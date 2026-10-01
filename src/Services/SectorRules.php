<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/** Sector classification used by every combat/defence path. */
class SectorRules
{
    private array $cache = [];

    public function __construct(private Database $db) {}

    /** @return array{starbase: bool, federation: bool} */
    public function flags(int $sectorId): array
    {
        if (!isset($this->cache[$sectorId])) {
            $row = $this->db->fetchOne(
                'SELECT u.is_starbase, COALESCE(z.is_federation, FALSE) AS is_federation
                 FROM universe u LEFT JOIN zones z ON z.zone_id = u.zone_id
                 WHERE u.sector_id = :id',
                ['id' => $sectorId]
            );
            $this->cache[$sectorId] = [
                'starbase' => (bool)($row['is_starbase'] ?? false),
                'federation' => (bool)($row['is_federation'] ?? false),
            ];
        }
        return $this->cache[$sectorId];
    }

    public function isStarbase(int $sectorId): bool
    {
        return $this->flags($sectorId)['starbase'];
    }

    public function isFederation(int $sectorId): bool
    {
        return $this->flags($sectorId)['federation'];
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
