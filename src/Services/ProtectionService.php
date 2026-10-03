<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Models\Ship;

/**
 * Newbie protection: exit conditions, grace period, respawn shield and the anti-abuse rules.
 * The pure decisions live in ProtectionRules; this class applies them to the database.
 */
class ProtectionService
{
    public function __construct(
        private Database $db,
        private ProtectionRules $rules,
        private Ship $shipModel,
        private PressService $press,
        private array $config
    ) {}

    public function rules(): ProtectionRules
    {
        return $this->rules;
    }

    public function enabled(): bool
    {
        return $this->rules->enabled();
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config['protection'][$key] ?? $default;
    }

    public function isProtected(array $ship): bool
    {
        return $this->rules->isProtected($ship);
    }

    public function isProtectedId(int $shipId): bool
    {
        $row = $this->db->fetchOne('SELECT protection_state, respawn_shield_until, is_npc FROM ships WHERE ship_id = :id', ['id' => $shipId]);
        return $row !== null && $this->rules->isProtected($row);
    }

    // ------------------------------------------------------------------ threshold and progress

    /** Score threshold for leaving protection: a fraction of the active players' median (percentile) score. */
    public function threshold(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT score FROM ships
             WHERE is_npc = FALSE AND ship_destroyed = FALSE AND protection_state = 'none'
               AND last_login > now() - make_interval(days => :d)",
            ['d' => (int)$this->cfg('active_player_days', 7)]
        );
        $ref = $this->rules->percentile(array_map(static fn($r) => (int)$r['score'], $rows), (float)$this->cfg('score_percentile', 50));
        return $this->rules->scoreThreshold($ref);
    }

    public function progress(array $ship): array
    {
        return $this->rules->progress($ship, $this->threshold());
    }

    // ------------------------------------------------------------------ lifecycle

    /** Count today as an active day (called on login and main-screen loads for protected ships). */
    public function markActive(array $ship): void
    {
        if (($ship['protection_state'] ?? 'none') !== 'none') {
            $this->shipModel->markActive((int)$ship['ship_id']);
        }
    }

    /**
     * Protection ends because the exit threshold was reached. The ship enters the 12-hour grace period
     * (attackable, with a news item and a message warning the player).
     */
    public function endNaturally(array $ship, string $reason): void
    {
        $this->db->execute(
            "UPDATE ships SET protection_state = 'grace', protection_ended_at = now() WHERE ship_id = :id AND protection_state = 'protected'",
            ['id' => (int)$ship['ship_id']]
        );
        $why = $reason === 'days' ? 'enough active days played' : 'a strong enough score';
        $hours = (int)$this->cfg('grace_hours', 12);
        $this->db->execute(
            "INSERT INTO news (headline, newstext, user_id, news_type) VALUES (:h, :t, NULL, 'protection')",
            ['h' => 'Rookie leaves the nest', 't' => $ship['character_name'] . ' has outgrown newbie protection and can now be attacked.']
        );
        $this->press->notify((int)$ship['ship_id'], 'Your newbie protection has ended',
            "You have reached $why. Your ship and planets can now be attacked by other players. Look to your defences.\n\nNothing changes for $hours hours of this warning period, then the shield is gone for good.");
    }

    /** Voluntary exit (opt-out) or an aggressive act: protection is gone immediately, no warning needed. */
    public function endNow(int $shipId): void
    {
        $this->db->execute(
            "UPDATE ships SET protection_state = 'none', protection_ended_at = now(), respawn_shield_until = NULL WHERE ship_id = :id",
            ['id' => $shipId]
        );
    }

    public function optOut(int $shipId): bool
    {
        if (!$this->isProtectedId($shipId)) {
            return false;
        }
        $this->endNow($shipId);
        return true;
    }

    /** Attacking a ship or planet, or deploying sector defences, ends protection (and the respawn shield) at once. */
    public function onAggressiveAction(array $ship): void
    {
        if ($this->rules->isProtected($ship)) {
            $this->endNow((int)$ship['ship_id']);
        }
    }

    /** A rebuilt escape-pod ship gets a respawn shield, once per cooldown. */
    public function grantRespawnShield(int $shipId): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        return $this->shipModel->grantRespawnShield($shipId, (int)$this->cfg('respawn_shield_hours', 24), (int)$this->cfg('respawn_shield_cooldown_days', 7));
    }

    /**
     * Scheduler task: end protection for ships that reached an exit condition and expire finished grace periods.
     * @return array{ended: int, graces_over: int}
     */
    public function tick(): array
    {
        if (!$this->enabled()) {
            return ['ended' => 0, 'graces_over' => 0];
        }
        $threshold = $this->threshold();
        $ended = 0;
        $over = 0;
        foreach ($this->db->fetchAll("SELECT * FROM ships WHERE protection_state IN ('protected', 'grace') AND ship_destroyed = FALSE") as $ship) {
            if ($ship['protection_state'] === 'grace') {
                if ($this->rules->graceOver($ship)) {
                    $this->db->execute("UPDATE ships SET protection_state = 'none' WHERE ship_id = :id", ['id' => (int)$ship['ship_id']]);
                    $over++;
                }
                continue;
            }
            $ship['score'] = $this->shipModel->calculateScore((int)$ship['ship_id']);
            if ($reason = $this->rules->exitReason($ship, $threshold)) {
                $this->endNaturally($ship, $reason);
                $ended++;
            }
        }
        return ['ended' => $ended, 'graces_over' => $over];
    }

    // ------------------------------------------------------------------ what protection covers

    /** The planets protection covers for an owner: the oldest N (so protection cannot shelter an empire). */
    public function coveredPlanetIds(int $ownerId): array
    {
        return array_map(static fn($r) => (int)$r['planet_id'], $this->db->fetchAll(
            'SELECT planet_id FROM planets WHERE owner = :o ORDER BY planet_id LIMIT ' . (int)$this->cfg('max_planets', 3),
            ['o' => $ownerId]
        ));
    }

    /** Is this planet currently protected from attack? */
    public function isPlanetProtected(array $planet): bool
    {
        if (empty($planet['owner']) || !$this->isProtectedId((int)$planet['owner'])) {
            return false;
        }
        return in_array((int)$planet['planet_id'], $this->coveredPlanetIds((int)$planet['owner']), true);
    }

    /** Does another player's defence (not a team-mate's) sit in this sector? */
    public function hostileDefencesIn(int $sectorId, array $ship): bool
    {
        return (bool)$this->db->fetchOne(
            'SELECT 1 AS x FROM sector_defence sd JOIN ships s ON s.ship_id = sd.ship_id
             WHERE sd.sector_id = :sector AND sd.quantity > 0 AND sd.ship_id <> :me AND (:team = 0 OR s.team <> :team2) LIMIT 1',
            ['sector' => $sectorId, 'me' => (int)$ship['ship_id'], 'team' => (int)$ship['team'], 'team2' => (int)$ship['team']]
        );
    }

    // ------------------------------------------------------------------ anti-abuse

    /**
     * IGB transfers between a protected and a non-protected account are capped per real day, either way.
     * @return array{allowed: bool, remaining?: int, error?: string}
     */
    public function creditTransferAllowed(int $fromId, int $toId, int $amount): array
    {
        if (!$this->enabled()) {
            return ['allowed' => true];
        }
        $from = $this->db->fetchOne('SELECT protection_state, respawn_shield_until, is_npc FROM ships WHERE ship_id = :id', ['id' => $fromId]);
        $to = $this->db->fetchOne('SELECT protection_state, respawn_shield_until, is_npc FROM ships WHERE ship_id = :id', ['id' => $toId]);
        if (!$from || !$to) {
            return ['allowed' => true];
        }
        $pf = $this->rules->isProtected($from);
        $pt = $this->rules->isProtected($to);
        if ($pf === $pt) {
            return ['allowed' => true];
        }
        $protectedId = $pf ? $fromId : $toId;
        $prot = static fn(string $a) => "($a.protection_state = 'protected' OR COALESCE($a.respawn_shield_until > now(), FALSE))";
        $used = (int)$this->db->fetchOne(
            "SELECT COALESCE(SUM(t.amount), 0) AS s FROM igb_transfers t
             JOIN ships f ON f.ship_id = t.from_ship JOIN ships r ON r.ship_id = t.to_ship
             WHERE t.transfer_time >= date_trunc('day', now())
               AND ((t.from_ship = :p AND NOT {$prot('r')}) OR (t.to_ship = :p2 AND NOT {$prot('f')}))",
            ['p' => $protectedId, 'p2' => $protectedId]
        )['s'];
        $cap = (int)$this->cfg('transfer_cap', 50000);
        $remaining = max(0, $cap - $used);
        if ($amount > $remaining) {
            return ['allowed' => false, 'remaining' => $remaining,
                'error' => 'Transfers between protected and non-protected accounts are capped at ' . number_format($cap) . ' credits per day (' . number_format($remaining) . ' left today)'];
        }
        return ['allowed' => true, 'remaining' => $remaining - $amount];
    }

    /** A team may hold at most N protected members at once. */
    public function teamAllowsProtected(int $teamId, array $joiner): bool
    {
        if (!$this->enabled() || !$this->rules->isProtected($joiner)) {
            return true;
        }
        $members = $this->db->fetchAll(
            'SELECT protection_state, respawn_shield_until, is_npc FROM ships WHERE team = :t AND ship_id <> :me AND ship_destroyed = FALSE',
            ['t' => $teamId, 'me' => (int)$joiner['ship_id']]
        );
        $protected = count(array_filter($members, fn($m) => $this->rules->isProtected($m)));
        return $protected < (int)$this->cfg('max_team_protected', 2);
    }

    /**
     * Admin multi-account signals: accounts that share a signup IP or device with another account,
     * where at least one of them is (or was recently) protected. No automatic action is taken.
     * @return array<int,array{kind: string, value: string, ships: array}>
     */
    public function multiAccountFlags(): array
    {
        $out = [];
        foreach (['signup_ip' => 'ip', 'signup_device' => 'device'] as $col => $kind) {
            $groups = $this->db->fetchAll(
                "SELECT $col AS v, COUNT(*) AS n FROM ships WHERE $col IS NOT NULL AND $col <> '' AND is_npc = FALSE
                 GROUP BY $col HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC LIMIT 100"
            );
            foreach ($groups as $g) {
                $ships = $this->db->fetchAll(
                    "SELECT ship_id, character_name, protection_state, score, created_at FROM ships WHERE $col = :v ORDER BY created_at",
                    ['v' => $g['v']]
                );
                $relevant = array_filter($ships, static fn($s) => $s['protection_state'] !== 'none' || strtotime((string)$s['created_at']) > time() - 30 * 86400);
                if ($relevant) {
                    $out[] = ['kind' => $kind, 'value' => (string)$g['v'], 'ships' => $ships];
                }
            }
        }
        return $out;
    }

    /** Admin: grant (restart) protection with an audit trail kept by the caller. */
    public function grant(int $shipId): void
    {
        $this->db->execute("UPDATE ships SET protection_state = 'protected', protection_ended_at = NULL WHERE ship_id = :id AND is_npc = FALSE", ['id' => $shipId]);
    }
}
