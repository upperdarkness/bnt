#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mark contraband black-market sectors in an existing universe (never FedSpace or starbases).
 *   php scripts/mark_blackmarkets.php [count]     # total desired; default contraband.markets_per_1000 scaled
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$db = new BNT\Core\Database($config);
$count = isset($argv[1]) ? (int)$argv[1] : null;
$added = BNT\Core\Services::create($config, $db)['contrabandService']->markMarkets($count);
echo "Marked $added new black-market sector(s).\n";
echo "Remember to set contraband.enabled (CONTRABAND_ENABLED=true) to open them for trade.\n";
