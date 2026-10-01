#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Create NPC accounts (never through registration) and issue their API tokens.
 *
 *   php scripts/npc_spawn.php --faction=free --count=2 [--controller=llm] [--model=provider/model]
 *                             [--name="Kraal Vesh"] [--token-file=/etc/bnt/npc_tokens.json]
 *   php scripts/npc_spawn.php --rotate-tokens --token-file=/etc/bnt/npc_tokens.json
 *
 * Tokens are written ONLY to the token file (mode 0600), a JSON map of ship_id => token
 * that the worker reads. The database stores only SHA-256 hashes, so a lost token must be re-issued.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\ApiAuth;
use BNT\Core\Database;
use BNT\Models\Ship;
use BNT\Services\AlignmentRules;
use BNT\Services\AlignmentService;
use BNT\Services\BountyService;
use BNT\Services\NpcService;
use BNT\Services\SectorRules;

$opts = getopt('', ['faction:', 'count:', 'controller:', 'model:', 'name:', 'token-file:', 'rotate-tokens', 'help']);
if (isset($opts['help']) || (!isset($opts['faction']) && !isset($opts['rotate-tokens']))) {
    fwrite(STDERR, "Usage: php scripts/npc_spawn.php --faction=<police|guild|xenobe|free> [--count=N] [--controller=scripted|llm]\n"
        . "         [--model=ID] [--name=NAME] [--token-file=PATH]\n"
        . "       php scripts/npc_spawn.php --rotate-tokens --token-file=PATH\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$config = require __DIR__ . '/../config/config.php';
$db = new Database($config);
$rules = new AlignmentRules($config);
$alignment = new AlignmentService($db, $rules, new BountyService($db, $rules), $config);
$apiAuth = new ApiAuth($db, new Ship($db));
$npcs = new NpcService($db, $alignment, $apiAuth, new SectorRules($db), $config);

$tokenFile = $opts['token-file'] ?? getenv('NPC_TOKENS_FILE') ?: null;

function loadTokens(?string $file): array
{
    if ($file && is_readable($file)) {
        return json_decode((string)file_get_contents($file), true) ?: [];
    }
    return [];
}

function saveTokens(?string $file, array $tokens): void
{
    if (!$file) {
        fwrite(STDERR, "WARNING: no --token-file given; tokens cannot be shown again. Re-run with --rotate-tokens to re-issue.\n");
        return;
    }
    $old = umask(0177);
    file_put_contents($file, json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
    umask($old);
    @chmod($file, 0600);
}

if (isset($opts['rotate-tokens'])) {
    $tokens = loadTokens($tokenFile);
    foreach ($db->fetchAll("SELECT ship_id FROM npc_profiles WHERE controller = 'llm'") as $row) {
        $t = $npcs->issueToken((int)$row['ship_id']);
        $tokens[(string)$row['ship_id']] = $t['token'];
        echo "Rotated token for ship {$row['ship_id']} (expires {$t['expires_at']})\n";
    }
    saveTokens($tokenFile, $tokens);
    exit(0);
}

$faction = (string)$opts['faction'];
$count = max(1, (int)($opts['count'] ?? 1));
$controller = $opts['controller'] ?? 'scripted';
if (!in_array($controller, ['scripted', 'llm'], true)) {
    fwrite(STDERR, "--controller must be 'scripted' or 'llm'\n");
    exit(1);
}
$tokens = loadTokens($tokenFile);
for ($i = 0; $i < $count; $i++) {
    $spawnOpts = ['controller' => $controller];
    if (isset($opts['model'])) {
        $spawnOpts['model'] = $opts['model'];
    }
    if (isset($opts['name']) && $count === 1) {
        $spawnOpts['name'] = $opts['name'];
    }
    $npc = $npcs->spawn($faction, $spawnOpts);
    echo "Created {$npc['name']} (ship {$npc['ship_id']}, {$npc['email']})\n";
    if ($controller === 'llm' || $tokenFile) {
        $t = $npcs->issueToken($npc['ship_id']);
        $tokens[(string)$npc['ship_id']] = $t['token'];
        echo "  token issued, expires {$t['expires_at']}\n";
    }
}
if ($tokens) {
    saveTokens($tokenFile, $tokens);
}
