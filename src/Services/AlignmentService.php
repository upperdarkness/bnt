<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Applies alignment changes (always logged), manages Wanted status,
 * Federation fines and the daily drift.
 */
class AlignmentService
{
    public function __construct(
        private Database $db,
        private AlignmentRules $rules,
        private BountyService $bounties,
        private array $config
    ) {}

    public function rules(): AlignmentRules
    {
        return $this->rules;
    }

    public function enabled(): bool
    {
        return (bool)($this->config['alignment']['enabled'] ?? true);
    }

    /**
     * Change a ship's alignment by $delta (clamped to the configured range) and write the log row.
     * Returns the new value, or null when the system is disabled / nothing changed.
     */
    public function apply(int $shipId, int $delta, string $reason, ?int $relatedShipId = null): ?int
    {
        if (!$this->enabled() || $delta === 0) {
            return null;
        }
        $min = (int)$this->config['alignment']['min'];
        $max = (int)$this->config['alignment']['max'];
        $row = $this->db->fetchOne(
            'WITH old AS (SELECT alignment FROM ships WHERE ship_id = :id FOR UPDATE)
             UPDATE ships SET alignment = LEAST(:max, GREATEST(:min, ships.alignment + :delta))
             FROM old WHERE ships.ship_id = :id2
             RETURNING ships.alignment AS new_value, old.alignment AS old_value',
            ['id' => $shipId, 'id2' => $shipId, 'delta' => $delta, 'min' => $min, 'max' => $max]
        );
        if (!$row) {
            return null;
        }
        $applied = (int)$row['new_value'] - (int)$row['old_value'];
        if ($applied === 0) {
            return (int)$row['new_value'];
        }
        $this->db->execute(
            'INSERT INTO alignment_log (ship_id, delta, new_value, reason, related_ship_id)
             VALUES (:ship, :delta, :new, :reason, :related)',
            ['ship' => $shipId, 'delta' => $applied, 'new' => (int)$row['new_value'],
             'reason' => $reason, 'related' => $relatedShipId]
        );
        $new = (int)$row['new_value'];
        if ($this->rules->isPirate($new)) {
            $this->ensurePirateWanted($shipId, $new);
        }
        return $new;
    }

    public function isWanted(array $ship): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if ($this->rules->isPirate((int)($ship['alignment'] ?? 0))) {
            return true;
        }
        if (empty($ship['wanted_until'])) {
            return false;
        }
        return strtotime((string)$ship['wanted_until']) > time();
    }

    /**
     * Set (or extend) Wanted status after a FedSpace offence.
     * Publishes news and refreshes the Federation bounty when first set.
     */
    public function setWanted(int $shipId, string $why = 'fedspace_offence', ?int $sector = null): void
    {
        if (!$this->enabled()) {
            return;
        }
        $days = (int)$this->rules->config('wanted_days', 7);
        $row = $this->db->fetchOne(
            "WITH old AS (SELECT wanted_until, character_name FROM ships WHERE ship_id = :id FOR UPDATE)
             UPDATE ships SET wanted_until = now() + make_interval(days => :days),
                    last_known_sector = COALESCE(:sector, ships.sector), last_known_at = now()
             FROM old WHERE ships.ship_id = :id2
             RETURNING (old.wanted_until IS NULL OR old.wanted_until < now()) AS newly_wanted,
                       ships.alignment, ships.character_name",
            ['id' => $shipId, 'id2' => $shipId, 'days' => $days, 'sector' => $sector]
        );
        if (!$row) {
            return;
        }
        $this->bounties->setFederationBounty($shipId, (int)$row['alignment']);
        if ($row['newly_wanted']) {
            $this->db->execute(
                "INSERT INTO news (headline, newstext, user_id, news_type)
                 VALUES (:h, :t, NULL, 'wanted')",
                ['h' => 'Federation declares ship Wanted',
                 't' => $row['character_name'] . ' is now wanted by the Federation. Reason: ' . str_replace('_', ' ', $why) . '.']
            );
        }
    }

    /** Pirates are Wanted automatically; keep wanted_until and the bounty alive. */
    private function ensurePirateWanted(int $shipId, int $alignment): void
    {
        $this->setWanted($shipId, 'pirate_status');
    }

    /** Refresh a Wanted ship's last known position (scan sighting or police contact). */
    public function noteSighting(int $shipId, int $sector): void
    {
        $this->db->execute(
            'UPDATE ships SET last_known_sector = :s, last_known_at = now()
             WHERE ship_id = :id AND (wanted_until > now() OR alignment <= :pirate)',
            ['s' => $sector, 'id' => $shipId, 'pirate' => (int)$this->config['alignment']['tiers']['outlaw'] - 1]
        );
    }

    /** Record sightings for every Wanted ship in a list (rows need ship_id, alignment, wanted_until). */
    public function noteSightings(array $ships, int $sector): void
    {
        foreach ($ships as $s) {
            if (isset($s['ship_id']) && $this->isWanted($s + ['alignment' => 0])) {
                $this->noteSighting((int)$s['ship_id'], $sector);
            }
        }
    }

    /**
     * Alignment gain from legitimate port trading: +1 per 10,000 credits,
     * capped at +20 per real day. Remainders carry between trades.
     */
    public function recordTrade(int $shipId, int $credits): void
    {
        if (!$this->enabled() || $credits <= 0) {
            return;
        }
        $per = (int)$this->rules->config('trade_credits_per_point', 10000);
        $cap = (int)$this->rules->config('trade_daily_cap', 20);
        $row = $this->db->fetchOne(
            'UPDATE ships SET trade_credit_accum = trade_credit_accum + :c WHERE ship_id = :id
             RETURNING trade_credit_accum',
            ['c' => $credits, 'id' => $shipId]
        );
        if (!$row) {
            return;
        }
        $points = intdiv((int)$row['trade_credit_accum'], $per);
        if ($points <= 0) {
            return;
        }
        $earned = (int)$this->db->fetchOne(
            "SELECT COALESCE(SUM(delta), 0) AS s FROM alignment_log
             WHERE ship_id = :id AND reason = 'port_trading' AND created_at >= date_trunc('day', now())",
            ['id' => $shipId]
        )['s'];
        $grant = max(0, min($points, $cap - $earned));
        // Consume the carried credits whether or not the daily cap let them count.
        $this->db->execute(
            'UPDATE ships SET trade_credit_accum = trade_credit_accum - :used WHERE ship_id = :id',
            ['used' => $points * $per, 'id' => $shipId]
        );
        if ($grant > 0) {
            $this->apply($shipId, $grant, 'port_trading');
        }
    }

    /**
     * What other players may see of a ship: tier (never the number), Wanted badge,
     * and NPC faction (never whether an LLM drives it).
     */
    public function publicView(array $row): array
    {
        $alignment = (int)($row['alignment'] ?? 0);
        $out = $row;
        unset($out['alignment'], $out['wanted_until'], $out['is_npc'], $out['faction']);
        $out['alignment_tier'] = $this->enabled() ? $this->rules->label($alignment) : null;
        $out['wanted'] = $this->isWanted($row);
        if (!empty($row['is_npc'])) {
            $out['npc'] = true;
            $out['faction'] = $this->config['npc']['factions'][$row['faction'] ?? '']['label'] ?? null;
        }
        return $out;
    }

    // ---------------------------------------------------------------- combat hooks

    /** Look up the fields the rules need for a ship (alignment, npc flag, faction, team). */
    public function profile(int $shipId): ?array
    {
        return $this->db->fetchOne(
            'SELECT s.ship_id, s.alignment, s.is_npc, s.team, s.wanted_until, s.character_name, p.faction
             FROM ships s LEFT JOIN npc_profiles p ON p.ship_id = s.ship_id WHERE s.ship_id = :id',
            ['id' => $shipId]
        );
    }

    /**
     * Apply the alignment consequences of an attack by one ship on another.
     * $victimBefore must be the victim's profile from BEFORE the fight.
     */
    public function recordShipAttack(array $attacker, array $victimBefore, bool $destroyed, bool $inFedSpace, bool $fedspaceOffence): void
    {
        if (!$this->enabled()) {
            return;
        }
        $attackerId = (int)$attacker['ship_id'];
        $victimId = (int)$victimBefore['ship_id'];
        foreach ($this->rules->attackDeltas($attacker, $victimBefore, $destroyed, $inFedSpace) as [$delta, $reason]) {
            $this->apply($attackerId, $delta, $reason, $victimId);
        }
        if ($fedspaceOffence) {
            $this->fedspaceOffence($attacker, $victimId);
        }
    }

    /** A sector defence owned by $ownerId destroyed a ship whose owner was $victimBefore. */
    public function recordDefenceKill(int $ownerId, array $victimBefore): void
    {
        if (!$this->enabled() || !$this->rules->isLawAbiding((int)$victimBefore['alignment'])) {
            return;
        }
        if ($ownerId === (int)$victimBefore['ship_id']) {
            return;
        }
        $owner = $this->db->fetchOne('SELECT team FROM ships WHERE ship_id = :id', ['id' => $ownerId]);
        if ($owner && (int)$owner['team'] !== 0 && (int)$owner['team'] === (int)$victimBefore['team']) {
            return;
        }
        $this->apply($ownerId, $this->rules->delta('attack_lawful'), 'defence_attacked_lawful', (int)$victimBefore['ship_id']);
        $this->apply($ownerId, $this->rules->delta('destroy_lawful'), 'defence_destroyed_lawful', (int)$victimBefore['ship_id']);
    }

    /** Penalty and Wanted status for a hostile act inside FedSpace by an Outlaw/Pirate. */
    public function fedspaceOffence(array $attacker, ?int $relatedShipId = null): void
    {
        if (!$this->enabled()) {
            return;
        }
        $this->apply((int)$attacker['ship_id'], $this->rules->delta('fedspace_hostile'), 'fedspace_hostile_action', $relatedShipId);
        $this->setWanted((int)$attacker['ship_id'], 'hostile action in FedSpace', (int)($attacker['sector'] ?? 0) ?: null);
    }

    public function recordPlanetCapture(array $captor, ?int $previousOwnerId, bool $inFedSpace, bool $fedspaceOffence): void
    {
        if (!$this->enabled()) {
            return;
        }
        $owner = ($previousOwnerId !== null && $previousOwnerId > 0) ? $this->profile($previousOwnerId) : null;
        $change = $this->rules->captureDelta($owner, $captor);
        if ($change !== null) {
            $this->apply((int)$captor['ship_id'], $change[0], $change[1], $owner ? (int)$owner['ship_id'] : null);
        }
        if ($fedspaceOffence) {
            $this->fedspaceOffence($captor, $owner ? (int)$owner['ship_id'] : null);
        }
    }

    // ---------------------------------------------------------------- fines

    /**
     * Pay a Federation fine at a starbase: restores alignment to the floor and clears Wanted.
     * Pirates cannot buy their way out.
     */
    public function payFine(int $shipId): array
    {
        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $ship = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id FOR UPDATE', ['id' => $shipId]);
            if (!$ship) {
                throw new \DomainException('Ship not found');
            }
            $sector = $this->db->fetchOne('SELECT is_starbase FROM universe WHERE sector_id = :s', ['s' => (int)$ship['sector']]);
            if (!$sector || !$sector['is_starbase']) {
                throw new \DomainException('Fines can only be paid at a starbase');
            }
            $alignment = (int)$ship['alignment'];
            if ($this->rules->isPirate($alignment)) {
                throw new \DomainException('The Federation will not accept a fine from a Pirate. Work your way out of the Pirate tier first.');
            }
            $wanted = !empty($ship['wanted_until']) && strtotime((string)$ship['wanted_until']) > time();
            $target = $this->rules->fineTarget($alignment);
            if ($alignment >= $target && !$wanted) {
                throw new \DomainException('You have nothing to pay: you are not wanted and your record is clean enough');
            }
            $fine = $this->rules->fineAmount($alignment);
            if ((int)$ship['credits'] < $fine) {
                throw new \DomainException('Fine is ' . number_format($fine) . ' credits; you cannot afford it');
            }
            $this->db->execute('UPDATE ships SET credits = credits - :f, wanted_until = NULL WHERE ship_id = :id', ['f' => $fine, 'id' => $shipId]);
            $this->bounties->clearFederationBounty($shipId);
            $this->apply($shipId, $target - $alignment, 'federation_fine');
            if ($own) {
                $pdo->commit();
            }
            return ['success' => true, 'fine' => $fine, 'alignment' => $target];
        } catch (\DomainException $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function quoteFine(array $ship): array
    {
        $alignment = (int)$ship['alignment'];
        return [
            'fine' => $this->rules->fineAmount($alignment),
            'restores_to' => $this->rules->fineTarget($alignment),
            'pirate_blocked' => $this->rules->isPirate($alignment),
            'applicable' => $alignment < $this->rules->fineTarget($alignment)
                || (!empty($ship['wanted_until']) && strtotime((string)$ship['wanted_until']) > time()),
        ];
    }

    // ---------------------------------------------------------------- scheduler

    /**
     * Daily drift toward 0 and Wanted expiry.
     * @return array{drifted: int, expired: int, renewed: int}
     */
    public function dailyDrift(): array
    {
        $pct = (float)$this->rules->config('drift_pct_per_day', 1);
        $includeNpcs = (bool)$this->rules->config('drift_npcs', false);
        $drifted = $this->db->query(
            "WITH c AS (
                SELECT ship_id, alignment AS old_value,
                       (CASE WHEN alignment > 0 THEN -1 ELSE 1 END)
                       * LEAST(ABS(alignment), GREATEST(1, ROUND(ABS(alignment) * CAST(:pct AS NUMERIC) / 100.0)::int)) AS d
                FROM ships WHERE alignment <> 0 AND (is_npc = FALSE OR :npcs) FOR UPDATE
             ), u AS (
                UPDATE ships s SET alignment = c.old_value + c.d FROM c
                WHERE s.ship_id = c.ship_id RETURNING s.ship_id, c.d, s.alignment
             )
             INSERT INTO alignment_log (ship_id, delta, new_value, reason)
             SELECT ship_id, d, alignment, 'daily_drift' FROM u",
            ['pct' => $pct, 'npcs' => $includeNpcs]
        )->rowCount();

        $pirateMax = (int)$this->config['alignment']['tiers']['outlaw'] - 1;
        // Wanted that has run out and whose owner is no longer a Pirate.
        $expiredIds = array_column($this->db->fetchAll(
            'SELECT ship_id FROM ships WHERE wanted_until IS NOT NULL AND wanted_until < now() AND alignment > :p',
            ['p' => $pirateMax]
        ), 'ship_id');
        foreach ($expiredIds as $id) {
            $this->db->execute('UPDATE ships SET wanted_until = NULL WHERE ship_id = :id', ['id' => (int)$id]);
            $this->bounties->clearFederationBounty((int)$id);
        }
        // Pirates stay Wanted: renew and resize the bounty.
        $renewed = 0;
        foreach ($this->db->fetchAll('SELECT ship_id, alignment FROM ships WHERE alignment <= :p AND ship_destroyed = FALSE', ['p' => $pirateMax]) as $row) {
            $this->db->execute(
                'UPDATE ships SET wanted_until = now() + make_interval(days => :d) WHERE ship_id = :id',
                ['d' => (int)$this->rules->config('wanted_days', 7), 'id' => (int)$row['ship_id']]
            );
            $this->bounties->setFederationBounty((int)$row['ship_id'], (int)$row['alignment']);
            $renewed++;
        }
        return ['drifted' => $drifted, 'expired' => count($expiredIds), 'renewed' => $renewed];
    }
}
