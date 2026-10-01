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

// Load config for starbase percentage
$config = require __DIR__ . '/../config/config.php';
$starbasePercentage = $config['game']['starbase_percentage'] ?? 5.0;

// Create sectors
$portTypes = ['none', 'none', 'none', 'ore', 'organics', 'goods', 'energy'];
$sectorIds = [];
$portsCreated = 0; // Track ports (non-none) for starbase assignment

// Check if Sector 1 already exists (starbase), start from sector 2 if so
$existingSector1 = $db->query("SELECT sector_id FROM universe WHERE sector_id = 1", [])->fetch();
$startFrom = $existingSector1 ? 2 : 1;
if ($existingSector1) {
    $sectorIds[] = 1; // Include existing Sector 1 in our list
    echo "  Sector 1 (Starbase) already exists, starting from Sector 2...\n";
}

for ($i = $startFrom; $i <= $numSectors; $i++) {
    $portType = $portTypes[array_rand($portTypes)];

    $initialInventory = [
        'ore' => ($portType === 'ore') ? 150000 : random_int(50000, 100000),
        'organics' => ($portType === 'organics') ? 150000 : random_int(50000, 100000),
        'goods' => ($portType === 'goods') ? 150000 : random_int(50000, 100000),
        'energy' => ($portType === 'energy') ? 150000 : random_int(50000, 100000),
    ];

    // Ports start with some colonists
    $portColonists = ($portType !== 'none') ? random_int(5000, 50000) : 0;

    // Determine if this should be a starbase
    // Sector 1 is always a starbase, others are randomly assigned based on percentage
    $isStarbase = false;
    if ($i == 1) {
        $isStarbase = true; // Sector 1 is always a starbase
    } elseif ($portType !== 'none') {
        // Only ports (not empty sectors) can be starbases
        $portsCreated++;
        // Calculate if this port should be a starbase based on percentage
        // We want approximately X% of ports to be starbases
        $targetStarbases = (int)ceil($portsCreated * ($starbasePercentage / 100));
        $currentStarbases = $db->query("SELECT COUNT(*) as count FROM universe WHERE is_starbase = TRUE AND sector_id > 1", [])->fetch()['count'] ?? 0;
        
        if ($currentStarbases < $targetStarbases) {
            // Random chance based on remaining needed starbases
            $remainingNeeded = $targetStarbases - $currentStarbases;
            $remainingPorts = max(1, ($numSectors - $i) / 4); // Approximate remaining ports (1/4 are ports)
            $chance = min(1.0, $remainingNeeded / max(1, $remainingPorts));
            $isStarbase = (mt_rand() / mt_getrandmax()) < $chance;
        }
    }

    $sql = "INSERT INTO universe (sector_name, port_type, port_ore, port_organics, port_goods, port_energy, port_colonists, is_starbase, zone_id)
            VALUES (:name, :port_type, :ore, :organics, :goods, :energy, :colonists, :is_starbase, :zone)
            RETURNING sector_id";

    $result = $db->query($sql, [
        'name' => "Sector $i",
        'port_type' => $portType,
        'ore' => $initialInventory['ore'],
        'organics' => $initialInventory['organics'],
        'goods' => $initialInventory['goods'],
        'energy' => $initialInventory['energy'],
        'colonists' => $portColonists,
        'is_starbase' => $isStarbase,
        'zone' => 1,
    ]);

    $row = $result->fetch();
    $sectorIds[] = (int)$row['sector_id'];

    if ($i % 100 === 0) {
        echo "Created $i sectors...\n";
    }
}

echo "Creating links between sectors...\n";

// First, create a path that connects all sectors to ensure reachability
// This creates a chain: 1->2->3->...->n, ensuring all sectors are connected
echo "  Creating base connectivity path...\n";
for ($i = 0; $i < count($sectorIds) - 1; $i++) {
    $currentSector = $sectorIds[$i];
    $nextSector = $sectorIds[$i + 1];
    
    // Create bidirectional link
    $db->execute(
        'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
        ['start' => $currentSector, 'dest' => $nextSector]
    );
    $db->execute(
        'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
        ['start' => $nextSector, 'dest' => $currentSector]
    );
}

// Also create a loop back from last to first for better connectivity
$db->execute(
    'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
    ['start' => $sectorIds[count($sectorIds) - 1], 'dest' => $sectorIds[0]]
);
$db->execute(
    'INSERT INTO links (link_start, link_dest) VALUES (:start, :dest) ON CONFLICT DO NOTHING',
    ['start' => $sectorIds[0], 'dest' => $sectorIds[count($sectorIds) - 1]]
);

// Now add additional random links for variety (2-5 per sector)
// This creates shortcuts and alternative routes
echo "  Adding additional random links for variety...\n";
foreach ($sectorIds as $sectorId) {
    $numLinks = random_int(2, 5);
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

// Sector 1 and its neighbours are the default Federation zone (zone 2, is_federation = true)
echo "  Marking Federation space around sector 1...\n";
$hasFederationFlag = (bool)$db->fetchOne("SELECT 1 AS x FROM information_schema.columns WHERE table_name = 'zones' AND column_name = 'is_federation'");
if ($hasFederationFlag) {
    $db->execute('UPDATE zones SET is_federation = TRUE WHERE zone_id = 2');
} else {
    echo "  (zones.is_federation is missing: apply database/migrations/add_alignment_npcs.sql to enable FedSpace rules)\n";
}
$db->execute('UPDATE universe SET zone_id = 2 WHERE sector_id = 1 OR sector_id IN (SELECT link_dest FROM links WHERE link_start = 1)');

// Home territories for NPC factions: a Free Trade Zone (3) and a War Zone / Xenobe Reach (4),
// each grown as a connected cluster of roughly 8% of the universe.
echo "  Carving Free Trade Zone and War Zone clusters...\n";
$adjacency = [];
foreach ($db->fetchAll('SELECT link_start, link_dest FROM links') as $row) {
    $adjacency[(int)$row['link_start']][] = (int)$row['link_dest'];
}
$taken = array_flip(array_map('intval', array_column($db->fetchAll('SELECT sector_id FROM universe WHERE zone_id = 2'), 'sector_id')));
foreach ([3, 4] as $zoneId) {
    $target = max(3, (int)round(count($sectorIds) * 0.08));
    $candidates = array_values(array_diff($sectorIds, array_keys($taken)));
    if (!$candidates) {
        break;
    }
    $queue = [$candidates[array_rand($candidates)]];
    $members = [];
    while ($queue && count($members) < $target) {
        $node = array_shift($queue);
        if (isset($taken[$node]) || isset($members[$node])) {
            continue;
        }
        $members[$node] = true;
        foreach ($adjacency[$node] ?? [] as $n) {
            $queue[] = $n;
        }
    }
    if ($members) {
        $db->execute('UPDATE universe SET zone_id = ' . (int)$zoneId . ' WHERE is_starbase = FALSE AND sector_id = ANY(CAST(:ids AS INT[]))',
            ['ids' => '{' . implode(',', array_keys($members)) . '}']);
        $taken += $members;
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
        'owner' => null,
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
