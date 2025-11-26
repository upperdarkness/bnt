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
     */
    public function generateTurns(): string
    {
        $turnsToAdd = $this->config['scheduler']['turns'];
        $maxTurns = $this->config['game']['max_turns'];

        $result = $this->db->execute(
            "UPDATE ships
             SET turns = LEAST(turns + :turns, :max_turns)
             WHERE ship_destroyed = FALSE
             AND last_login > NOW() - INTERVAL '30 days'",
            ['turns' => $turnsToAdd, 'max_turns' => $maxTurns]
        );

        $count = $this->db->fetchOne("SELECT COUNT(*) as count FROM ships WHERE ship_destroyed = FALSE")['count'];

        return "Added $turnsToAdd turns to $count active players";
    }

    /**
     * Port production - generate commodities at ports
     */
    public function portProduction(): string
    {
        $rate = $this->config['scheduler']['ports'];
        $ports = $this->db->fetchAll(
            "SELECT sector_id, port_type FROM universe WHERE port_type IS NOT NULL"
        );

        $updated = 0;
        foreach ($ports as $port) {
            $portType = $port['port_type'];

            // Determine what this port produces based on type
            $updates = [];
            switch ($portType) {
                case 'ore':
                    $updates['port_ore'] = "LEAST(port_ore + $rate, 100000000)";
                    break;
                case 'organics':
                    $updates['port_organics'] = "LEAST(port_organics + $rate, 100000000)";
                    break;
                case 'goods':
                    $updates['port_goods'] = "LEAST(port_goods + $rate, 100000000)";
                    break;
                case 'energy':
                    $updates['port_energy'] = "LEAST(port_energy + $rate, 1000000000)";
                    break;
            }

            if (!empty($updates)) {
                $setParts = [];
                foreach ($updates as $col => $expr) {
                    $setParts[] = "$col = $expr";
                }
                $setClause = implode(', ', $setParts);

                $this->db->execute(
                    "UPDATE universe SET $setClause WHERE sector_id = :sector",
                    ['sector' => $port['sector_id']]
                );
                $updated++;
            }
        }

        return "Updated production for $updated ports";
    }

    /**
     * Planet production - produce resources based on colonist allocation
     */
    public function planetProduction(): string
    {
        $planets = $this->db->fetchAll(
            "SELECT * FROM planets WHERE owner != 0 AND colonists >= 100"
        );

        $updated = 0;
        foreach ($planets as $planet) {
            $colonists = (int)$planet['colonists'];
            $baseProduction = floor($colonists / 100); // Production rate based on colonists

            $updates = [];

            // Calculate production for each resource
            if ($planet['prod_ore'] > 0) {
                $amount = floor($baseProduction * ($planet['prod_ore'] / 100));
                $updates[] = "ore = LEAST(ore + $amount, 100000000)";
            }

            if ($planet['prod_organics'] > 0) {
                $amount = floor($baseProduction * ($planet['prod_organics'] / 100));
                $updates[] = "organics = LEAST(organics + $amount, 100000000)";
            }

            if ($planet['prod_goods'] > 0) {
                $amount = floor($baseProduction * ($planet['prod_goods'] / 100));
                $updates[] = "goods = LEAST(goods + $amount, 100000000)";
            }

            if ($planet['prod_energy'] > 0) {
                $amount = floor($baseProduction * ($planet['prod_energy'] / 100));
                $updates[] = "energy = LEAST(energy + $amount, 1000000000)";
            }

            if ($planet['prod_fighters'] > 0) {
                $amount = floor($baseProduction * ($planet['prod_fighters'] / 100) / 10);
                $updates[] = "fighters = LEAST(fighters + $amount, 1000000)";
            }

            if ($planet['prod_torp'] > 0) {
                $amount = floor($baseProduction * ($planet['prod_torp'] / 100) / 20);
                $updates[] = "torps = LEAST(torps + $amount, 1000000)";
            }

            if (!empty($updates)) {
                $setClause = implode(', ', $updates);
                $this->db->execute(
                    "UPDATE planets SET $setClause WHERE planet_id = :id",
                    ['id' => $planet['planet_id']]
                );
                $updated++;
            }
        }

        return "Updated production for $updated planets";
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
        // Get recent combat events
        $combatEvents = $this->db->fetchAll(
            "SELECT l.*, s.character_name
             FROM logs l
             JOIN ships s ON l.ship_id = s.ship_id
             WHERE l.log_type IN (3, 13)
             AND l.created_at > NOW() - INTERVAL '15 minutes'
             ORDER BY l.log_id DESC
             LIMIT 10"
        );

        $newsItems = 0;
        foreach ($combatEvents as $event) {
            $data = json_decode($event['log_data'], true);

            if ($data && isset($data['defender_destroyed']) && $data['defender_destroyed']) {
                $news = "{$event['character_name']} destroyed an enemy ship!";

                $this->db->execute(
                    "INSERT INTO news (headline, details, created_at) VALUES (:headline, :details, NOW())",
                    ['headline' => $news, 'details' => json_encode($data)]
                );
                $newsItems++;
            }
        }

        // Clean old news (keep last 100)
        $this->db->execute(
            "DELETE FROM news WHERE news_id NOT IN (
                SELECT news_id FROM (
                    SELECT news_id FROM news ORDER BY created_at DESC LIMIT 100
                ) tmp
            )"
        );

        return "Generated $newsItems news items";
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
}
