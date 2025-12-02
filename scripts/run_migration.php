#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;

echo "Running starbase migration...\n";

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config);

try {
    // Add is_starbase column
    echo "Adding is_starbase column...\n";
    $db->execute("ALTER TABLE universe ADD COLUMN IF NOT EXISTS is_starbase BOOLEAN DEFAULT FALSE");
    
    // Mark Sector 1 as starbase
    echo "Marking Sector 1 as starbase...\n";
    $db->execute("UPDATE universe SET is_starbase = TRUE WHERE sector_id = 1");
    
    // Create index
    echo "Creating index...\n";
    $db->execute("CREATE INDEX IF NOT EXISTS idx_universe_starbase ON universe(is_starbase) WHERE is_starbase = TRUE");
    
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
