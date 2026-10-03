#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * NPC simulation harness.
 *
 * Builds a throw-away database with a 1,000-sector universe, spawns the default NPC population
 * plus a few chaotic "human" bots, then runs the scheduler tasks with the clock fast-forwarded
 * (default: 7 game days, one 2-minute scheduler cycle per iteration). Reports scripted tick times,
 * NPC deaths, credits generated and any rule violations found by invariant checks.
 *
 *   php scripts/npc_simulate.php [--sectors=1000] [--days=7] [--bots=30] [--tick-minutes=2]
 *                                [--seed=N] [--keep] [--db=NAME] [--json]
 *
 * Needs a PostgreSQL role that may CREATE DATABASE (same DB_* environment as the game).
 * Exit status is non-zero if any rule violation was found or a scripted tick exceeded 200 ms.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BNT\Core\Database;
use BNT\Core\SchedulerTasks;
use BNT\Core\Services;

$opts = getopt('', ['sectors::', 'days::', 'bots::', 'tick-minutes::', 'seed::', 'keep', 'db::', 'json', 'help']);
if (isset($opts['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 1400) . "\n";
    exit(0);
}
$sectors = (int)($opts['sectors'] ?? 1000);
$days = (float)($opts['days'] ?? 7);
$botCount = (int)($opts['bots'] ?? 30);
$tickMinutes = max(1, (int)($opts['tick-minutes'] ?? 2));
$seed = (int)($opts['seed'] ?? 12345);
mt_srand($seed);
srand($seed);

$root = dirname(__DIR__);
$config = require $root . '/config/config.php';
$config['contraband']['enabled'] = true;   // exercise the contraband economy in the simulation
$base = $config['database'];

// ------------------------------------------------------------------ scratch database
$dbName = $opts['db'] ?? 'bnt_sim_' . bin2hex(random_bytes(4));
$dsn = static fn(string $name) => sprintf('pgsql:host=%s;port=%d;dbname=%s', $base['host'], $base['port'], $name);
$admin = new PDO($dsn('postgres'), $base['username'], $base['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE ' . $dbName);
$pdo = new PDO($dsn($dbName), $base['username'], $base['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$files = [$root . '/database/schema.sql'];
foreach (['add_api_tokens', 'add_attack_logs', 'add_planet_economy_news', 'add_port_colonists', 'add_scheduler', 'add_ship_types',
    'add_skills', 'add_starbases', 'fix_database_setup', 'fix_planet_owner_nullable', 'add_alignment_npcs', 'add_contraband', 'add_protection_news_rumours'] as $m) {
    $files[] = $root . "/database/migrations/$m.sql";
}
foreach ($files as $f) {
    try {
        $pdo->exec((string)file_get_contents($f));
    } catch (PDOException $e) {
        // Same tolerance as the project's own setup: some migrations repeat objects the schema already has.
    }
}
unset($pdo);

putenv("DB_NAME=$dbName");
$config['database']['database'] = $dbName;
$config['npc']['graph_cache_path'] = sys_get_temp_dir() . "/bnt_sim_graph_$dbName.json";
@unlink($config['npc']['graph_cache_path']);

fwrite(STDERR, "Creating a $sectors-sector universe in $dbName...\n");
$cmd = sprintf('DB_NAME=%s DB_HOST=%s DB_PORT=%d DB_USER=%s DB_PASS=%s %s %s %d 200 2>&1',
    escapeshellarg($dbName), escapeshellarg($base['host']), $base['port'], escapeshellarg($base['username']),
    escapeshellarg($base['password']), escapeshellarg(PHP_BINARY), escapeshellarg($root . '/scripts/create_universe.php'), $sectors);
exec($cmd, $out, $rc);
if ($rc !== 0) {
    fwrite(STDERR, implode("\n", $out) . "\nuniverse generation failed\n");
    exit(2);
}

$ref = new ReflectionProperty(Database::class, 'connection');
$ref->setValue(null, null);
$db = new Database($config);
$svc = Services::create($config, $db);
$schedTasks = new SchedulerTasks($db, $config);
$npcTasks = $svc['npcTasks'];
/** @var BNT\Services\CombatService $combat */
$combat = $svc['combatService'];
/** @var BNT\Services\MovementService $movement */
$movement = $svc['movementService'];
/** @var BNT\Services\TradeService $trade */
$trade = $svc['tradeService'];
/** @var BNT\Models\Ship $shipModel */
$shipModel = $svc['shipModel'];
$graph = $svc['sectorGraph'];
$alignment = $svc['alignmentService'];

// ------------------------------------------------------------------ population and bots
$npcTasks->population();
$npcCount = (int)$db->fetchOne('SELECT COUNT(*) AS c FROM npc_profiles')['c'];
$bots = [];
for ($i = 1; $i <= $botCount; $i++) {
    $id = $shipModel->register("bot$i@sim.test", 'simulation-pass', "Bot $i", $config['game'], ['balanced', 'merchant', 'warship', 'scout'][$i % 4]);
    $db->execute('UPDATE ships SET sector = :s WHERE ship_id = :id', ['s' => random_int(1, $sectors), 'id' => $id]);
    $bots[$id] = ['aggressive' => ($i % 6) === 0];
}
$startCredits = (int)$db->fetchOne('SELECT COALESCE(SUM(credits), 0) AS c FROM ships')['c'];

$ticks = (int)floor($days * 24 * 60 / $tickMinutes);
$ticksPerHour = max(1, intdiv(60, $tickMinutes));
$ticksPerDay = $ticksPerHour * 24;
fwrite(STDERR, sprintf("Simulating %.1f days: %d ticks of %d min, %d NPCs, %d bots\n", $days, $ticks, $tickMinutes, $npcCount, $botCount));

$tickMs = [];
$tickNpcs = [];
$violations = [];
$stats = ['bot_actions' => 0, 'bot_attacks_ok' => 0, 'bot_attacks_blocked' => 0, 'fines_paid' => 0];
$lastLogId = 0;

function ageClock(Database $db, string $interval): void
{
    $db->execute("UPDATE ships SET wanted_until = wanted_until - CAST(:i AS INTERVAL), last_known_at = last_known_at - CAST(:i2 AS INTERVAL)", ['i' => $interval, 'i2' => $interval]);
    $db->execute("UPDATE npc_profiles SET respawn_at = respawn_at - CAST(:i AS INTERVAL) WHERE respawn_at IS NOT NULL", ['i' => $interval]);
    $db->execute("UPDATE npc_profiles SET state = jsonb_set(state, '{police,last_contact_at}',
        to_jsonb(to_char((state -> 'police' ->> 'last_contact_at')::timestamptz - CAST(:i AS INTERVAL), 'YYYY-MM-DD\"T\"HH24:MI:SSOF')))
        WHERE state -> 'police' ->> 'last_contact_at' IS NOT NULL", ['i' => $interval]);
    $db->execute("UPDATE messages SET sent_at = sent_at - CAST(:i AS INTERVAL)", ['i' => $interval]);
    $db->execute("UPDATE npc_events SET created_at = created_at - CAST(:i AS INTERVAL)", ['i' => $interval]);
}

/** Invariant checks. Returns a list of violation strings found since $sinceLogId. */
function checkInvariants(Database $db, int &$sinceLogId, int $sectors): array
{
    $v = [];
    // 1. Every alignment change is logged: the log sums to the current value.
    foreach ($db->fetchAll('SELECT s.ship_id, s.alignment, COALESCE((SELECT SUM(delta) FROM alignment_log l WHERE l.ship_id = s.ship_id), 0) AS logged FROM ships s
                            WHERE s.alignment <> COALESCE((SELECT SUM(delta) FROM alignment_log l WHERE l.ship_id = s.ship_id), 0) LIMIT 5') as $r) {
        $v[] = "alignment of ship {$r['ship_id']} is {$r['alignment']} but the log sums to {$r['logged']}";
    }
    foreach ($db->fetchAll('SELECT ship_id, alignment FROM ships WHERE alignment NOT BETWEEN -10000 AND 10000 LIMIT 5') as $r) {
        $v[] = "alignment out of range for ship {$r['ship_id']}";
    }
    // 2. No combat in a starbase sector; no attack on a Neutral-or-better ship in FedSpace (police vs Wanted excepted).
    $rows = $db->fetchAll(
        "SELECT a.log_id, a.attacker_id, a.attacker_name, a.defender_id, a.attack_type, a.sector, a.defender_tier, u.is_starbase, COALESCE(z.is_federation, FALSE) AS fed,
                (SELECT p.faction FROM npc_profiles p WHERE p.ship_id = a.attacker_id) AS attacker_faction
         FROM attack_logs a JOIN universe u ON u.sector_id = a.sector LEFT JOIN zones z ON z.zone_id = u.zone_id
         WHERE a.log_id > :since AND a.attack_type IN ('ship', 'planet')
         ORDER BY a.log_id",
        ['since' => $sinceLogId]
    );
    foreach ($rows as $r) {
        $sinceLogId = max($sinceLogId, (int)$r['log_id']);
        if ($r['is_starbase']) {
            $v[] = "combat inside starbase sector {$r['sector']} (attack log {$r['log_id']})";
        }
        if ($r['fed'] && in_array($r['defender_tier'], ['Paragon', 'Lawful', 'Neutral'], true) && $r['attacker_faction'] !== 'police') {
            $v[] = "{$r['attacker_name']} attacked a {$r['defender_tier']} ship in FedSpace sector {$r['sector']} (attack log {$r['log_id']})";
        }
    }
    // 3. Defences never sit in starbase sectors; no negative or over-cap resources.
    foreach ($db->fetchAll('SELECT sd.defence_id FROM sector_defence sd JOIN universe u ON u.sector_id = sd.sector_id WHERE u.is_starbase LIMIT 3') as $r) {
        $v[] = "defence {$r['defence_id']} in a starbase sector";
    }
    foreach ($db->fetchAll('SELECT ship_id FROM ships WHERE credits < 0 OR ship_fighters < 0 OR torps < 0 OR ship_ore < 0 OR ship_organics < 0 OR ship_goods < 0 LIMIT 3') as $r) {
        $v[] = "negative resources on ship {$r['ship_id']}";
    }
    foreach ($db->fetchAll('SELECT ship_id, sector FROM ships WHERE sector < 1 OR sector > :n LIMIT 3', ['n' => $sectors]) as $r) {
        $v[] = "ship {$r['ship_id']} is in invalid sector {$r['sector']}";
    }
    // 4. NPC accounts cannot be logged in to.
    foreach ($db->fetchAll("SELECT ship_id FROM ships WHERE is_npc AND (email NOT LIKE '%@npc.invalid' OR password_hash LIKE '$2y$%') LIMIT 3") as $r) {
        $v[] = "NPC {$r['ship_id']} has a usable login";
    }
    // 4b. Contraband stays out of starbases and honest NPC hands; carry cap respected.
    foreach ($db->fetchAll("SELECT s.ship_id FROM ships s LEFT JOIN npc_profiles p ON p.ship_id = s.ship_id LEFT JOIN universe u ON u.sector_id = s.sector
                            WHERE s.ship_contraband > 0 AND (u.is_starbase OR p.faction IN ('guild', 'police') OR s.ship_contraband > 50) LIMIT 3") as $r) {
        $v[] = "ship {$r['ship_id']} holds contraband in a place or hand where it never should";
    }
    // 5. Unclaimed Federation bounties only on Wanted ships.
    foreach ($db->fetchAll('SELECT b.target_id FROM bounties b JOIN ships s ON s.ship_id = b.target_id
                            WHERE b.placed_by IS NULL AND b.claimed_by IS NULL AND s.wanted_until IS NULL LIMIT 3') as $r) {
        $v[] = "Federation bounty on non-Wanted ship {$r['target_id']}";
    }
    return $v;
}

function botAct(int $id, array $bot, array $svc, Database $db, array &$stats, int $sectors): void
{
    $ship = $svc['shipModel']->find($id);
    if (!$ship) {
        return;
    }
    if ($ship['ship_destroyed']) {
        // Human bots re-register after being destroyed (new ship, same record) to keep traffic flowing.
        $db->execute('UPDATE ships SET ship_destroyed = FALSE, armor_pts = 100, sector = :s, alignment = 0 WHERE ship_id = :id', ['s' => random_int(1, $sectors), 'id' => $id]);
        $db->execute('DELETE FROM alignment_log WHERE ship_id = :id', ['id' => $id]);
        $stats['bot_revivals'] = ($stats['bot_revivals'] ?? 0) + 1;
    }
    $db->execute('UPDATE ships SET turns = GREATEST(turns, 200) WHERE ship_id = :id', ['id' => $id]);
    $ship = $svc['shipModel']->find($id);
    $roll = random_int(1, 100);
    $stats['bot_actions']++;
    if ($roll <= 55) {
        $links = $svc['sectorGraph']->neighbours((int)$ship['sector']);
        if ($links) {
            $svc['movementService']->move($ship, $links[array_rand($links)]);
        }
    } elseif ($roll <= 70) {
        $c = ['ore', 'organics', 'goods', 'energy'][random_int(0, 3)];
        $svc['tradeService']->trade($id, $c, random_int(0, 1) ? 'buy' : 'sell', random_int(1, 60));
    } elseif ($roll <= 70 + ($bot['aggressive'] ? 22 : 6)) {
        $others = $svc['shipModel']->getShipsInSector((int)$ship['sector'], $id);
        if ($others) {
            $r = $svc['combatService']->attackShip($ship, (int)$others[array_rand($others)]['ship_id']);
            $stats[$r['success'] ? 'bot_attacks_ok' : 'bot_attacks_blocked']++;
        }
    } elseif ($roll <= 94) {
        $svc['combatService']->deployDefence($ship, random_int(0, 1) ? 'F' : 'M', random_int(1, 5));
    } else {
        $r = $svc['alignmentService']->payFine($id);
        if ($r['success']) {
            $stats['fines_paid']++;
        }
    }
    // Keep the economy of the bots alive.
    $db->execute('UPDATE ships SET credits = GREATEST(credits, 20000), ship_fighters = GREATEST(ship_fighters, 30), torps = GREATEST(torps, 20) WHERE ship_id = :id', ['id' => $id]);
}

// ------------------------------------------------------------------ run
$wallStart = microtime(true);
for ($t = 1; $t <= $ticks; $t++) {
    $schedTasks->generateTurns(1);
    if ($t % 5 === 0) {
        $schedTasks->portProduction();
    }
    foreach ($bots as $id => $bot) {
        if (random_int(1, 100) <= 30) {
            botAct($id, $bot, $svc, $db, $stats, $sectors);
        }
    }
    $db->getConnection()->beginTransaction();   // the real scheduler runs tasks inside a transaction
    $started = microtime(true);
    $tickResult = $npcTasks->scriptedTick();
    $ms = (microtime(true) - $started) * 1000;
    $db->getConnection()->commit();
    $tickMs[] = $ms;
    preg_match('/Ran (\d+) NPCs/', $tickResult, $m);
    $tickNpcs[] = (int)($m[1] ?? 0);
    $npcTasks->policeDispatch();
    if ($t % 5 === 0) {
        $npcTasks->population();
    }
    if ($t % $ticksPerHour === 0) {
        ageClock($db, '1 hour');
    }
    if ($t % $ticksPerDay === 0) {
        $db->execute("UPDATE alignment_log SET created_at = created_at - interval '1 day'");
        $npcTasks->alignmentDrift();
        $db->execute("UPDATE scheduler_log SET run_time = run_time");
        fwrite(STDERR, sprintf("  day %d done (%.0fs elapsed)\n", intdiv($t, $ticksPerDay), microtime(true) - $wallStart));
    }
    if ($t % ($ticksPerHour * 2) === 0 || $t === $ticks) {
        foreach (checkInvariants($db, $lastLogId, $sectors) as $viol) {
            $violations[$viol] = true;
        }
    }
}

// ------------------------------------------------------------------ report
sort($tickMs);
$pct = static fn(array $a, float $p) => $a ? $a[min(count($a) - 1, (int)floor($p * count($a)))] : 0.0;
$endCredits = (int)$db->fetchOne('SELECT COALESCE(SUM(credits), 0) AS c FROM ships')['c'];
$npcCredits = $db->fetchAll("SELECT p.faction, COUNT(*) AS n, COALESCE(SUM(s.credits), 0) AS credits FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id GROUP BY p.faction ORDER BY p.faction");
$deaths = $db->fetchAll("SELECT p.faction, COUNT(*) AS n FROM attack_logs a JOIN npc_profiles p ON p.ship_id = a.defender_id WHERE a.result = 'destroyed' GROUP BY p.faction");
$report = [
    'sectors' => $sectors, 'game_days' => $days, 'ticks' => $ticks, 'npcs' => $npcCount, 'bots' => $botCount,
    'scripted_tick_ms' => ['avg' => round(array_sum($tickMs) / max(1, count($tickMs)), 1), 'p95' => round($pct($tickMs, 0.95), 1),
        'p99' => round($pct($tickMs, 0.99), 1), 'max' => round(end($tickMs) ?: 0, 1), 'limit' => 200],
    'avg_npcs_per_tick' => round(array_sum($tickNpcs) / max(1, count($tickNpcs)), 1),
    'npc_deaths_by_faction' => array_column($deaths, 'n', 'faction'),
    'npc_respawns' => (int)$db->fetchOne("SELECT COUNT(*) AS c FROM alignment_log WHERE reason = 'npc_respawn'")['c'],
    'credits' => ['start_total' => $startCredits, 'end_total' => $endCredits, 'net_generated' => $endCredits - $startCredits,
        'npc_by_faction' => array_column($npcCredits, 'credits', 'faction')],
    'wanted_now' => (int)$db->fetchOne('SELECT COUNT(*) AS c FROM ships WHERE wanted_until > now()')['c'],
    'federation_bounties_open' => (int)$db->fetchOne('SELECT COUNT(*) AS c FROM bounties WHERE placed_by IS NULL AND claimed_by IS NULL')['c'],
    'alignment_log_rows' => (int)$db->fetchOne('SELECT COUNT(*) AS c FROM alignment_log')['c'],
    'attacks' => (int)$db->fetchOne('SELECT COUNT(*) AS c FROM attack_logs')['c'],
    'bots' => $stats,
    'wall_seconds' => round(microtime(true) - $wallStart, 1),
    'rule_violations' => array_keys($violations),
];

if (isset($opts['json'])) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "\nNPC simulation report ($dbName)\n" . str_repeat('=', 40) . "\n";
    printf("Universe: %d sectors, %d NPCs, %d bots, %.1f game days (%d ticks, %.0fs wall)\n", $sectors, $npcCount, $botCount, $days, $ticks, $report['wall_seconds']);
    printf("Scripted tick: avg %.1f ms, p95 %.1f ms, p99 %.1f ms, max %.1f ms (limit 200 ms); avg %.1f NPCs/tick\n",
        $report['scripted_tick_ms']['avg'], $report['scripted_tick_ms']['p95'], $report['scripted_tick_ms']['p99'], $report['scripted_tick_ms']['max'], $report['avg_npcs_per_tick']);
    printf("NPC deaths: %s; respawns: %d\n", json_encode($report['npc_deaths_by_faction']), $report['npc_respawns']);
    printf("Credits: start %s, end %s, net generated %s\n", number_format($startCredits), number_format($endCredits), number_format($endCredits - $startCredits));
    foreach ($npcCredits as $r) {
        printf("  %-8s %2d ships holding %s credits\n", $r['faction'], $r['n'], number_format((int)$r['credits']));
    }
    printf("Combat: %d attacks logged; bots: %d actions, %d attacks landed, %d blocked by rules, %d fines paid\n",
        $report['attacks'], $stats['bot_actions'], $stats['bot_attacks_ok'], $stats['bot_attacks_blocked'], $stats['fines_paid']);
    printf("Wanted now: %d; open Federation bounties: %d; alignment log rows: %d\n", $report['wanted_now'], $report['federation_bounties_open'], $report['alignment_log_rows']);
    echo $violations ? "RULE VIOLATIONS (" . count($violations) . "):\n  - " . implode("\n  - ", array_slice(array_keys($violations), 0, 20)) . "\n" : "Rule violations: none\n";
}

// ------------------------------------------------------------------ cleanup
$ref->setValue(null, null);
if (!isset($opts['keep'])) {
    $admin->exec('DROP DATABASE IF EXISTS ' . $dbName);
}
@unlink($config['npc']['graph_cache_path']);
exit(($violations || ($report['scripted_tick_ms']['max'] > 200)) ? 1 : 0);
