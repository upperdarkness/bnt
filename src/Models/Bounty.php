<?php

declare(strict_types=1);

namespace BNT\Models;

class Bounty extends Model
{
    protected string $table = 'bounty';
    protected string $primaryKey = 'bounty_id';

    /**
     * Get all bounties on a specific player
     */
    public function getBountiesOn(int $shipId): array
    {
        $sql = "SELECT b.*, s.character_name as placed_by_name
                FROM {$this->table} b
                LEFT JOIN ships s ON b.placed_by = s.ship_id
                WHERE b.bounty_on = :ship_id
                ORDER BY b.amount DESC";

        return $this->db->fetchAll($sql, ['ship_id' => $shipId]);
    }

    /**
     * Get all bounties placed by a player
     */
    public function getBountiesBy(int $shipId): array
    {
        $sql = "SELECT b.*, s.character_name as target_name
                FROM {$this->table} b
                JOIN ships s ON b.bounty_on = s.ship_id
                WHERE b.placed_by = :ship_id
                ORDER BY b.amount DESC";

        return $this->db->fetchAll($sql, ['ship_id' => $shipId]);
    }

    /**
     * Get total bounty on a player
     */
    public function getTotalBounty(int $shipId): int
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) as total
                FROM {$this->table}
                WHERE bounty_on = :ship_id";

        $result = $this->db->fetchOne($sql, ['ship_id' => $shipId]);
        return (int)($result['total'] ?? 0);
    }

    /**
     * Place a bounty
     */
    public function placeBounty(int $placerId, int $targetId, int $amount): bool
    {
        // Don't allow self-bounty
        if ($placerId === $targetId) {
            return false;
        }

        return (bool)$this->create([
            'placed_by' => $placerId,
            'bounty_on' => $targetId,
            'amount' => $amount,
        ]);
    }

    /**
     * Cancel all bounties placed by a player
     */
    public function cancelBountiesBy(int $shipId): bool
    {
        return $this->db->execute(
            "DELETE FROM {$this->table} WHERE placed_by = :ship_id",
            ['ship_id' => $shipId]
        );
    }

    /**
     * Get top bounties (leaderboard)
     */
    public function getTopBounties(int $limit = 10): array
    {
        $sql = "SELECT s.ship_id, s.character_name, s.score,
                COALESCE(SUM(b.amount), 0) as total_bounty
                FROM ships s
                LEFT JOIN {$this->table} b ON s.ship_id = b.bounty_on
                WHERE s.ship_destroyed = FALSE
                GROUP BY s.ship_id
                HAVING SUM(b.amount) > 0
                ORDER BY total_bounty DESC
                LIMIT :limit";

        return $this->db->fetchAll($sql, ['limit' => $limit]);
    }
}
