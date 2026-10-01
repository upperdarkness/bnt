#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test runner: php tests/run.php [FilterSubstring]
 *
 * Pure unit tests need nothing. Database tests need PostgreSQL client tools on PATH
 * (or PG_BIN) and the usual PGHOST/PGPORT/PGUSER/PGPASSWORD variables; they create and drop
 * their own databases and are skipped (not failed) when PostgreSQL is unavailable.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/php/TestCase.php';
require_once __DIR__ . '/php/DbTestCase.php';
require_once __DIR__ . '/php/HttpServers.php';
require_once __DIR__ . '/php/WorkerHarness.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/php/*Test.php') ?: [];
sort($files);

$pass = $fail = $skip = $assertions = 0;
$failures = [];

foreach ($files as $file) {
    require_once $file;
    $short = basename($file, '.php');
    if ($filter !== '' && stripos($short, $filter) === false) {
        continue;
    }
    $class = 'BNT\\Tests\\' . $short;
    $methods = array_filter(get_class_methods($class), static fn($m) => str_starts_with($m, 'test'));
    echo "\n$short\n";
    try {
        $class::setUpBeforeClass();
    } catch (Throwable $e) {
        echo "  ERROR in setUpBeforeClass: {$e->getMessage()}\n";
        $fail++;
        $failures[] = [$short, 'setUpBeforeClass', $e];
        continue;
    }
    $probe = new $class();
    if ($reason = $probe->skipReason()) {
        echo "  SKIPPED: $reason\n";
        $skip += count($methods);
        $class::tearDownAfterClass();
        continue;
    }
    foreach ($methods as $method) {
        $t = new $class();
        $start = microtime(true);
        try {
            $t->setUp();
            $t->$method();
            $t->tearDown();
            $pass++;
            printf("  \033[32m✓\033[0m %s (%dms)\n", $method, (microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            $fail++;
            $failures[] = [$short, $method, $e];
            printf("  \033[31m✗\033[0m %s\n      %s\n", $method, strtok($e->getMessage(), "\n"));
        }
        $assertions += $t->assertions;
    }
    $class::tearDownAfterClass();
}

echo "\n";
foreach ($failures as [$c, $m, $e]) {
    echo "FAIL $c::$m\n  " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $trace = array_filter(explode("\n", $e->getTraceAsString()), static fn($l) => !str_contains($l, 'tests/run.php') && !str_contains($l, 'TestCase.php'));
    echo '  ' . implode("\n  ", array_slice($trace, 0, 5)) . "\n\n";
}
printf("%d passed, %d failed, %d skipped, %d assertions\n", $pass, $fail, $skip, $assertions);
exit($fail > 0 ? 1 : 0);
