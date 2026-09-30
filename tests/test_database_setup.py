"""Integration tests: requires PHP, Composer autoloading, and PostgreSQL client tools.

Run with PGHOST, PGPORT, PGUSER pointing to a disposable PostgreSQL server:
    python3 -m unittest discover -s tests -v
The PostgreSQL role must be able to create databases.
"""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[1]


class DatabaseSetupTest(unittest.TestCase):
    def setUp(self):
        self.name = 'bnt_test_' + uuid.uuid4().hex
        self.env = dict(os.environ, DB_NAME=self.name,
                        DB_HOST=os.environ.get('PGHOST', 'localhost'),
                        DB_PORT=os.environ.get('PGPORT', '5432'),
                        DB_USER=os.environ.get('PGUSER', 'bnt'),
                        DB_PASS=os.environ.get('PGPASSWORD', 'bnt'))
        self.run_command(['createdb', self.name])
        self.addCleanup(self.run_command, ['dropdb', self.name])

    def run_command(self, args, check=True, **kwargs):
        result = subprocess.run(args, env=self.env, cwd=ROOT, text=True,
                                capture_output=True, **kwargs)
        if check and result.returncode:
            self.fail(f"{args!r} failed:\n{result.stdout}\n{result.stderr}")
        return result

    def sql(self, text):
        return self.run_command(['psql', '-X', '-At', '-v', 'ON_ERROR_STOP=1',
                                 '-d', self.name, '-c', text]).stdout.strip()

    def test_fresh_setup_and_universe(self):
        self.run_command(['bash', 'scripts/init_db.sh'])
        self.assertEqual(self.sql('SELECT count(*) FROM zones WHERE owner IS NULL'), '4')
        self.run_command(['php', 'scripts/create_universe.php', '10', '2'])
        self.assertEqual(self.sql('SELECT count(*) FROM universe'), '10')
        self.assertEqual(self.sql('SELECT count(*) FROM planets WHERE owner IS NULL'), '2')
        self.sql("INSERT INTO ships (email, password_hash, character_name) VALUES ('test@example.com', 'test', 'test'); INSERT INTO zones (zone_name, owner) VALUES ('Owned', 1); UPDATE planets SET owner = 1; DELETE FROM ships WHERE ship_id = 1;")
        self.assertEqual(self.sql('SELECT count(*) FROM zones WHERE owner IS NULL'), '5')
        self.assertEqual(self.sql('SELECT count(*) FROM planets WHERE owner IS NULL'), '2')
        self.sql("INSERT INTO zones (zone_name) VALUES ('Unowned')")
        result = self.run_command(['psql', '-v', 'ON_ERROR_STOP=1', '-d', self.name,
                                  '-c', "INSERT INTO zones (zone_name, owner) VALUES ('Invalid', 999)"], check=False)
        self.assertNotEqual(result.returncode, 0)

    def test_failed_schema_rolls_back_and_does_not_report_success(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'database').mkdir()
            shutil.copy(ROOT / 'scripts/init_db.sh', root / 'scripts/init_db.sh')
            (root / 'database/schema.sql').write_text(
                'CREATE TABLE rollback_probe (id integer); SELECT missing_column;')
            result = self.run_command(['bash', str(root / 'scripts/init_db.sh')], check=False)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('Database setup complete!', result.stdout)
            self.assertEqual(self.sql("SELECT to_regclass('rollback_probe') IS NULL"), 't')

    def test_repair_preserves_existing_ownership_and_is_repeatable(self):
        self.sql("""
            CREATE TABLE ships (ship_id integer PRIMARY KEY);
            INSERT INTO ships VALUES (7);
            CREATE TABLE zones (zone_id serial PRIMARY KEY, zone_name varchar(50),
                owner integer DEFAULT 0, corp_zone boolean DEFAULT false,
                CONSTRAINT fk_zone_owner FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET DEFAULT);
            CREATE TABLE planets (owner integer DEFAULT 0,
                CONSTRAINT fk_planet_owner FOREIGN KEY (owner) REFERENCES ships(ship_id) ON DELETE SET DEFAULT);
            CREATE TABLE universe (sector_id serial PRIMARY KEY);
            INSERT INTO zones (zone_id, zone_name, owner) VALUES (5, 'Player zone', 7);
            INSERT INTO planets VALUES (7);
        """)
        for _ in range(2):
            self.run_command(['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction',
                              '-d', self.name, '-f', 'database/migrations/fix_database_setup.sql'])
        self.assertEqual(self.sql('SELECT count(*) FROM zones WHERE owner IS NULL'), '4')
        self.assertEqual(self.sql('SELECT owner FROM zones WHERE zone_id = 5'), '7')
        self.assertEqual(self.sql('SELECT owner FROM planets'), '7')
        self.sql('INSERT INTO universe DEFAULT VALUES')
        self.assertEqual(self.sql('SELECT port_colonists, is_starbase FROM universe'), '0|f')
        self.sql('DELETE FROM ships WHERE ship_id = 7')
        self.assertEqual(self.sql('SELECT owner IS NULL FROM planets'), 't')
