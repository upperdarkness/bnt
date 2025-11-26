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
        return $this->create([
            'planet_name' => $name,
            'sector_id' => $sectorId,
            'owner' => $owner,
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

    public function updateProduction(int $planetId): bool
    {
        $planet = $this->find($planetId);
        if (!$planet) {
            return false;
        }

        // Calculate production based on colonists and percentages
        $productionRate = 0.005;
        $totalProduction = $planet['colonists'] * $productionRate;

        $newOre = $planet['ore'] + ($totalProduction * $planet['prod_ore'] / 100);
        $newOrganics = $planet['organics'] + ($totalProduction * $planet['prod_organics'] / 100);
        $newGoods = $planet['goods'] + ($totalProduction * $planet['prod_goods'] / 100);
        $newEnergy = $planet['energy'] + ($totalProduction * $planet['prod_energy'] / 100);
        $newFighters = $planet['fighters'] + (int)($totalProduction * $planet['prod_fighters'] / 100 / 50);
        $newTorps = $planet['torps'] + (int)($totalProduction * $planet['prod_torp'] / 100 / 25);

        // Colonist reproduction
        $reproductionRate = 0.0005;
        $newColonists = (int)($planet['colonists'] * (1 + $reproductionRate));

        // Organics consumption
        $consumption = $planet['colonists'] * 0.05;
        $newOrganics -= $consumption;

        // Starvation if no organics
        if ($newOrganics < 0) {
            $newColonists = (int)($newColonists * 0.99);
            $newOrganics = 0;
        }

        return $this->update($planetId, [
            'ore' => (int)$newOre,
            'organics' => (int)max(0, $newOrganics),
            'goods' => (int)$newGoods,
            'energy' => (int)$newEnergy,
            'fighters' => $newFighters,
            'torps' => $newTorps,
            'colonists' => $newColonists,
        ]);
    }
}
