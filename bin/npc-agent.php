#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * LLM NPC worker. Long-running PHP 8.1 CLI process (systemd) or cron-every-minute with --once.
 *
 * Environment (never from the repository):
 *   OPENROUTER_API_KEY   required
 *   NPC_TOKENS_FILE      JSON map of ship_id => API token (mode 0600), written by scripts/npc_spawn.php
 *   NPC_API_BASE_URL     e.g. https://bnt.example.com/api/v1
 *   OPENROUTER_URL       override (tests use the mock server)
 *   DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS   control-plane database (wake queue, budgets, audit log)
 *   NPC_LOCK_FILE        lock file for --once (default /tmp/bnt-npc-agent.lock)
 *
 * Usage: php bin/npc-agent.php [--once] [--max-loops=N] [--poll=SECONDS]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\NpcAgent\ApiClient;
use BNT\NpcAgent\OpenRouterClient;
use BNT\NpcAgent\PromptGuard;
use BNT\NpcAgent\Prompts;
use BNT\NpcAgent\Store;
use BNT\NpcAgent\Tools;
use BNT\NpcAgent\Worker;

$opts = getopt('', ['once', 'max-loops::', 'poll::', 'help']);
if (isset($opts['help'])) {
    echo "Usage: php bin/npc-agent.php [--once] [--max-loops=N] [--poll=SECONDS]\n";
    exit(0);
}

$config = require __DIR__ . '/../config/config.php';
$npc = $config['npc'];

$apiKey = getenv('OPENROUTER_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "OPENROUTER_API_KEY is not set. Refusing to start.\n");
    exit(2);
}
$tokensFile = getenv('NPC_TOKENS_FILE') ?: '';
if ($tokensFile === '' || !is_readable($tokensFile)) {
    fwrite(STDERR, "NPC_TOKENS_FILE must point to a readable token file (see scripts/npc_spawn.php).\n");
    exit(2);
}
if ((fileperms($tokensFile) & 0077) !== 0) {
    fwrite(STDERR, "Warning: $tokensFile is readable by other users; chmod 600 it.\n");
}

// Cron mode: a lock file keeps overlapping runs from stacking up.
$lock = null;
if (isset($opts['once'])) {
    $lock = fopen(getenv('NPC_LOCK_FILE') ?: sys_get_temp_dir() . '/bnt-npc-agent.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another worker run holds the lock; exiting.\n");
        exit(0);
    }
}

$prompts = new Prompts($npc['prompt_dir']);
$store = new Store(Store::connect($config['database']), $npc);
$worker = new Worker(
    $store,
    new ApiClient(getenv('NPC_API_BASE_URL') ?: $npc['api_base_url']),
    new OpenRouterClient(getenv('OPENROUTER_URL') ?: $npc['openrouter_url'], $apiKey, $npc['price_per_million'] ?? ['input' => 3.0, 'output' => 15.0]),
    new Tools(new ApiClient(getenv('NPC_API_BASE_URL') ?: $npc['api_base_url']), new PromptGuard($prompts->allText())),
    $prompts,
    $npc,
    $tokensFile,
    static function (string $line): void {
        fwrite(STDOUT, $line . "\n"); // stdout for journald
    }
);

$worker->setContentWorker(new \BNT\NpcAgent\ContentWorker(
    $store,
    new ApiClient(getenv('NPC_API_BASE_URL') ?: $npc['api_base_url']),
    new OpenRouterClient(getenv('OPENROUTER_URL') ?: $npc['openrouter_url'], $apiKey, $npc['price_per_million'] ?? ['input' => 3.0, 'output' => 15.0]),
    $config,
    $tokensFile,
    $npc['prompt_dir'],
    static function (string $line): void {
        fwrite(STDOUT, $line . "\n");
    }
));

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, static fn() => $worker->stop());
    }
}

fwrite(STDOUT, date('c') . " npc-agent started\n");
$maxLoops = isset($opts['once']) ? 1 : (isset($opts['max-loops']) ? (int)$opts['max-loops'] : null);
$worker->run($maxLoops, (int)($opts['poll'] ?? getenv('NPC_POLL_SECONDS') ?: 5));
fwrite(STDOUT, date('c') . " npc-agent stopped\n");
