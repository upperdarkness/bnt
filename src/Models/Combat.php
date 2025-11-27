<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class Combat
{
    private const TORP_DAMAGE_RATE = 10;
    private const FIGHTER_PRICE = 50;
    private const TORPEDO_PRICE = 25;
    private const LEVEL_FACTOR = 1.5;

    public function __construct(private Database $db) {}

    /**
     * Calculate if attack is successful based on engines and cloak
     * Now includes ship type speed bonuses
     */
    public function canEscape(array $attacker, array $defender): bool
    {
        // Apply ship type speed bonuses if ship_type is present
        $attackerEngines = $attacker['engines'];
        $defenderEngines = $defender['engines'];

        if (isset($attacker['ship_type'])) {
            $attackerSpeedBonus = ShipType::getSpeedBonus($attacker['ship_type']);
            $attackerEngines = (int)($attackerEngines * $attackerSpeedBonus);
        }

        if (isset($defender['ship_type'])) {
            $defenderSpeedBonus = ShipType::getSpeedBonus($defender['ship_type']);
            $defenderEngines = (int)($defenderEngines * $defenderSpeedBonus);
        }

        // Defender can escape if they have better engines (with speed bonus applied)
        if ($defenderEngines > $attackerEngines) {
            return true;
        }

        // Defender can escape if cloaked well enough
        if ($defender['cloak'] > $attacker['sensors']) {
            return true;
        }

        return false;
    }

    /**
     * Calculate beam damage
     */
    public function calculateBeamDamage(int $beamLevel): int
    {
        return (int)round(pow(self::LEVEL_FACTOR, $beamLevel) * 100);
    }

    /**
     * Calculate torpedo damage
     */
    public function calculateTorpedoDamage(int $numTorps): int
    {
        return $numTorps * self::TORP_DAMAGE_RATE;
    }

    /**
     * Calculate fighter damage
     */
    public function calculateFighterDamage(int $numFighters): int
    {
        return (int)($numFighters * 2);
    }

    /**
     * Calculate shield strength
     */
    public function calculateShieldStrength(int $shieldLevel): int
    {
        return (int)round(pow(self::LEVEL_FACTOR, $shieldLevel) * 100);
    }

    /**
     * Calculate armor strength
     */
    public function calculateArmorStrength(int $armorLevel, int $armorPts): int
    {
        $maxArmor = (int)round(pow(self::LEVEL_FACTOR, $armorLevel) * 100);
        return min($armorPts, $maxArmor);
    }

    /**
     * Execute ship-to-ship combat
     */
    public function shipVsShip(array $attacker, array $defender): array
    {
        $result = [
            'success' => false,
            'escaped' => false,
            'defender_destroyed' => false,
            'attacker_damage' => 0,
            'defender_damage' => 0,
            'torpedos_used' => 0,
            'fighters_lost_attacker' => 0,
            'fighters_lost_defender' => 0,
            'message' => '',
        ];

        // Check if defender can escape
        if ($this->canEscape($attacker, $defender)) {
            $result['escaped'] = true;
            $result['message'] = 'Target escaped! They had better engines or cloaking.';
            return $result;
        }

        // Calculate attacker's offensive power
        $beamDamage = $this->calculateBeamDamage($attacker['beams']);

        // Use torpedoes if available
        $torpsToUse = min($attacker['torps'], 10); // Use max 10 torpedoes
        $torpDamage = $this->calculateTorpedoDamage($torpsToUse);
        $result['torpedos_used'] = $torpsToUse;

        // Fighter combat
        $attackerFighters = min($attacker['ship_fighters'], 100); // Deploy max 100
        $defenderFighters = min($defender['ship_fighters'], 100);

        $fighterDamage = 0;
        if ($attackerFighters > $defenderFighters) {
            $fighterDamage = $this->calculateFighterDamage($attackerFighters - $defenderFighters);
            $result['fighters_lost_defender'] = $defenderFighters;
            $result['fighters_lost_attacker'] = (int)($defenderFighters * 0.5);
        } else {
            $result['fighters_lost_attacker'] = $attackerFighters;
            $result['fighters_lost_defender'] = (int)($attackerFighters * 0.5);
        }

        // Total damage to defender
        $totalDamage = $beamDamage + $torpDamage + $fighterDamage;

        // Calculate defender's shields and armor
        $shieldStrength = $this->calculateShieldStrength($defender['shields']);
        $armorStrength = $this->calculateArmorStrength($defender['armor'], $defender['armor_pts']);

        // Apply damage
        $damageAfterShields = max(0, $totalDamage - $shieldStrength);
        $damageToArmor = min($damageAfterShields, $armorStrength);
        $hullDamage = max(0, $damageAfterShields - $armorStrength);

        $result['defender_damage'] = (int)$damageAfterShields;

        // Defender counterattack (reduced effectiveness)
        $counterBeamDamage = (int)($this->calculateBeamDamage($defender['beams']) * 0.5);
        $counterShieldStrength = $this->calculateShieldStrength($attacker['shields']);
        $attackerDamage = max(0, $counterBeamDamage - $counterShieldStrength);
        $result['attacker_damage'] = (int)$attackerDamage;

        // Check if defender is destroyed
        if ($hullDamage > 0 || $defender['armor_pts'] <= $damageToArmor) {
            $result['defender_destroyed'] = true;
            $result['success'] = true;
            $result['message'] = 'Target destroyed!';
        } else {
            $result['success'] = true;
            $result['message'] = 'Attack successful! Target damaged.';
        }

        return $result;
    }

    /**
     * Execute planet attack
     */
    public function shipVsPlanet(array $ship, array $planet): array
    {
        $result = [
            'success' => false,
            'planet_destroyed' => false,
            'planet_captured' => false,
            'damage_dealt' => 0,
            'ship_damage' => 0,
            'torpedos_used' => 0,
            'fighters_lost_ship' => 0,
            'fighters_lost_planet' => 0,
            'message' => '',
        ];

        // Can't attack your own planet
        if ($planet['owner'] == $ship['ship_id']) {
            $result['message'] = 'You cannot attack your own planet!';
            return $result;
        }

        // Calculate attack power
        $torpsToUse = min($ship['torps'], 20); // Use max 20 torpedoes on planet
        $torpDamage = $this->calculateTorpedoDamage($torpsToUse);
        $result['torpedos_used'] = $torpsToUse;

        $beamDamage = $this->calculateBeamDamage($ship['beams']);

        // Fighter combat
        $shipFighters = min($ship['ship_fighters'], 200);
        $planetFighters = $planet['fighters'];

        $fighterDamage = 0;
        if ($shipFighters > $planetFighters) {
            $fighterDamage = $this->calculateFighterDamage($shipFighters - $planetFighters);
            $result['fighters_lost_planet'] = $planetFighters;
            $result['fighters_lost_ship'] = (int)($planetFighters * 0.3);
        } else {
            // Planet defends successfully
            $result['fighters_lost_ship'] = $shipFighters;
            $result['fighters_lost_planet'] = (int)($shipFighters * 0.3);
            $result['message'] = 'Planet defenses repelled your attack!';

            // Ship takes damage from planet
            $planetBeams = $planet['base'] ? 500 : 100;
            $shieldStrength = $this->calculateShieldStrength($ship['shields']);
            $result['ship_damage'] = max(0, $planetBeams - $shieldStrength);

            return $result;
        }

        $totalDamage = $beamDamage + $torpDamage + $fighterDamage;
        $result['damage_dealt'] = (int)$totalDamage;

        // Planet base provides defense
        $baseDefense = $planet['base'] ? 10000 : 0;
        $totalDefense = $baseDefense + ($planet['torps'] * 10);

        if ($totalDamage > $totalDefense) {
            // Planet defeated
            if ($planet['base']) {
                // Destroy base
                $result['success'] = true;
                $result['message'] = 'Planet base destroyed! You can now capture it.';
            } else {
                // Capture planet
                $result['planet_captured'] = true;
                $result['success'] = true;
                $result['message'] = 'Planet captured!';
            }
        } else {
            // Damage but not captured
            $result['success'] = true;
            $result['message'] = 'Planet damaged but defenses hold!';

            // Ship takes counter damage
            $counterDamage = $planet['base'] ? 1000 : 200;
            $shieldStrength = $this->calculateShieldStrength($ship['shields']);
            $result['ship_damage'] = max(0, $counterDamage - $shieldStrength);
        }

        return $result;
    }

    /**
     * Check for sector mines
     */
    public function checkMines(int $shipId, int $sectorId, int $hullSize): array
    {
        $result = [
            'hit' => false,
            'damage' => 0,
            'mines_destroyed' => 0,
            'ship_destroyed' => false,
            'message' => '',
        ];

        // Small ships can avoid mines
        if ($hullSize < 8) {
            return $result;
        }

        // Get mines in sector
        $sql = "SELECT SUM(quantity) as total FROM sector_defence
                WHERE sector_id = :sector AND defence_type = 'M'";
        $mines = $this->db->fetchOne($sql, ['sector' => $sectorId]);

        if (!$mines || $mines['total'] == 0) {
            return $result;
        }

        // 20% chance per mine, max 80%
        $totalMines = (int)$mines['total'];
        $hitChance = min(80, $totalMines * 20);

        if (random_int(1, 100) > $hitChance) {
            return $result;
        }

        // Hit mines!
        $result['hit'] = true;
        $minesHit = min($totalMines, random_int(1, 3));
        $result['mines_destroyed'] = $minesHit;
        $result['damage'] = $minesHit * 500;
        $result['message'] = "You hit $minesHit mine(s)! Damage: {$result['damage']}";

        return $result;
    }

    /**
     * Check for sector fighters attacking
     */
    public function checkSectorFighters(array $ship, int $sectorId): array
    {
        $result = [
            'attacked' => false,
            'damage' => 0,
            'fighters_destroyed' => 0,
            'ship_destroyed' => false,
            'message' => '',
        ];

        // Get enemy fighters in sector
        $sql = "SELECT sd.quantity, sd.ship_id, s.team
                FROM sector_defence sd
                JOIN ships s ON sd.ship_id = s.ship_id
                WHERE sd.sector_id = :sector
                AND sd.defence_type = 'F'
                AND sd.ship_id != :ship_id";

        $defenses = $this->db->fetchAll($sql, [
            'sector' => $sectorId,
            'ship_id' => $ship['ship_id']
        ]);

        if (empty($defenses)) {
            return $result;
        }

        $totalEnemyFighters = 0;
        foreach ($defenses as $defense) {
            // Don't attack team members
            if ($ship['team'] != 0 && $defense['team'] == $ship['team']) {
                continue;
            }
            $totalEnemyFighters += $defense['quantity'];
        }

        if ($totalEnemyFighters == 0) {
            return $result;
        }

        // Fighters attack!
        $result['attacked'] = true;
        $fighterDamage = $this->calculateFighterDamage($totalEnemyFighters);

        $shieldStrength = $this->calculateShieldStrength($ship['shields']);
        $result['damage'] = max(0, $fighterDamage - $shieldStrength);
        $result['message'] = "Sector fighters attacked! $totalEnemyFighters fighters dealt {$result['damage']} damage!";

        return $result;
    }

    /**
     * Apply damage to ship
     */
    public function applyDamageToShip(int $shipId, int $damage): bool
    {
        $ship = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id', ['id' => $shipId]);

        if (!$ship) {
            return false;
        }

        $armorDamage = min($ship['armor_pts'], $damage);
        $newArmorPts = $ship['armor_pts'] - $armorDamage;

        // If armor is depleted, ship is destroyed
        if ($newArmorPts <= 0) {
            return $this->destroyShip($shipId);
        }

        return $this->db->execute(
            'UPDATE ships SET armor_pts = :armor WHERE ship_id = :id',
            ['armor' => $newArmorPts, 'id' => $shipId]
        );
    }

    /**
     * Destroy a ship
     */
    public function destroyShip(int $shipId): bool
    {
        return $this->db->execute(
            'UPDATE ships SET ship_destroyed = TRUE WHERE ship_id = :id',
            ['id' => $shipId]
        );
    }

    /**
     * Award credits for kill
     */
    public function awardKillCredits(int $winnerId, array $loser): int
    {
        // Award percentage of loser's score as credits
        $credits = (int)($loser['score'] * 0.1);

        $this->db->execute(
            'UPDATE ships SET credits = credits + :credits WHERE ship_id = :id',
            ['credits' => $credits, 'id' => $winnerId]
        );

        return $credits;
    }

    /**
     * Check and collect bounty
     */
    public function collectBounty(int $killerId, int $targetId): int
    {
        $sql = "SELECT SUM(amount) as total FROM bounty WHERE bounty_on = :target";
        $bounty = $this->db->fetchOne($sql, ['target' => $targetId]);

        if (!$bounty || $bounty['total'] == 0) {
            return 0;
        }

        $total = (int)$bounty['total'];

        // Award bounty to killer
        $this->db->execute(
            'UPDATE ships SET credits = credits + :amount WHERE ship_id = :id',
            ['amount' => $total, 'id' => $killerId]
        );

        // Remove bounties
        $this->db->execute('DELETE FROM bounty WHERE bounty_on = :target', ['target' => $targetId]);

        return $total;
    }

    /**
     * Execute defense vs defense combat when defenses are deployed in same sector
     * Fighters attack mines, mines destroy fighters
     */
    public function defenseVsDefense(int $sectorId, int $deployingShipId): array
    {
        $result = [
            'combat_occurred' => false,
            'friendly_losses' => 0,
            'enemy_losses' => 0,
            'message' => '',
        ];

        // Get deploying ship's team
        $ship = $this->db->fetchOne('SELECT team FROM ships WHERE ship_id = :id', ['id' => $deployingShipId]);
        if (!$ship) {
            return $result;
        }

        // Get deploying ship's defenses in this sector
        $sql = "SELECT * FROM sector_defence
                WHERE sector_id = :sector AND ship_id = :ship
                ORDER BY quantity DESC";
        $friendlyDefenses = $this->db->fetchAll($sql, ['sector' => $sectorId, 'ship' => $deployingShipId]);

        if (empty($friendlyDefenses)) {
            return $result;
        }

        // Get enemy defenses (different owner, not same team if team is set)
        $sql = "SELECT sd.*, s.team
                FROM sector_defence sd
                JOIN ships s ON sd.ship_id = s.ship_id
                WHERE sd.sector_id = :sector
                AND sd.ship_id != :ship
                ORDER BY sd.quantity DESC";
        $enemyDefenses = $this->db->fetchAll($sql, ['sector' => $sectorId, 'ship' => $deployingShipId]);

        if (empty($enemyDefenses)) {
            return $result;
        }

        // Filter out team members if applicable
        $realEnemies = [];
        foreach ($enemyDefenses as $defense) {
            if ($ship['team'] == 0 || $defense['team'] != $ship['team']) {
                $realEnemies[] = $defense;
            }
        }

        if (empty($realEnemies)) {
            return $result;
        }

        $result['combat_occurred'] = true;

        // Combat: each side attacks the other
        foreach ($friendlyDefenses as $friendly) {
            foreach ($realEnemies as &$enemy) {
                if ($friendly['quantity'] <= 0 || $enemy['quantity'] <= 0) {
                    continue;
                }

                // Calculate damage
                $friendlyDamage = (int)($friendly['quantity'] * 0.5); // 50% effectiveness
                $enemyDamage = (int)($enemy['quantity'] * 0.5);

                // Apply damage
                $friendlyLosses = min($friendly['quantity'], $enemyDamage);
                $enemyLosses = min($enemy['quantity'], $friendlyDamage);

                // Update quantities
                $this->db->execute(
                    'UPDATE sector_defence SET quantity = quantity - :losses WHERE defence_id = :id',
                    ['losses' => $friendlyLosses, 'id' => $friendly['defence_id']]
                );

                $this->db->execute(
                    'UPDATE sector_defence SET quantity = quantity - :losses WHERE defence_id = :id',
                    ['losses' => $enemyLosses, 'id' => $enemy['defence_id']]
                );

                $result['friendly_losses'] += $friendlyLosses;
                $result['enemy_losses'] += $enemyLosses;

                // Update in-memory values
                $friendly['quantity'] -= $friendlyLosses;
                $enemy['quantity'] -= $enemyLosses;
            }
        }

        // Clean up destroyed defenses
        $this->db->execute('DELETE FROM sector_defence WHERE quantity <= 0');

        if ($result['friendly_losses'] > 0 || $result['enemy_losses'] > 0) {
            $result['message'] = "Defense combat! You lost {$result['friendly_losses']} units, enemies lost {$result['enemy_losses']} units.";
        }

        return $result;
    }
}
