#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;

echo "Marking random ports as starbases...\n";

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config);

$starbasePercentage = $config['game']['starbase_percentage'] ?? 5.0;

try {
    // Get all ports (non-none port types)
    $ports = $db->fetchAll(
        "SELECT sector_id, port_type FROM universe WHERE port_type != 'none' AND sector_id != 1 ORDER BY sector_id"
    );
    
    $totalPorts = count($ports);
    $targetStarbases = (int)ceil($totalPorts * ($starbasePercentage / 100));
    
    echo "Total ports: $totalPorts\n";
    echo "Target starbases (excluding Sector 1): $targetStarbases\n";
    
    // Count existing starbases (excluding Sector 1)
    $existing = $db->fetchOne(
        "SELECT COUNT(*) as count FROM universe WHERE is_starbase = TRUE AND sector_id != 1"
    );
    $existingCount = (int)($existing['count'] ?? 0);
    
    echo "Existing starbases (excluding Sector 1): $existingCount\n";
    
    if ($existingCount >= $targetStarbases) {
        echo "Already have enough starbases!\n";
        exit(0);
    }
    
    $needed = $targetStarbases - $existingCount;
    echo "Need to mark $needed more ports as starbases\n";
    
    // Shuffle ports and mark random ones as starbases
    shuffle($ports);
    $marked = 0;
    
    foreach ($ports as $port) {
        if ($marked >= $needed) {
            break;
        }
        
        // Check if already a starbase
        $check = $db->fetchOne(
            "SELECT is_starbase FROM universe WHERE sector_id = :id",
            ['id' => $port['sector_id']]
        );
        
        if (!($check['is_starbase'] ?? false)) {
            $db->execute(
                "UPDATE universe SET is_starbase = TRUE WHERE sector_id = :id",
                ['id' => $port['sector_id']]
            );
            $marked++;
            echo "Marked Sector {$port['sector_id']} ({$port['port_type']} port) as starbase\n";
        }
    }
    
    echo "\nMarked $marked ports as starbases!\n";
    echo "Total starbases now: " . ($existingCount + $marked + 1) . " (including Sector 1)\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
