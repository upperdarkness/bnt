<?php
declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class Ranking
{
    public function __construct(private Database $db) {}

    /**
     * Get player rankings with optional sorting
     *
     * @param string $sortBy Sort column (score, turns, login, good, bad, alliance, efficiency)
     * @param int $limit Maximum number of results
     * @return array List of ranked players
     */
    public function getRankings(string $sortBy = 'score', int $limit = 100): array
    {
        $orderBy = match($sortBy) {
            'turns' => 's.turns_used DESC, s.character_name ASC',
            'login' => 's.last_login DESC, s.character_name ASC',
            'good' => 's.rating DESC, s.character_name ASC',
            'bad' => 's.rating ASC, s.character_name ASC',
            'alliance' => 't.team_name DESC, s.character_name ASC',
            'efficiency' => 'efficiency DESC, s.character_name ASC',
            default => 's.score DESC, s.character_name ASC'
        };

        $query = "
            SELECT
                s.ship_id,
                s.character_name,
                s.score,
                s.turns_used,
                s.last_login,
                EXTRACT(EPOCH FROM s.last_login) as last_login_timestamp,
                s.rating,
                t.team_name,
                CASE
                    WHEN s.turns_used < 150 THEN 0
                    ELSE ROUND(s.score::numeric / NULLIF(s.turns_used, 0))
                END as efficiency,
                CASE
                    WHEN EXTRACT(EPOCH FROM (NOW() - s.last_login)) <= 300 THEN true
                    ELSE false
                END as is_online
            FROM ships s
            LEFT JOIN teams t ON s.team_id = t.team_id
            WHERE s.ship_destroyed = FALSE
            ORDER BY {$orderBy}
            LIMIT :limit
        ";

        return $this->db->fetchAll($query, ['limit' => $limit]);
    }

    /**
     * Get total count of active players
     *
     * @return int Total number of active players
     */
    public function getPlayerCount(): int
    {
        $result = $this->db->fetch(
            'SELECT COUNT(*) as count FROM ships WHERE ship_destroyed = FALSE'
        );

        return (int)($result['count'] ?? 0);
    }

    /**
     * Get a player's current rank by score
     *
     * @param int $playerId Player's ship ID
     * @return int|null The player's rank (1-based) or null if not found
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
     * Get top players by team
     *
     * @param int $limit Maximum number of teams to return
     * @return array List of top teams with total score
     */
    public function getTeamRankings(int $limit = 20): array
    {
        $query = "
            SELECT
                t.team_id,
                t.team_name,
                COUNT(s.ship_id) as member_count,
                SUM(s.score) as total_score,
                AVG(s.score)::integer as avg_score,
                MAX(s.score) as top_player_score
            FROM teams t
            INNER JOIN ships s ON t.team_id = s.team_id
            WHERE s.ship_destroyed = FALSE
            GROUP BY t.team_id, t.team_name
            HAVING COUNT(s.ship_id) > 0
            ORDER BY total_score DESC
            LIMIT :limit
        ";

        return $this->db->fetchAll($query, ['limit' => $limit]);
    }

    /**
     * Calculate and format the rating value (good/evil alignment)
     * Square root of absolute value, negative if rating is negative
     *
     * @param float $rating Raw rating value
     * @return int Formatted rating
     */
    public static function formatRating(float $rating): int
    {
        $formattedRating = (int)round(sqrt(abs($rating)));

        return $rating < 0 ? -$formattedRating : $formattedRating;
    }
}
