<?php
declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class PlayerInfo
{
    public function __construct(private Database $db) {}

    /**
     * Get comprehensive player information by ship ID
     *
     * @param int $playerId Player's ship ID
     * @return array|null Player information or null if not found
     */
    public function getPlayerInfo(int $playerId): ?array
    {
        $query = "
            SELECT
                s.ship_id,
                s.character_name,
                s.ship_name,
                s.score,
                s.rating,
                s.last_login,
                s.created_at,
                s.turns_used,
                s.turns,
                s.ship_destroyed,
                s.hull,
                s.engines,
                s.power,
                s.computer,
                s.sensors,
                s.beams,
                s.torp_launchers,
                s.shields,
                s.armor,
                s.cloak,
                t.team_id,
                t.team_name,
                t.team_description,
                EXTRACT(EPOCH FROM (NOW() - s.last_login)) as seconds_since_login,
                CASE
                    WHEN EXTRACT(EPOCH FROM (NOW() - s.last_login)) <= 300 THEN true
                    ELSE false
                END as is_online
            FROM ships s
            LEFT JOIN teams t ON s.team_id = t.team_id
            WHERE s.ship_id = :player_id
        ";

        return $this->db->fetch($query, ['player_id' => $playerId]);
    }

    /**
     * Get player's rank by score
     *
     * @param int $playerId Player's ship ID
     * @return int|null Player's rank or null if not found
     */
    public function getPlayerRank(int $playerId): ?int
    {
        $query = "
            SELECT rank FROM (
                SELECT
                    ship_id,
                    ROW_NUMBER() OVER (ORDER BY score DESC, character_name ASC) as rank
                FROM ships
                WHERE ship_destroyed = FALSE
            ) ranked
            WHERE ship_id = :player_id
        ";

        $result = $this->db->fetch($query, ['player_id' => $playerId]);
        return $result ? (int)$result['rank'] : null;
    }

    /**
     * Get player's planet count
     *
     * @param int $playerId Player's ship ID
     * @return int Number of planets owned
     */
    public function getPlanetCount(int $playerId): int
    {
        $result = $this->db->fetch(
            'SELECT COUNT(*) as count FROM planets WHERE owner = :player_id',
            ['player_id' => $playerId]
        );

        return (int)($result['count'] ?? 0);
    }

    /**
     * Calculate total ship level (sum of all component levels)
     *
     * @param array $player Player data
     * @return int Total ship level
     */
    public static function calculateShipLevel(array $player): int
    {
        return (int)(
            ($player['hull'] ?? 0) +
            ($player['engines'] ?? 0) +
            ($player['power'] ?? 0) +
            ($player['computer'] ?? 0) +
            ($player['sensors'] ?? 0) +
            ($player['beams'] ?? 0) +
            ($player['torp_launchers'] ?? 0) +
            ($player['shields'] ?? 0) +
            ($player['armor'] ?? 0) +
            ($player['cloak'] ?? 0)
        );
    }

    /**
     * Format rating value (good/evil alignment)
     *
     * @param float $rating Raw rating value
     * @return int Formatted rating
     */
    public static function formatRating(float $rating): int
    {
        $formattedRating = (int)round(sqrt(abs($rating)));
        return $rating < 0 ? -$formattedRating : $formattedRating;
    }

    /**
     * Get player's team members
     *
     * @param int $teamId Team ID
     * @param int $excludePlayerId Player ID to exclude from results
     * @return array List of team members
     */
    public function getTeamMembers(int $teamId, int $excludePlayerId = 0): array
    {
        $query = "
            SELECT
                ship_id,
                character_name,
                score,
                last_login,
                CASE
                    WHEN EXTRACT(EPOCH FROM (NOW() - last_login)) <= 300 THEN true
                    ELSE false
                END as is_online
            FROM ships
            WHERE team_id = :team_id
            AND ship_id != :exclude_id
            AND ship_destroyed = FALSE
            ORDER BY score DESC
            LIMIT 10
        ";

        return $this->db->fetchAll($query, [
            'team_id' => $teamId,
            'exclude_id' => $excludePlayerId
        ]);
    }

    /**
     * Get recent activity summary for a player
     *
     * @param int $playerId Player's ship ID
     * @return array Activity statistics
     */
    public function getActivitySummary(int $playerId): array
    {
        // Get account age in days
        $player = $this->db->fetch(
            'SELECT EXTRACT(EPOCH FROM (NOW() - created_at)) / 86400 as days_active FROM ships WHERE ship_id = :player_id',
            ['player_id' => $playerId]
        );

        $daysActive = max(1, (int)($player['days_active'] ?? 1));

        return [
            'days_active' => $daysActive
        ];
    }

    /**
     * Check if player can be messaged
     *
     * @param int $playerId Target player's ship ID
     * @return bool True if player exists and is not destroyed
     */
    public function canMessage(int $playerId): bool
    {
        $result = $this->db->fetch(
            'SELECT ship_id FROM ships WHERE ship_id = :player_id AND ship_destroyed = FALSE',
            ['player_id' => $playerId]
        );

        return $result !== null;
    }

    /**
     * Search for players by name
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @return array List of matching players
     */
    public function searchPlayers(string $query, int $limit = 20): array
    {
        $searchQuery = "
            SELECT
                s.ship_id,
                s.character_name,
                s.score,
                t.team_name,
                CASE
                    WHEN EXTRACT(EPOCH FROM (NOW() - s.last_login)) <= 300 THEN true
                    ELSE false
                END as is_online
            FROM ships s
            LEFT JOIN teams t ON s.team_id = t.team_id
            WHERE s.ship_destroyed = FALSE
            AND LOWER(s.character_name) LIKE LOWER(:query)
            ORDER BY s.score DESC
            LIMIT :limit
        ";

        return $this->db->fetchAll($searchQuery, [
            'query' => '%' . $query . '%',
            'limit' => $limit
        ]);
    }
}
