#!/usr/bin/env php
<?php

declare(strict_types=1);

// Check if running from CLI
if (PHP_SAPI !== 'cli') {
    die('This script can only be run from the command line.');
}

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;
use BNT\Core\Scheduler;
use BNT\Core\SchedulerTasks;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = new Database($config);

// Initialize scheduler
$scheduler = new Scheduler($db, $config);
$tasks = new SchedulerTasks($db, $config);

// Register all tasks
$scheduler->registerTask('turns', [$tasks, 'generateTurns'], $config['scheduler']['turns']);
$scheduler->registerTask('ports', [$tasks, 'portProduction'], $config['scheduler']['ports']);
$scheduler->registerTask('planets', [$tasks, 'planetProduction'], $config['scheduler']['planets']);
$scheduler->registerTask('igb', [$tasks, 'igbInterest'], $config['scheduler']['igb']);
$scheduler->registerTask('ranking', [$tasks, 'updateRankings'], $config['scheduler']['ranking']);
$scheduler->registerTask('news', [$tasks, 'generateNews'], $config['scheduler']['news']);
$scheduler->registerTask('degrade', [$tasks, 'degradeFighters'], $config['scheduler']['degrade']);
$scheduler->registerTask('cleanup', [$tasks, 'cleanup'], 60); // Run cleanup hourly

// Parse command line arguments
$options = getopt('', ['task:', 'force', 'status', 'help']);

// Show help
if (isset($options['help']) || (empty($options) && $argc === 1)) {
    echo <<<HELP
BlackNova Traders Scheduler
============================

Usage: php scheduler.php [OPTIONS]

Options:
  --status         Show status of all scheduled tasks
  --task=NAME      Run a specific task only
  --force          Force run task even if not due (requires --task)
  --help           Show this help message

Examples:
  php scheduler.php                    # Run all due tasks
  php scheduler.php --status           # Show task status
  php scheduler.php --task=turns       # Run only the turns task if due
  php scheduler.php --task=turns --force   # Force run turns task now

Available tasks: turns, ports, planets, igb, ranking, news, degrade, cleanup

HELP;
    exit(0);
}

// Show status
if (isset($options['status'])) {
    echo "Scheduler Status\n";
    echo "================\n\n";

    $status = $scheduler->getStatus();

    foreach ($status as $taskName => $info) {
        echo str_pad($taskName, 15) . " | ";
        echo "Interval: " . str_pad($info['interval'], 12) . " | ";
        echo "Last Run: " . str_pad($info['last_run'], 20) . " | ";
        echo "Next Run: " . $info['next_run_in'];

        if ($info['is_due']) {
            echo " [DUE NOW]";
        }

        echo "\n";
    }

    exit(0);
}

// Run specific task
if (isset($options['task'])) {
    $taskName = $options['task'];

    echo "Running task: $taskName\n";

    if (isset($options['force'])) {
        $result = $scheduler->forceRun($taskName);
    } else {
        $results = $scheduler->run();
        $result = $results[$taskName] ?? ['status' => 'error', 'error' => 'Task not found'];
    }

    echo "Status: {$result['status']}\n";

    if (isset($result['result'])) {
        echo "Result: {$result['result']}\n";
    }

    if (isset($result['duration'])) {
        echo "Duration: {$result['duration']}\n";
    }

    if (isset($result['error'])) {
        echo "Error: {$result['error']}\n";
        exit(1);
    }

    exit(0);
}

// Run all due tasks
echo "[" . date('Y-m-d H:i:s') . "] Running scheduler...\n";

$results = $scheduler->run();

foreach ($results as $taskName => $result) {
    $status = strtoupper($result['status']);
    $statusPadded = str_pad($status, 10);

    echo "[$statusPadded] $taskName";

    if ($result['status'] === 'success') {
        echo " - {$result['result']} ({$result['duration']})";
    } elseif ($result['status'] === 'error') {
        echo " - ERROR: {$result['error']}";
    } elseif ($result['status'] === 'skipped') {
        echo " - Next run in {$result['next_run_in']}";
    }

    echo "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Scheduler complete.\n";

exit(0);
