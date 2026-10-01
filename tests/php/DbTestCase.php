<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Core\Database;
use BNT\Core\Services;

/**
 * Base class for tests that need PostgreSQL. Each test class gets its own throw-away
 * database built from database/schema.sql plus every migration (same as production).
 * Uses the standard PG* environment variables; skipped when psql/createdb are unavailable.
 */
abstract class DbTestCase extends TestCase
{
    protected static ?string $dbName = null;
    protected static array $config = [];
    protected static ?Database $db = null;
    protected static array $svc = [];

    public static ?string $skip = null;

    public function skipReason(): ?string
    {
        return self::$skip;
    }

    public static function setUpBeforeClass(): void
    {
        self::$skip = null;
        self::$dbName = null;
        $root = dirname(__DIR__, 2);
        $pgBin = getenv('PG_BIN') ?: '';
        $psql = ($pgBin ? rtrim($pgBin, '/') . '/' : '') . 'psql';
        $createdb = ($pgBin ? rtrim($pgBin, '/') . '/' : '') . 'createdb';
        exec('command -v ' . escapeshellarg($psql) . ' 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            self::$skip = 'psql not found (set PG_BIN or PATH)';
            return;
        }
        $name = 'bnt_test_' . bin2hex(random_bytes(6));
        $env = 'LC_ALL=en_US.UTF-8 ';
        exec($env . escapeshellarg($createdb) . ' ' . escapeshellarg($name) . ' 2>&1', $out2, $rc);
        if ($rc !== 0) {
            self::$skip = 'cannot create test database: ' . implode(' ', $out2);
            return;
        }
        self::$dbName = $name;
        $files = array_merge(
            [$root . '/database/schema.sql'],
            self::migrations($root)
        );
        foreach ($files as $file) {
            exec($env . escapeshellarg($psql) . ' -X -q -d ' . escapeshellarg($name) . ' -f ' . escapeshellarg($file) . ' 2>&1', $o, $r);
        }
        putenv('DB_NAME=' . $name);
        $_ENV['DB_NAME'] = $name;
        self::$config = require $root . '/config/config.php';
        self::$config['database']['database'] = $name;
        self::$config['database']['host'] = getenv('PGHOST') ?: 'localhost';
        self::$config['database']['port'] = (int)(getenv('PGPORT') ?: 5432);
        self::$config['database']['username'] = getenv('PGUSER') ?: self::$config['database']['username'];
        self::$config['database']['password'] = getenv('PGPASSWORD') ?: 'x';
        self::$config['npc']['graph_cache_path'] = sys_get_temp_dir() . '/bnt_test_graph_' . $name . '.json';
        self::$config['npc']['prompt_dir'] = $root . '/config/npc_prompts';
        // Database holds a static PDO; reflect to reset between classes.
        $ref = new \ReflectionProperty(Database::class, 'connection');
        $ref->setValue(null, null);
        self::$db = new Database(self::$config);
        self::$svc = Services::create(self::$config, self::$db);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dbName === null) {
            return;
        }
        $ref = new \ReflectionProperty(Database::class, 'connection');
        $ref->setValue(null, null);
        $pgBin = getenv('PG_BIN') ?: '';
        $dropdb = ($pgBin ? rtrim($pgBin, '/') . '/' : '') . 'dropdb';
        exec('LC_ALL=en_US.UTF-8 ' . escapeshellarg($dropdb) . ' --if-exists ' . escapeshellarg(self::$dbName) . ' 2>&1');
        @unlink(self::$config['npc']['graph_cache_path'] ?? '');
        self::$dbName = null;
    }

    private static function migrations(string $root): array
    {
        $order = ['add_api_tokens', 'add_attack_logs', 'add_planet_economy_news', 'add_port_colonists', 'add_scheduler',
            'add_ship_types', 'add_skills', 'add_starbases', 'fix_database_setup', 'fix_planet_owner_nullable', 'add_alignment_npcs'];
        $files = [];
        foreach ($order as $m) {
            $f = "$root/database/migrations/$m.sql";
            if (is_file($f)) {
                $files[] = $f;
            }
        }
        return $files;
    }

    // ------------------------------------------------------------ fixtures

    protected function db(): Database
    {
        return self::$db;
    }

    protected function svc(string $name): object
    {
        return self::$svc[$name];
    }

    /** Wipe game data between tests. */
    public function setUp(): void
    {
        self::$db->execute('TRUNCATE ships, universe, links, planets, sector_defence, messages, news, attack_logs, bounties, alignment_log,
            npc_events, npc_action_log, npc_alerts, movement_log, ibank_accounts, api_tokens, api_rate_buckets, ship_known_ports,
            npc_settings, npc_worker_status, teams, scheduler_tasks RESTART IDENTITY CASCADE');
        self::$db->execute("TRUNCATE zones RESTART IDENTITY CASCADE");
        self::$db->execute("INSERT INTO zones (zone_id, zone_name, corp_zone, is_federation) VALUES
            (1, 'Neutral Zone', FALSE, FALSE), (2, 'Federation Space', TRUE, TRUE), (3, 'Free Trade Zone', FALSE, FALSE), (4, 'War Zone', FALSE, FALSE)");
        self::$svc['sectorRules']->forget();
        self::$svc['sectorGraph']->refresh();
        self::$config['npc']['llm_enabled'] = false;
    }

    /**
     * Linear universe 1..$n (each linked to neighbours), sector 1 a starbase in Federation zone 2,
     * sector 2 also Federation (neighbour of 1). Port types cycle ore/organics/goods/energy from sector 3.
     */
    protected function makeUniverse(int $n = 12): void
    {
        $types = ['ore', 'organics', 'goods', 'energy'];
        for ($i = 1; $i <= $n; $i++) {
            $port = $i >= 3 ? $types[($i - 3) % 4] : 'none';
            self::$db->execute(
                'INSERT INTO universe (sector_id, sector_name, zone_id, port_type, is_starbase, port_ore, port_organics, port_goods, port_energy)
                 VALUES (:id, :name, :zone, :port, :sb, 150000, 150000, 150000, 150000)',
                ['id' => $i, 'name' => "Sector $i", 'zone' => $i <= 2 ? 2 : 1, 'port' => $i === 1 ? 'special' : $port, 'sb' => $i === 1]
            );
        }
        for ($i = 1; $i < $n; $i++) {
            self::$db->execute('INSERT INTO links (link_start, link_dest) VALUES (:a, :b), (:b2, :a2)', ['a' => $i, 'b' => $i + 1, 'b2' => $i + 1, 'a2' => $i]);
        }
        self::$db->execute("SELECT setval(pg_get_serial_sequence('universe', 'sector_id'), :n)", ['n' => $n]);
        self::$svc['sectorRules']->forget();
        self::$svc['sectorGraph']->refresh();
    }

    protected function makePlayer(string $name, array $over = []): int
    {
        static $n = 0;
        $n++;
        $id = self::$svc['shipModel']->register("p{$n}_" . bin2hex(random_bytes(3)) . '@example.com', 'password123', $name, self::$config['game'], $over['ship_type'] ?? 'balanced');
        unset($over['ship_type']);
        $over += ['sector' => 5];
        $sets = [];
        $params = ['id' => $id];
        foreach ($over as $k => $v) {
            $sets[] = "$k = :$k";
            $params[$k] = $v;
        }
        self::$db->execute('UPDATE ships SET ' . implode(', ', $sets) . ' WHERE ship_id = :id', $params);
        return $id;
    }

    protected function ship(int $id): array
    {
        return self::$svc['shipModel']->find($id);
    }

    protected function alignmentOf(int $id): int
    {
        return (int)$this->ship($id)['alignment'];
    }

    protected function logCount(int $shipId, ?string $reason = null): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM alignment_log WHERE ship_id = :id' . ($reason ? ' AND reason = :r' : '');
        $params = ['id' => $shipId] + ($reason ? ['r' => $reason] : []);
        return (int)self::$db->fetchOne($sql, $params)['c'];
    }

    /** Strong attacker that always beats a default ship in one hit. */
    protected function strong(array $extra = []): array
    {
        return $extra + ['beams' => 12, 'ship_fighters' => 500, 'torps' => 50, 'armor' => 10, 'armor_pts' => 5000, 'shields' => 5];
    }
}
