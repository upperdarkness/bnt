#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;

echo "Adding port_colonists column to universe table...\n";

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config);

try {
    $db->execute("ALTER TABLE universe ADD COLUMN IF NOT EXISTS port_colonists BIGINT DEFAULT 0");
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
