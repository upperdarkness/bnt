<?php

declare(strict_types=1);

namespace BNT\Core;

use BNT\Core\Database;

class SchedulerTasks
{
    public function __construct(
        private Database $db,
        private array $config
    ) {}

    /**
     * Generate new turns for all active players
     * 
     * @param int $cycles Number of scheduler cycles that have passed (defaults to 1)
     */
    public function generateTurns(int $cycles = 1): string
    {
        $turnsPerCycle = $this->config['scheduler']['turns'];
        $maxTurns = $this->config['game']['max_turns'];
        
        // Calculate total turns to add based on missed cycles
        $turnsToAdd = $turnsPerCycle * $cycles;
        
        // Safety cap: never add more than 24 hours worth of turns at once
        // (assuming 2 minute intervals, that's 720 cycles = 1440 turns max)
        $maxTurnsPerUpdate = $turnsPerCycle * 720; // 24 hours worth
        $turnsToAdd = min($turnsToAdd, $maxTurnsPerUpdate);

        $result = $this->db->execute(
            "UPDATE ships
             SET turns = LEAST(turns + :turns, :max_turns)
             WHERE ship_destroyed = FALSE
             AND last_login > NOW() - INTERVAL '30 days'",
            ['turns' => $turnsToAdd, 'max_turns' => $maxTurns]
        );

        // Faction turn multipliers (admin-visible NPC advantage; >1.0 only)
        foreach ($this->config['npc']['factions'] ?? [] as $faction => $tpl) {
            $multiplier = (float)($tpl['turn_multiplier'] ?? 1.0);
            if ($multiplier > 1.0) {
                $this->db->execute(
                    "UPDATE ships SET turns = LEAST(turns + :extra, :max_turns)
                     WHERE is_npc = TRUE AND ship_destroyed = FALSE
                     AND ship_id IN (SELECT ship_id FROM npc_profiles WHERE faction = :faction)",
                    ['extra' => (int)round($turnsToAdd * ($multiplier - 1.0)), 'max_turns' => $maxTurns, 'faction' => $faction]
                );
            }
        }

        $count = $this->db->fetchOne("SELECT COUNT(*) as count FROM ships WHERE ship_destroyed = FALSE")['count'];
        
        $cyclesInfo = $cycles > 1 ? " ($cycles cycles)" : "";

        return "Added $turnsToAdd turns to $count active players$cyclesInfo";
    }

    /**
     * Port production - generate and consume commodities at ports
     * Implements regeneration (asymptotic growth) for export commodities
     * Implements consumption (decay) for import commodities
     */
    public function portProduction(): string
    {
        $ports = $this->db->fetchAll(
            "SELECT sector_id, port_type, port_ore, port_organics, port_goods, port_energy, port_colonists 
             FROM universe 
             WHERE port_type IS NOT NULL AND port_type != 'none' AND port_type != 'special'"
        );

        $updated = 0;
        foreach ($ports as $port) {
            $portType = $port['port_type'];
            $updates = [];

            // Get what this port exports (sells) and imports (buys)
            $exportCommodities = $this->getPortExportCommodities($portType);
            $importCommodities = $this->getPortImportCommodities($portType);

            // Regeneration: Ports generate their export commodity
            // Formula: New_Stock = Current_Stock + ((Max_Capacity - Current_Stock) × Regeneration_Rate)
            foreach ($exportCommodities as $commodity) {
                $config = $this->config['trading'][$commodity];
                $currentStock = (int)$port["port_$commodity"];
                $maxCapacity = $config['limit'];
                $regenerationRate = $config['regeneration_rate'];

                // Calculate regeneration based on empty space
                $emptySpace = $maxCapacity - $currentStock;
                $regeneration = (int)($emptySpace * $regenerationRate);
                $newStock = min($currentStock + $regeneration, $maxCapacity);

                if ($newStock != $currentStock) {
                    $updates["port_$commodity"] = $newStock;
                }
            }

            // Consumption: Ports consume their import commodities
            // Formula: New_Stock = Current_Stock - (Current_Stock × Consumption_Rate)
            foreach ($importCommodities as $commodity) {
                $config = $this->config['trading'][$commodity];
                $currentStock = (int)$port["port_$commodity"];
                $consumptionRate = $config['consumption_rate'];

                if ($currentStock > 0) {
                    // Calculate consumption as percentage of current stock
                    $consumption = (int)($currentStock * $consumptionRate);
                    $newStock = max($currentStock - $consumption, 0);

                    if ($newStock != $currentStock) {
                        $updates["port_$commodity"] = $newStock;
                    }
                }
            }

            // Colonist generation: Ports generate colonists over time
            // Uses regeneration formula similar to commodities
            $currentColonists = (int)($port['port_colonists'] ?? 0);
            $maxColonists = 100000; // Maximum colonists at a port
            $colonistRegenerationRate = 0.02; // 2% of empty space per tick
            
            $emptySpace = $maxColonists - $currentColonists;
            $colonistGrowth = (int)($emptySpace * $colonistRegenerationRate);
            $newColonists = min($currentColonists + $colonistGrowth, $maxColonists);

            if ($newColonists != $currentColonists) {
                $updates['port_colonists'] = $newColonists;
            }

            // Apply updates if any
            if (!empty($updates)) {
                $setParts = [];
                foreach ($updates as $col => $value) {
                    $setParts[] = "$col = :$col";
                }
                $setClause = implode(', ', $setParts);

                $params = ['sector' => $port['sector_id']];
                foreach ($updates as $col => $value) {
                    $params[$col] = $value;
                }

                $this->db->execute(
                    "UPDATE universe SET $setClause WHERE sector_id = :sector",
                    $params
                );
                $updated++;
            }
        }

        return "Updated production/consumption for $updated ports";
    }

    /**
     * Get commodities that a port exports (sells)
     */
    private function getPortExportCommodities(string $portType): array
    {
        return match ($portType) {
            'ore' => ['ore'],
            'organics' => ['organics'],
            'goods' => ['goods'],
            'energy' => ['energy'],
            default => []
        };
    }

    /**
     * Get commodities that a port imports (buys)
     */
    private function getPortImportCommodities(string $portType): array
    {
        return match ($portType) {
            'ore' => ['organics', 'goods'],
            'organics' => ['ore', 'goods'],
            'goods' => ['ore', 'organics'],
            'energy' => ['ore', 'organics', 'goods'],
            default => []
        };
    }

    /**
     * Planet production - produce resources based on colonist allocation
     */
    public function planetProduction(int $cycles = 1): string
    {
        $cycles = max(1, min($cycles, 720));
        $economy = new PlanetEconomy($this->config['planet_economy'] ?? []);
        $pdo = $this->db->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $planets = $this->db->fetchAll(
                'SELECT * FROM planets WHERE owner > 0 AND colonists > 0 ORDER BY planet_id FOR UPDATE'
            );
            foreach ($planets as $planet) {
                for ($cycle = 0; $cycle < $cycles; $cycle++) {
                    $planet = $economy->cycle($planet)['planet'];
                }
                $params = ['id' => (int)$planet['planet_id']];
                $sets = [];
                foreach (['ore', 'organics', 'goods', 'energy', 'fighters', 'torps', 'colonists', 'credits'] as $field) {
                    $sets[] = "$field = :$field";
                    $params[$field] = (int)$planet[$field];
                }
                $this->db->execute('UPDATE planets SET ' . implode(', ', $sets) . ' WHERE planet_id = :id', $params);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return 'Updated economy for ' . count($planets) . " planets ($cycles cycles)";
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * IGB Interest - add interest to bank accounts
     */
    public function igbInterest(): string
    {
        $interestRate = 0.001; // 0.1% interest per cycle

        $result = $this->db->execute(
            "UPDATE ibank_accounts
             SET balance = balance + FLOOR(balance * :rate)
             WHERE balance > 0",
            ['rate' => $interestRate]
        );

        $count = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM ibank_accounts WHERE balance > 0"
        )['count'];

        return "Applied interest to $count bank accounts";
    }

    /**
     * Update player rankings
     */
    public function updateRankings(): string
    {
        // Clear existing rankings
        $this->db->execute('DELETE FROM rankings');

        // Get top 100 players by score
        $players = $this->db->fetchAll(
            "SELECT ship_id, character_name, score, credits, ship_fighters, team
             FROM ships
             WHERE ship_destroyed = FALSE
             ORDER BY score DESC
             LIMIT 100"
        );

        $rank = 1;
        foreach ($players as $player) {
            $this->db->execute(
                "INSERT INTO rankings (rank, ship_id, character_name, score, credits, fighters, team, updated_at)
                 VALUES (:rank, :ship_id, :name, :score, :credits, :fighters, :team, NOW())",
                [
                    'rank' => $rank,
                    'ship_id' => $player['ship_id'],
                    'name' => $player['character_name'],
                    'score' => $player['score'],
                    'credits' => $player['credits'],
                    'fighters' => $player['ship_fighters'],
                    'team' => $player['team']
                ]
            );
            $rank++;
        }

        return "Updated rankings with " . count($players) . " players";
    }

    /**
     * Generate game news from recent events
     */
    public function generateNews(): string
    {
        return (new \BNT\Models\News($this->db))->publishCombatEvents();
    }

    /**
     * Degrade deployed fighters - fighters decay over time
     */
    public function degradeFighters(): string
    {
        $degradeRate = 0.01; // 1% degradation per cycle

        $result = $this->db->execute(
            "UPDATE sector_defence
             SET quantity = GREATEST(FLOOR(quantity * :rate), 0)
             WHERE defence_type = 'F'
             AND quantity > 0",
            ['rate' => (1 - $degradeRate)]
        );

        // Remove defenses with 0 quantity
        $this->db->execute("DELETE FROM sector_defence WHERE quantity = 0");

        $count = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM sector_defence WHERE defence_type = 'F'"
        )['count'];

        return "Degraded $count fighter deployments";
    }

    /**
     * Clean up expired sessions and inactive players
     */
    public function cleanup(): string
    {
        $actions = [];

        // Remove old session data (older than 7 days)
        $this->db->execute("DELETE FROM sessions WHERE last_activity < NOW() - INTERVAL '7 days'");
        $actions[] = "cleaned sessions";

        // Clear old logs (keep last 30 days)
        $this->db->execute("DELETE FROM logs WHERE created_at < NOW() - INTERVAL '30 days'");
        $actions[] = "cleaned old logs";

        // Clear old team invitations (older than 7 days)
        $this->db->execute("DELETE FROM team_invitations WHERE created_date < NOW() - INTERVAL '7 days'");
        $actions[] = "cleaned old invitations";

        return "Cleanup: " . implode(", ", $actions);
    }

    /**
     * Tow large ships out of sector 1 (starbase)
     * Ships above the configured hull level are automatically towed to a random connected sector
     */
    public function towLargeShips(): string
    {
        $maxHullLevel = $this->config['starbase']['max_hull_level'] ?? 5;
        
        // Find ships in sector 1 with hull above threshold
        $ships = $this->db->fetchAll(
            "SELECT ship_id, character_name, hull, sector 
             FROM ships 
             WHERE sector = 1 
             AND hull > :max_hull 
             AND ship_destroyed = FALSE",
            ['max_hull' => $maxHullLevel]
        );

        if (empty($ships)) {
            return "No large ships to tow from sector 1";
        }

        $towedCount = 0;
        $errors = [];

        foreach ($ships as $ship) {
            // Get connected sectors from sector 1
            $linkedSectors = $this->db->fetchAll(
                "SELECT u.sector_id 
                 FROM universe u
                 INNER JOIN links l ON u.sector_id = l.link_dest
                 WHERE l.link_start = 1
                 ORDER BY u.sector_id",
                []
            );

            if (empty($linkedSectors)) {
                $errors[] = "Ship {$ship['character_name']} (ID: {$ship['ship_id']}) - No connected sectors found";
                continue;
            }

            // Randomly select a connected sector
            $randomIndex = array_rand($linkedSectors);
            $targetSector = (int)$linkedSectors[$randomIndex]['sector_id'];

            // Move the ship
            $this->db->execute(
                "UPDATE ships SET sector = :sector WHERE ship_id = :ship_id",
                ['sector' => $targetSector, 'ship_id' => $ship['ship_id']]
            );

            // Create log entry
            // Log type 99 = tow event (using a high number to avoid conflicts)
            $logData = json_encode([
                'action' => 'towed_from_starbase',
                'from_sector' => 1,
                'to_sector' => $targetSector,
                'reason' => "Hull level {$ship['hull']} exceeds maximum allowed level {$maxHullLevel}",
                'hull_level' => $ship['hull']
            ]);

            $this->db->execute(
                "INSERT INTO logs (ship_id, log_type, log_data, logged_at) 
                 VALUES (:ship_id, 99, :log_data, NOW())",
                ['ship_id' => $ship['ship_id'], 'log_data' => $logData]
            );

            $towedCount++;
        }

        $result = "Towed $towedCount large ship(s) from sector 1";
        if (!empty($errors)) {
            $result .= ". Errors: " . implode('; ', $errors);
        }

        return $result;
    }
}
