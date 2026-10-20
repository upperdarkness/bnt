<?php

declare(strict_types=1);

namespace BNT\Tests;

/**
 * database/schema.sql must be complete on its own: a fresh install runs only that file. Every migration in
 * database/migrations/ is for upgrading existing databases, so applying them all on top of a fresh schema must
 * change nothing (tables, columns, indexes, constraints, seed rows).
 */
class SchemaTest extends DbTestCase
{
    private function psql(string $db, string $sql): string
    {
        $pgBin = getenv('PG_BIN') ?: '';
        $psql = ($pgBin ? rtrim($pgBin, '/') . '/' : '') . 'psql';
        exec('LC_ALL=en_US.UTF-8 ' . escapeshellarg($psql) . ' -X -At -d ' . escapeshellarg($db) . ' -c ' . escapeshellarg($sql) . ' 2>&1', $out);
        return implode("\n", $out);
    }

    /** Structure and seed data in a comparable form. */
    private function fingerprint(string $db): array
    {
        $q = [
            'columns' => "SELECT table_name||'.'||column_name||' '||data_type||' '||COALESCE(column_default,'')||' '||is_nullable FROM information_schema.columns WHERE table_schema='public' ORDER BY 1",
            'indexes' => "SELECT indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY 1",
            'constraints' => "SELECT conrelid::regclass||' '||pg_get_constraintdef(oid) FROM pg_constraint WHERE connamespace='public'::regnamespace ORDER BY 1",
            'scheduler' => "SELECT task_name||' '||interval_minutes||' '||enabled FROM scheduler_tasks ORDER BY 1",
            'zones' => "SELECT zone_id||' '||zone_name||' '||COALESCE(is_federation::text,'') FROM zones ORDER BY 1",
        ];
        $out = [];
        foreach ($q as $k => $sql) {
            $out[$k] = explode("\n", $this->psql($db, $sql));
        }
        return $out;
    }

    private function build(string $db, array $files, bool $singleTransaction = false): array
    {
        $pgBin = getenv('PG_BIN') ?: '';
        $bin = fn(string $b) => ($pgBin ? rtrim($pgBin, '/') . '/' : '') . $b;
        exec('LC_ALL=en_US.UTF-8 ' . escapeshellarg($bin('dropdb')) . ' --if-exists ' . escapeshellarg($db) . ' 2>&1');
        exec('LC_ALL=en_US.UTF-8 ' . escapeshellarg($bin('createdb')) . ' ' . escapeshellarg($db) . ' 2>&1');
        $errors = [];
        foreach ($files as $file) {
            exec(escapeshellarg($bin('psql')) . ' -X -q ' . ($singleTransaction ? '-v ON_ERROR_STOP=1 --single-transaction ' : '') . '-d ' . escapeshellarg($db) . ' -f ' . escapeshellarg($file) . ' 2>&1', $out);
            $errors = array_merge($errors, array_filter($out ?? [], fn($l) => str_contains($l, 'ERROR:')));
            $out = [];
        }
        return array_values($errors);
    }

    public function testMigrationsAddNothingToAFreshSchema(): void
    {
        $root = dirname(__DIR__, 2);
        $fresh = self::$dbName . '_fresh';
        $mig = self::$dbName . '_mig';
        $pgBin = getenv('PG_BIN') ?: '';
        try {
            // Exactly what scripts/init_db.sh does: one transaction, no migrations.
            $this->assertSame([], $this->build($fresh, [$root . '/database/schema.sql'], true), 'schema.sql loads cleanly in one transaction');
            $this->build($mig, array_merge([$root . '/database/schema.sql'], self::migrations($root)));
            $a = $this->fingerprint($fresh);
            $b = $this->fingerprint($mig);
            foreach ($a as $kind => $rows) {
                $this->assertSame([], array_values(array_diff($b[$kind], $rows)), "migrations add $kind that schema.sql lacks");
                $this->assertSame([], array_values(array_diff($rows, $b[$kind])), "schema.sql has $kind the migrations do not produce");
            }
            $this->assertTrue(count($a['columns']) > 200, 'fingerprint actually read the schema');
            $this->assertTrue(count($a['scheduler']) >= 15, 'seed rows are in schema.sql');
        } finally {
            foreach ([$fresh, $mig] as $d) {
                exec(escapeshellarg(($pgBin ? rtrim($pgBin, '/') . '/' : '') . 'dropdb') . ' --if-exists ' . escapeshellarg($d) . ' 2>&1');
            }
        }
    }
}
