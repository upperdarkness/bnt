<?php

declare(strict_types=1);

namespace BNT\Models;

class Universe extends Model
{
    protected string $table = 'universe';
    protected string $primaryKey = 'sector_id';

    public function getSector(int $sectorId): ?array
    {
        return $this->find($sectorId);
    }

    public function getLinkedSectors(int $sectorId): array
    {
        $sql = "SELECT u.sector_id, u.sector_name, u.port_type, u.beacon, u.is_starbase
                FROM universe u
                INNER JOIN links l ON u.sector_id = l.link_dest
                WHERE l.link_start = :sector_id
                ORDER BY l.link_dest ASC";

        return $this->db->fetchAll($sql, ['sector_id' => $sectorId]);
    }

    public function createLink(int $fromSector, int $toSector): bool
    {
        // Create bidirectional link
        $this->db->execute(
            'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
            ['start' => $fromSector, 'dest' => $toSector]
        );

        return $this->db->execute(
            'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
            ['start' => $toSector, 'dest' => $fromSector]
        );
    }

    public function deleteLink(int $fromSector, int $toSector): bool
    {
        $this->db->execute(
            'DELETE FROM links WHERE link_start = :start AND link_dest = :dest',
            ['start' => $fromSector, 'dest' => $toSector]
        );

        return $this->db->execute(
            'DELETE FROM links WHERE link_start = :start AND link_dest = :dest',
            ['start' => $toSector, 'dest' => $fromSector]
        );
    }

    public function isLinked(int $sector1, int $sector2): bool
    {
        $sql = "SELECT COUNT(*) as count FROM links
                WHERE link_start = :start AND link_dest = :dest";

        $result = $this->db->fetchOne($sql, [
            'start' => $sector1,
            'dest' => $sector2
        ]);

        return $result && $result['count'] > 0;
    }

    public function getSectorCount(): int
    {
        $result = $this->db->fetchOne("SELECT COUNT(*) as count FROM {$this->table}");
        return (int)($result['count'] ?? 0);
    }

    public function createSector(string $name = '', string $portType = 'none', int $zoneId = 1): int
    {
        return $this->create([
            'sector_name' => $name,
            'port_type' => $portType,
            'zone_id' => $zoneId,
        ]);
    }

    /**
     * Check if a sector is a starbase
     */
    public function isStarbase(int $sectorId): bool
    {
        $sector = $this->getSector($sectorId);
        return $sector && ($sector['is_starbase'] ?? false);
    }

    /**
     * Get a random sector ID (excluding the current sector)
     */
    public function getRandomSector(int $excludeSectorId = 0): ?int
    {
        $sql = "SELECT sector_id FROM universe";
        $params = [];
        
        if ($excludeSectorId > 0) {
            $sql .= " WHERE sector_id != :exclude";
            $params['exclude'] = $excludeSectorId;
        }
        
        $sql .= " ORDER BY RANDOM() LIMIT 1";
        
        $result = $this->db->fetchOne($sql, $params);
        return $result ? (int)$result['sector_id'] : null;
    }
}
