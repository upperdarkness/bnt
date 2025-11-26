#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;
use BNT\Models\Universe;
use BNT\Models\Planet;

echo "BlackNova Traders - Universe Generator\n";
echo "======================================\n\n";

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config);
$universeModel = new Universe($db);
$planetModel = new Planet($db);

$numSectors = (int)($argv[1] ?? 1000);
$numPlanets = (int)($argv[2] ?? 200);

echo "Creating $numSectors sectors...\n";

// Create sectors
$portTypes = ['none', 'none', 'none', 'ore', 'organics', 'goods', 'energy'];
$sectorIds = [];

for ($i = 1; $i <= $numSectors; $i++) {
    $portType = $portTypes[array_rand($portTypes)];

    $initialInventory = [
        'ore' => ($portType === 'ore') ? 150000 : random_int(50000, 100000),
        'organics' => ($portType === 'organics') ? 150000 : random_int(50000, 100000),
        'goods' => ($portType === 'goods') ? 150000 : random_int(50000, 100000),
        'energy' => ($portType === 'energy') ? 150000 : random_int(50000, 100000),
    ];

    $sql = "INSERT INTO universe (sector_name, port_type, port_ore, port_organics, port_goods, port_energy, zone_id)
            VALUES (:name, :port_type, :ore, :organics, :goods, :energy, :zone)
            RETURNING sector_id";

    $result = $db->query($sql, [
        'name' => "Sector $i",
        'port_type' => $portType,
        'ore' => $initialInventory['ore'],
        'organics' => $initialInventory['organics'],
        'goods' => $initialInventory['goods'],
        'energy' => $initialInventory['energy'],
        'zone' => 1,
    ]);

    $row = $result->fetch();
    $sectorIds[] = (int)$row['sector_id'];

    if ($i % 100 === 0) {
        echo "Created $i sectors...\n";
    }
}

echo "Creating links between sectors...\n";

// Create links - each sector connected to 3-7 random sectors
foreach ($sectorIds as $sectorId) {
    $numLinks = random_int(3, 7);
    $linkedTo = [];

    for ($j = 0; $j < $numLinks; $j++) {
        do {
            $targetSector = $sectorIds[array_rand($sectorIds)];
        } while ($targetSector === $sectorId || in_array($targetSector, $linkedTo));

        $linkedTo[] = $targetSector;

        // Create bidirectional link
        $db->execute(
            'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
            ['start' => $sectorId, 'dest' => $targetSector]
        );
        $db->execute(
            'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
            ['start' => $targetSector, 'dest' => $sectorId]
        );
    }
}

echo "Creating $numPlanets planets...\n";

// Create planets
$planetNames = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta', 'Iota', 'Kappa'];
$suffixes = ['Prime', 'II', 'III', 'IV', 'V', 'Minor', 'Major', 'Centauri', 'Proxima'];

for ($i = 0; $i < $numPlanets; $i++) {
    $sectorId = $sectorIds[array_rand($sectorIds)];
    $name = $planetNames[array_rand($planetNames)] . ' ' . $suffixes[array_rand($suffixes)] . ' ' . random_int(1, 999);

    $planetModel->create([
        'planet_name' => $name,
        'sector_id' => $sectorId,
        'owner' => 0,
        'organics' => random_int(10000, 50000),
        'ore' => random_int(10000, 50000),
        'goods' => random_int(10000, 50000),
        'energy' => random_int(10000, 50000),
        'colonists' => random_int(10000, 100000),
    ]);

    if (($i + 1) % 50 === 0) {
        echo "Created " . ($i + 1) . " planets...\n";
    }
}

echo "\nUniverse creation complete!\n";
echo "Created:\n";
echo "  - $numSectors sectors\n";
echo "  - $numPlanets planets\n";
echo "  - Links between all sectors\n";
