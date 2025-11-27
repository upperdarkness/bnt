<?php
declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class AttackLog
{
    public function __construct(private Database $db) {}

    /**
     * Log an attack event
     *
     * @param int $attackerId Attacker's ship ID
     * @param string $attackerName Attacker's character name
     * @param int|null $defenderId Defender's ship/planet ID (null for defenses)
     * @param string|null $defenderName Defender's name
     * @param string $attackType Type of attack (ship, planet, defense)
     * @param string $result Result of attack (success, failure, destroyed, escaped)
     * @param int $damageDealttotal Damage dealt in the attack
     * @param int $sector Sector where attack occurred
     * @return int Log ID
     */
    public function logAttack(
        int $attackerId,
        string $attackerName,
        ?int $defenderId,
        ?string $defenderName,
        string $attackType,
        string $result,
        int $damageDealt = 0,
        int $sector = 0
    ): int {
        $this->db->execute(
            'INSERT INTO attack_logs
            (attacker_id, attacker_name, defender_id, defender_name, attack_type, result, damage_dealt, sector)
            VALUES (:attacker_id, :attacker_name, :defender_id, :defender_name, :attack_type, :result, :damage, :sector)',
            [
                'attacker_id' => $attackerId,
                'attacker_name' => $attackerName,
                'defender_id' => $defenderId,
                'defender_name' => $defenderName,
                'attack_type' => $attackType,
                'result' => $result,
                'damage' => $damageDealt,
                'sector' => $sector
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get attacks made by a player
     *
     * @param int $playerId Player's ship ID
     * @param int $limit Maximum results
     * @param int $offset Offset for pagination
     * @return array List of attack logs
     */
    public function getAttacksMadeBy(int $playerId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM attack_logs
            WHERE attacker_id = :player_id
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset',
            [
                'player_id' => $playerId,
                'limit' => $limit,
                'offset' => $offset
            ]
        );
    }

    /**
     * Get attacks received by a player
     *
     * @param int $playerId Player's ship ID
     * @param int $limit Maximum results
     * @param int $offset Offset for pagination
     * @return array List of attack logs
     */
    public function getAttacksReceivedBy(int $playerId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM attack_logs
            WHERE defender_id = :player_id
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset',
            [
                'player_id' => $playerId,
                'limit' => $limit,
                'offset' => $offset
            ]
        );
    }

    /**
     * Get recent attacks (both made and received) for a player
     *
     * @param int $playerId Player's ship ID
     * @param int $limit Maximum results
     * @return array List of attack logs
     */
    public function getRecentActivity(int $playerId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM attack_logs
            WHERE attacker_id = :player_id OR defender_id = :player_id
            ORDER BY timestamp DESC
            LIMIT :limit',
            [
                'player_id' => $playerId,
                'limit' => $limit
            ]
        );
    }

    /**
     * Get attack statistics for a player
     *
     * @param int $playerId Player's ship ID
     * @return array Attack statistics
     */
    public function getStatistics(int $playerId): array
    {
        // Attacks made
        $attacksMade = $this->db->fetch(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN result IN (\'success\', \'destroyed\') THEN 1 ELSE 0 END) as successful,
                SUM(damage_dealt) as total_damage
            FROM attack_logs
            WHERE attacker_id = :player_id',
            ['player_id' => $playerId]
        );

        // Attacks received
        $attacksReceived = $this->db->fetch(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN result = \'destroyed\' THEN 1 ELSE 0 END) as times_destroyed,
                SUM(damage_dealt) as total_damage_received
            FROM attack_logs
            WHERE defender_id = :player_id',
            ['player_id' => $playerId]
        );

        return [
            'attacks_made' => (int)($attacksMade['total'] ?? 0),
            'successful_attacks' => (int)($attacksMade['successful'] ?? 0),
            'total_damage_dealt' => (int)($attacksMade['total_damage'] ?? 0),
            'attacks_received' => (int)($attacksReceived['total'] ?? 0),
            'times_destroyed' => (int)($attacksReceived['times_destroyed'] ?? 0),
            'total_damage_received' => (int)($attacksReceived['total_damage_received'] ?? 0)
        ];
    }

    /**
     * Delete old attack logs (cleanup)
     *
     * @param int $daysOld Delete logs older than this many days
     * @return int Number of logs deleted
     */
    public function deleteOldLogs(int $daysOld = 30): int
    {
        $result = $this->db->execute(
            'DELETE FROM attack_logs WHERE timestamp < NOW() - INTERVAL \':days days\'',
            ['days' => $daysOld]
        );

        return $result;
    }

    /**
     * Count total logs for a player
     *
     * @param int $playerId Player's ship ID
     * @param string $type Type of logs (made, received, all)
     * @return int Total count
     */
    public function getLogCount(int $playerId, string $type = 'all'): int
    {
        $query = match($type) {
            'made' => 'SELECT COUNT(*) as count FROM attack_logs WHERE attacker_id = :player_id',
            'received' => 'SELECT COUNT(*) as count FROM attack_logs WHERE defender_id = :player_id',
            default => 'SELECT COUNT(*) as count FROM attack_logs WHERE attacker_id = :player_id OR defender_id = :player_id'
        };

        $result = $this->db->fetch($query, ['player_id' => $playerId]);
        return (int)($result['count'] ?? 0);
    }
}
