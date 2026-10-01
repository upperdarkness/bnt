<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Models\AttackLog;
use BNT\Models\Combat;
use BNT\Models\Planet;
use BNT\Models\Ship;
use BNT\Models\ShipType;
use BNT\Models\Skill;
use BNT\Models\Universe;

/**
 * Ship, planet and defence combat. The single entry point for every attack:
 * web UI, REST API and NPCs all come through here, so starbase and FedSpace
 * rules cannot be bypassed.
 *
 * Every method returns:
 *   success  bool   - the action was carried out (turn spent) or accepted
 *   flash    string - 'message' or 'error' (for the web UI)
 *   text     string - human-readable outcome
 *   code     ?string- stable machine-readable error code when success is false
 *   data     array  - structured outcome for the API
 */
class CombatService
{
    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private Universe $universe,
        private Planet $planetModel,
        private Combat $combat,
        private AttackLog $attackLog,
        private Skill $skills,
        private AlignmentService $alignment,
        private SectorRules $sectors,
        private NpcEvents $events,
        private array $config,
        private ?NpcMonitor $monitor = null,
        private ?ContrabandService $contraband = null
    ) {}

    // ------------------------------------------------------------------ ships

    public function attackShip(array $ship, int $targetId): array
    {
        $sectorId = (int)$ship['sector'];
        $flags = $this->sectors->flags($sectorId);
        if ($flags['starbase']) {
            return $this->refuse(AlignmentRules::SAFE_ZONE_MESSAGE, 'STARBASE_NO_COMBAT');
        }

        $target = $this->shipModel->find($targetId);
        if (!$target || $target['ship_destroyed'] || $target['on_planet']) {
            return $this->refuse('Target not found', 'TARGET_NOT_FOUND');
        }
        if ((int)$target['sector'] !== $sectorId) {
            return $this->refuse('Target is not in your sector', 'TARGET_NOT_IN_SECTOR');
        }
        if ((int)$target['ship_id'] === (int)$ship['ship_id']) {
            return $this->refuse('You cannot attack yourself', 'INVALID_TARGET');
        }
        if ((int)$ship['team'] !== 0 && (int)$target['team'] === (int)$ship['team']) {
            return $this->refuse('You cannot attack your team members', 'TEAM_MEMBER');
        }
        if ((int)$ship['turns'] < 1) {
            return $this->refuse('Not enough turns', 'INSUFFICIENT_TURNS');
        }

        $attackerProfile = $this->alignment->profile((int)$ship['ship_id']) + ['sector' => $sectorId];
        $targetProfile = $this->alignment->profile($targetId);
        $inFed = $this->alignment->enabled() && $flags['federation'];
        $decision = $this->alignment->rules()->combatDecision(
            ['alignment' => (int)$ship['alignment'],
             'is_police' => !empty($attackerProfile['is_npc']) && ($attackerProfile['faction'] ?? null) === 'police'],
            ['alignment' => (int)$target['alignment'], 'wanted' => $this->alignment->isWanted($target)],
            false,
            $inFed
        );
        if (!$decision['allowed']) {
            return $this->refuse($decision['message'], 'FEDSPACE_PROTECTED');
        }
        $offence = $decision['fedspace_offence'];
        if (!empty($attackerProfile['is_npc']) && !empty($targetProfile['is_npc'])
            && ($attackerProfile['faction'] ?? null) === ($targetProfile['faction'] ?? null)) {
            // NPCs never fight their own faction: refuse and raise an anomaly alert for the admins.
            $this->monitor?->friendlyFire($attackerProfile, $targetProfile);
            return $this->refuse('You cannot attack a member of your own faction', 'FACTION_LOYALTY');
        }

        $skills = $this->skills->getSkills((int)$ship['ship_id']);
        $combatSkillMultiplier = $this->skills->getCombatMultiplier($skills['combat']);
        $shipTypeCombatMultiplier = ShipType::getCombatMultiplier($ship['ship_type']);

        $result = $this->combat->shipVsShip($ship, $target);
        $base = [
            'target_id' => $targetId,
            'attacker_alignment_tier' => $this->alignment->rules()->label((int)$ship['alignment']),
        ];

        // Emergency Warp Drive: defender jumps away
        if (!$result['escaped'] && ($target['dev_emerwarp'] ?? 0) > 0) {
            $randomSector = $this->universe->getRandomSector((int)$target['sector']);
            if ($randomSector) {
                $this->shipModel->update($targetId, [
                    'sector' => $randomSector,
                    'dev_emerwarp' => max(0, ($target['dev_emerwarp'] ?? 0) - 1),
                ]);
                $this->db->execute(
                    'INSERT INTO logs (ship_id, log_type, log_data, logged_at) VALUES (:ship_id, 100, :log_data, NOW())',
                    ['ship_id' => $targetId, 'log_data' => json_encode([
                        'action' => 'emergency_warp_activated',
                        'from_sector' => (int)$target['sector'],
                        'to_sector' => $randomSector,
                        'attacker_id' => (int)$ship['ship_id'],
                        'attacker_name' => $ship['character_name'],
                        'reason' => 'Attacked by another player',
                    ])]
                );
                $this->attackLog->logAttack((int)$ship['ship_id'], $ship['character_name'], $targetId,
                    $target['character_name'], 'ship', 'escaped', 0, $sectorId);
                $this->alignment->recordShipAttack($attackerProfile, $targetProfile, false, $inFed, $offence);
                $this->events->queue($targetId, 'attacked', ['by' => (int)$ship['ship_id'], 'by_name' => $ship['character_name'],
                    'hull_lost_pct' => 0, 'sector' => $sectorId]);
                return $this->ok("Target activated Emergency Warp Drive and escaped to Sector $randomSector!", 'message',
                    $base + ['outcome' => 'escaped']);
            }
        }

        // Skill and ship-type multipliers on damage dealt
        $total = $combatSkillMultiplier * $shipTypeCombatMultiplier;
        if ($total != 1.0 && $result['defender_damage'] > 0) {
            $result['defender_damage'] = (int)($result['defender_damage'] * $total);
            if ($result['defender_damage'] >= $target['armor']) {
                $result['defender_destroyed'] = true;
            }
        }
        $defenseMultiplier = ShipType::getDefenseMultiplier($ship['ship_type']);
        if ($defenseMultiplier != 1.0 && $result['attacker_damage'] > 0) {
            $result['attacker_damage'] = (int)($result['attacker_damage'] / $defenseMultiplier);
        }

        $this->shipModel->useTurns((int)$ship['ship_id'], 1);

        if ($result['escaped']) {
            $this->attackLog->logAttack((int)$ship['ship_id'], $ship['character_name'], $targetId,
                $target['character_name'], 'ship', 'escaped', 0, $sectorId);
            $this->alignment->recordShipAttack($attackerProfile, $targetProfile, false, $inFed, $offence);
            $this->events->queue($targetId, 'attacked', ['by' => (int)$ship['ship_id'], 'by_name' => $ship['character_name'],
                'hull_lost_pct' => 0, 'sector' => $sectorId]);
            return $this->ok($result['message'], 'error', $base + ['outcome' => 'escaped']);
        }

        if ($result['torpedos_used'] > 0) {
            $this->shipModel->update((int)$ship['ship_id'], ['torps' => $ship['torps'] - $result['torpedos_used']]);
        }
        $this->shipModel->update((int)$ship['ship_id'], ['ship_fighters' => $ship['ship_fighters'] - $result['fighters_lost_attacker']]);
        $this->shipModel->update($targetId, ['ship_fighters' => max(0, $target['ship_fighters'] - $result['fighters_lost_defender'])]);
        if ($result['attacker_damage'] > 0) {
            $this->combat->applyDamageToShip((int)$ship['ship_id'], $result['attacker_damage']);
        }

        $hullPct = (int)min(100, round(100 * $result['defender_damage'] / max(1, (int)$target['armor_pts'])));

        if ($result['defender_destroyed']) {
            $this->combat->destroyShip($targetId);
            $credits = $this->combat->awardKillCredits((int)$ship['ship_id'], $target);
            $bounty = $this->combat->collectBounty((int)$ship['ship_id'], $targetId);
            $looted = $this->contraband?->lootOnKill((int)$ship['ship_id'], $targetId) ?? 0;
            $text = "Target destroyed! You earned $credits credits";
            if ($bounty > 0) {
                $text .= " + $bounty bounty";
            }
            $text .= ' = ' . ($credits + $bounty) . ' total!';
            if ($looted > 0) {
                $text .= " You salvaged $looted " . $this->contraband->name() . '.';
            }
            $this->attackLog->logAttack((int)$ship['ship_id'], $ship['character_name'], $targetId,
                $target['character_name'], 'ship', 'destroyed', $result['defender_damage'], $sectorId);
            $this->skills->awardSkillPoints((int)$ship['ship_id'], min(5, max(3, (int)floor(($target['rating'] ?? 0) / 20))));
            $this->alignment->recordShipAttack($attackerProfile, $targetProfile, true, $inFed, $offence);
            $this->events->queue($targetId, 'attacked', ['by' => (int)$ship['ship_id'], 'by_name' => $ship['character_name'],
                'hull_lost_pct' => 100, 'sector' => $sectorId, 'destroyed' => true]);
            return $this->ok($text, 'message', $base + ['outcome' => 'destroyed', 'credits' => $credits, 'bounty' => $bounty,
                'contraband_looted' => $looted, 'damage' => $result['defender_damage']]);
        }

        if ($result['defender_damage'] > 0) {
            $this->combat->applyDamageToShip($targetId, $result['defender_damage']);
        }
        $this->attackLog->logAttack((int)$ship['ship_id'], $ship['character_name'], $targetId,
            $target['character_name'], 'ship', 'success', $result['defender_damage'], $sectorId);
        $this->alignment->recordShipAttack($attackerProfile, $targetProfile, false, $inFed, $offence);
        $this->events->queue($targetId, 'attacked', ['by' => (int)$ship['ship_id'], 'by_name' => $ship['character_name'],
            'hull_lost_pct' => $hullPct, 'sector' => $sectorId]);
        return $this->ok($result['message'] . " Damage dealt: {$result['defender_damage']}", 'message',
            $base + ['outcome' => 'damaged', 'damage' => $result['defender_damage']]);
    }

    // ---------------------------------------------------------------- planets

    public function attackPlanet(array $ship, int $planetId): array
    {
        $sectorId = (int)$ship['sector'];
        $flags = $this->sectors->flags($sectorId);
        if ($flags['starbase']) {
            return $this->refuse(AlignmentRules::SAFE_ZONE_MESSAGE, 'STARBASE_NO_COMBAT');
        }
        $planet = $this->planetModel->find($planetId);
        if (!$planet) {
            return $this->refuse('Planet not found', 'PLANET_NOT_FOUND');
        }
        if ((int)$planet['sector_id'] !== $sectorId) {
            return $this->refuse('Planet is not in your sector', 'PLANET_NOT_IN_SECTOR');
        }
        if ((int)$planet['owner'] === (int)$ship['ship_id']) {
            return $this->refuse('You cannot attack your own planet', 'INVALID_TARGET');
        }
        if ((int)$ship['turns'] < 5) {
            return $this->refuse('Need at least 5 turns to attack a planet', 'INSUFFICIENT_TURNS');
        }

        $ownerId = $planet['owner'] !== null ? (int)$planet['owner'] : 0;
        $ownerProfile = $ownerId > 0 ? $this->alignment->profile($ownerId) : null;
        if ($ownerProfile && (int)$ship['team'] !== 0 && (int)$ownerProfile['team'] === (int)$ship['team']) {
            return $this->refuse('You cannot attack your team members', 'TEAM_MEMBER');
        }
        $attackerProfile = $this->alignment->profile((int)$ship['ship_id']) + ['sector' => $sectorId];
        $inFed = $this->alignment->enabled() && $flags['federation'];
        $decision = $this->alignment->rules()->combatDecision(
            ['alignment' => (int)$ship['alignment'],
             'is_police' => !empty($attackerProfile['is_npc']) && ($attackerProfile['faction'] ?? null) === 'police'],
            $ownerProfile ? ['alignment' => (int)$ownerProfile['alignment'], 'wanted' => $this->alignment->isWanted($ownerProfile)] : null,
            false,
            $inFed
        );
        if (!$decision['allowed']) {
            return $this->refuse($decision['message'], 'FEDSPACE_PROTECTED');
        }
        $offence = $decision['fedspace_offence'];

        $skills = $this->skills->getSkills((int)$ship['ship_id']);
        $total = $this->skills->getCombatMultiplier($skills['combat']) * ShipType::getCombatMultiplier($ship['ship_type']);

        $result = $this->combat->shipVsPlanet($ship, $planet);
        if ($total != 1.0 && isset($result['planet_damage'])) {
            $result['planet_damage'] = (int)($result['planet_damage'] * $total);
        }
        $defenseMultiplier = ShipType::getDefenseMultiplier($ship['ship_type']);
        if ($defenseMultiplier != 1.0 && $result['ship_damage'] > 0) {
            $result['ship_damage'] = (int)($result['ship_damage'] / $defenseMultiplier);
        }

        $this->shipModel->useTurns((int)$ship['ship_id'], 5);
        if ($result['torpedos_used'] > 0) {
            $this->shipModel->update((int)$ship['ship_id'], ['torps' => $ship['torps'] - $result['torpedos_used']]);
        }
        if ($result['fighters_lost_ship'] > 0) {
            $this->shipModel->update((int)$ship['ship_id'], ['ship_fighters' => $ship['ship_fighters'] - $result['fighters_lost_ship']]);
        }
        if ($result['fighters_lost_planet'] > 0) {
            $this->planetModel->update($planetId, ['fighters' => max(0, $planet['fighters'] - $result['fighters_lost_planet'])]);
        }
        if ($result['ship_damage'] > 0) {
            $this->combat->applyDamageToShip((int)$ship['ship_id'], $result['ship_damage']);
        }

        $captured = false;
        if ($result['planet_captured']) {
            $this->planetModel->capture($planetId, (int)$ship['ship_id']);
            $this->skills->awardSkillPoints((int)$ship['ship_id'], 3);
            $text = 'Planet captured!';
            $flash = 'message';
            $resultType = 'destroyed';
            $damage = $result['planet_damage'] ?? 0;
            $captured = true;
        } elseif ($result['success']) {
            if ($planet['base']) {
                $this->planetModel->update($planetId, ['base' => false]);
            }
            $text = $result['message'];
            $flash = 'message';
            $resultType = 'success';
            $damage = $result['planet_damage'] ?? 0;
        } else {
            $text = $result['message'];
            $flash = 'error';
            $resultType = 'failure';
            $damage = 0;
        }

        $this->attackLog->logAttack((int)$ship['ship_id'], $ship['character_name'], $ownerId > 0 ? $ownerId : null,
            $planet['planet_name'] ?? "Planet {$planetId}", 'planet', $resultType, $damage, $sectorId);

        if ($captured) {
            $this->alignment->recordPlanetCapture($attackerProfile, $ownerId > 0 ? $ownerId : null, $inFed, $offence);
            if ($ownerId > 0) {
                $this->events->queue($ownerId, 'planet_lost', ['planet_id' => $planetId, 'planet_name' => $planet['planet_name'],
                    'by' => (int)$ship['ship_id'], 'by_name' => $ship['character_name'], 'sector' => $sectorId]);
            }
        } elseif ($offence) {
            $this->alignment->fedspaceOffence($attackerProfile, $ownerId > 0 ? $ownerId : null);
        }
        if (!$captured && $ownerId > 0) {
            $this->events->queue($ownerId, 'planet_attacked', ['planet_id' => $planetId, 'planet_name' => $planet['planet_name'],
                'by' => (int)$ship['ship_id'], 'by_name' => $ship['character_name'], 'sector' => $sectorId]);
        }
        return $this->ok($text, $flash, ['outcome' => $resultType, 'captured' => $captured, 'damage' => $damage, 'planet_id' => $planetId]);
    }

    // -------------------------------------------------------------- defences

    /** @param string $type 'F' fighters or 'M' mines (mines use the torpedo stock, as in the web UI) */
    public function deployDefence(array $ship, string $type, int $quantity): array
    {
        $sectorId = (int)$ship['sector'];
        $flags = $this->sectors->flags($sectorId);
        if ($flags['starbase']) {
            return $this->refuse('Defenses cannot be deployed in starbase sectors', 'STARBASE_NO_DEFENCES');
        }
        if (!in_array($type, ['F', 'M'], true)) {
            return $this->refuse('Invalid defense type', 'INVALID_DEFENCE_TYPE');
        }
        if ($quantity <= 0) {
            return $this->refuse('Quantity must be positive', 'INVALID_QUANTITY');
        }
        if ($this->alignment->enabled() && $flags['federation']
            && !$this->alignment->rules()->mayDeployInFedSpace((int)$ship['alignment'])) {
            return $this->refuse('Sector defences cannot be deployed in Federation space by outlaws', 'FEDSPACE_NO_DEFENCES');
        }
        $name = $type === 'F' ? 'fighters' : 'mines';
        $column = $type === 'F' ? 'ship_fighters' : 'torps';
        if ((int)$ship[$column] < $quantity) {
            return $this->refuse("Not enough $name", 'INSUFFICIENT_STOCK');
        }

        $existing = $this->db->fetchOne(
            'SELECT * FROM sector_defence WHERE sector_id = :sector AND ship_id = :ship AND defence_type = :type',
            ['sector' => $sectorId, 'ship' => $ship['ship_id'], 'type' => $type]
        );
        if ($existing) {
            $this->db->execute('UPDATE sector_defence SET quantity = quantity + :qty WHERE defence_id = :id',
                ['qty' => $quantity, 'id' => $existing['defence_id']]);
        } else {
            $this->db->execute('INSERT INTO sector_defence (ship_id, sector_id, defence_type, quantity) VALUES (:ship, :sector, :type, :qty)',
                ['ship' => $ship['ship_id'], 'sector' => $sectorId, 'type' => $type, 'qty' => $quantity]);
        }
        $this->shipModel->update((int)$ship['ship_id'], [$column => $ship[$column] - $quantity]);

        $text = "Deployed $quantity $name in this sector";
        $dvd = $this->combat->defenseVsDefense($sectorId, (int)$ship['ship_id']);
        if ($dvd['combat_occurred']) {
            $text = "Deployed $quantity $name. " . $dvd['message'];
            $this->attackLog->logAttack((int)$ship['ship_id'], $ship['character_name'], null, null, 'defense',
                ($dvd['success'] ?? true) ? 'success' : 'failure', $dvd['damage_dealt'] ?? 0, $sectorId);
        }
        return $this->ok($text, 'message', ['deployed' => $quantity, 'type' => $type]);
    }

    // ---------------------------------------------------------------- helpers

    private function refuse(string $text, string $code): array
    {
        return ['success' => false, 'flash' => 'error', 'text' => $text, 'code' => $code, 'data' => []];
    }

    private function ok(string $text, string $flash, array $data): array
    {
        return ['success' => true, 'flash' => $flash, 'text' => $text, 'code' => null, 'data' => $data];
    }
}
