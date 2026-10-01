<?php
declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;
use BNT\Services\AlignmentRules;

class AttackLog
{
    public function __construct(private Database $db, private ?AlignmentRules $rules = null) {}

    private function tierOf(?int $shipId): ?string
    {
        if ($this->rules === null || !$shipId || !$this->rules->config('enabled', true)) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT alignment FROM ships WHERE ship_id = :id', ['id' => $shipId]);
        return $row ? $this->rules->label((int)$row['alignment']) : null;
    }

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
            (attacker_id, attacker_name, defender_id, defender_name, attack_type, result, damage_dealt, sector, attacker_tier, defender_tier)
            VALUES (:attacker_id, :attacker_name, :defender_id, :defender_name, :attack_type, :result, :damage, :sector, :atier, :dtier)',
            [
                'attacker_id' => $attackerId,
                'attacker_name' => $attackerName,
                'defender_id' => $defenderId,
                'defender_name' => $defenderName,
                'attack_type' => $attackType,
                'result' => $result,
                'damage' => $damageDealt,
                'sector' => $sector,
                'atier' => $this->tierOf($attackerId),
                'dtier' => $this->tierOf($defenderId),
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
        // PDO requires LIMIT/OFFSET to be integers, so we cast them in the query
        $sql = 'SELECT * FROM attack_logs
            WHERE attacker_id = :player_id
            ORDER BY timestamp DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        
        return $this->db->fetchAll($sql, ['player_id' => $playerId]);
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
        // PDO requires LIMIT/OFFSET to be integers, so we cast them in the query
        $sql = 'SELECT * FROM attack_logs
            WHERE defender_id = :player_id
            ORDER BY timestamp DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        
        return $this->db->fetchAll($sql, ['player_id' => $playerId]);
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
        // PDO requires LIMIT to be an integer, so we cast it in the query
        $sql = 'SELECT * FROM attack_logs
            WHERE attacker_id = :player_id OR defender_id = :player_id
            ORDER BY timestamp DESC
            LIMIT ' . (int)$limit;
        
        return $this->db->fetchAll($sql, ['player_id' => $playerId]);
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
        $attacksMade = $this->db->fetchOne(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN result IN (\'success\', \'destroyed\') THEN 1 ELSE 0 END) as successful,
                SUM(damage_dealt) as total_damage
            FROM attack_logs
            WHERE attacker_id = :player_id',
            ['player_id' => $playerId]
        ) ?? [];

        // Attacks received
        $attacksReceived = $this->db->fetchOne(
            'SELECT
                COUNT(*) as total,
                SUM(CASE WHEN result = \'destroyed\' THEN 1 ELSE 0 END) as times_destroyed,
                SUM(damage_dealt) as total_damage_received
            FROM attack_logs
            WHERE defender_id = :player_id',
            ['player_id' => $playerId]
        ) ?? [];

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

        $result = $this->db->fetchOne($query, ['player_id' => $playerId]);
        return (int)($result['count'] ?? 0);
    }
}
