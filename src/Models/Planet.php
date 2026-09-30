<?php

declare(strict_types=1);

namespace BNT\Models;

class Planet extends Model
{
    protected string $table = 'planets';
    protected string $primaryKey = 'planet_id';

    public function getPlanetsInSector(int $sectorId): array
    {
        $sql = "SELECT p.*, s.character_name as owner_name
                FROM {$this->table} p
                LEFT JOIN ships s ON p.owner = s.ship_id
                WHERE p.sector_id = :sector_id
                ORDER BY p.planet_name";

        return $this->db->fetchAll($sql, ['sector_id' => $sectorId]);
    }

    public function getPlayerPlanets(int $shipId): array
    {
        $sql = "SELECT p.*, u.sector_id
                FROM {$this->table} p
                JOIN universe u ON p.sector_id = u.sector_id
                WHERE p.owner = :ship_id
                ORDER BY p.planet_name";

        return $this->db->fetchAll($sql, ['ship_id' => $shipId]);
    }

    public function createPlanet(string $name, int $sectorId, int $owner = 0): int
    {
        // Use NULL for unowned planets (owner = 0) to satisfy foreign key constraint
        // The foreign key requires owner to reference an existing ship_id
        $ownerValue = ($owner > 0) ? $owner : null;
        
        return $this->create([
            'planet_name' => $name,
            'sector_id' => $sectorId,
            'owner' => $ownerValue,
            'organics' => random_int(0, 10000),
            'ore' => random_int(0, 10000),
            'goods' => random_int(0, 10000),
            'energy' => random_int(0, 10000),
            'colonists' => random_int(100, 10000),
        ]);
    }

    public function capture(int $planetId, int $newOwner): bool
    {
        return $this->update($planetId, [
            'owner' => $newOwner,
            'defeated' => false,
        ]);
    }

    public function destroy(int $planetId): bool
    {
        return $this->delete($planetId);
    }

}
