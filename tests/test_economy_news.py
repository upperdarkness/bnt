"""PostgreSQL integration tests; use the same PG* environment as test_database_setup."""
import json
import http.client
import re
import socket
import tempfile
import time
import os
from pathlib import Path
import subprocess
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[1]


class EconomyNewsTest(unittest.TestCase):
    def command(self, args, **kwargs):
        result = subprocess.run(args, cwd=ROOT, env=self.env, capture_output=True, text=True, **kwargs)
        if result.returncode:
            self.fail(result.stdout + result.stderr)
        return result.stdout.strip()

    def sql(self, sql):
        return self.command(['psql', '-XAt', '-v', 'ON_ERROR_STOP=1', '-d', self.name, '-c', sql])

    def php_code(self, body):
        return ('require "vendor/autoload.php"; '
                '$config = require "config/config.php"; '
                '$db = new BNT\\Core\\Database($config); '
                '$tasks = new BNT\\Core\\SchedulerTasks($db, $config); ' + body)

    def php(self, body):
        return self.command(['php', '-r', self.php_code(body)])

    def setUp(self):
        self.name = 'bnt_economy_' + uuid.uuid4().hex
        self.env = dict(os.environ, DB_NAME=self.name,
                        DB_HOST=os.environ.get('PGHOST', 'localhost'),
                        DB_PORT=os.environ.get('PGPORT', '5432'),
                        DB_USER=os.environ.get('PGUSER', 'bnt'),
                        DB_PASS=os.environ.get('PGPASSWORD', 'bnt'))
        self.command(['createdb', self.name])
        self.addCleanup(self.command, ['dropdb', self.name])
        self.command(['psql', '-v', 'ON_ERROR_STOP=1', '-d', self.name, '-f', 'database/schema.sql'])
        self.sql("""INSERT INTO ships (email, password_hash, character_name)
                    VALUES ('owner@example.com', 'unused', 'Owner');
                    INSERT INTO universe (sector_name) VALUES ('Test');
                    INSERT INTO planets (planet_name, sector_id, owner, colonists, organics, credits)
                    VALUES ('Colony', 1, 1, 10000, 100, 10000);""")

    def test_growth_food_income_and_catchup(self):
        self.php('$tasks->planetProduction(3);')
        self.assertEqual(self.sql('SELECT colonists, organics, credits, ore FROM planets'), '10015|128|10045|60')
        # Sub-100 populations still eat and can starve all the way to zero.
        self.sql('UPDATE planets SET colonists = 1, organics = 0')
        self.php('$tasks->planetProduction();')
        self.assertEqual(self.sql('SELECT colonists, organics FROM planets'), '0|0')

    def test_starvation_suppresses_growth_and_tax(self):
        self.sql('UPDATE planets SET organics = 0, prod_organics = 0, prod_ore = 40')
        self.php('$tasks->planetProduction();')
        self.assertEqual(self.sql('SELECT colonists, organics, credits FROM planets'), '9900|0|10005')

    def test_caps_preserve_existing_stock_and_unowned_planets(self):
        self.sql('UPDATE planets SET colonists = 100000000, organics = 100000000, credits = 10000000, ore = 200000000')
        self.php('$tasks->planetProduction();')
        self.assertEqual(self.sql('SELECT colonists, credits, ore FROM planets'), '100000000|10000000|200000000')
        self.sql('UPDATE planets SET base = TRUE')
        self.php('$tasks->planetProduction();')
        self.assertEqual(self.sql('SELECT credits FROM planets'), '10105000')
        self.sql('UPDATE planets SET owner = NULL')
        before = self.sql('SELECT row_to_json(planets) FROM planets')
        self.php('$tasks->planetProduction(720);')
        self.assertEqual(self.sql('SELECT row_to_json(planets) FROM planets'), before)

    def test_scheduler_catches_up_once_and_rolls_back_failures(self):
        self.sql("UPDATE scheduler_tasks SET last_run = NOW() - INTERVAL '6 minutes 5 seconds' WHERE task_name = 'planet_production'")
        code = r'''$scheduler = new BNT\Core\Scheduler($db, $config);
                  $scheduler->registerTask('planet_production', [$tasks, 'planetProduction'], 2);
                  echo json_encode($scheduler->run());'''
        result = json.loads(self.php(code))
        self.assertEqual(result['planet_production']['status'], 'success')
        self.assertEqual(self.sql('SELECT colonists FROM planets'), '10015')
        self.assertEqual(json.loads(self.php(code))['planet_production']['status'], 'skipped')
        before = self.sql("SELECT last_run FROM scheduler_tasks WHERE task_name = 'planet_production'")
        result = json.loads(self.php(r'''$scheduler = new BNT\Core\Scheduler($db, $config);
            $scheduler->registerTask('planet_production', function() use ($db) {
                $db->execute('UPDATE planets SET credits = 999');
                throw new RuntimeException('deliberate failure');
            }, 2);
            echo json_encode($scheduler->forceRun('planet_production'));'''))
        self.assertEqual(result['status'], 'error')
        self.assertEqual(self.sql('SELECT credits FROM planets'), '10045')
        self.assertEqual(self.sql("SELECT last_run FROM scheduler_tasks WHERE task_name = 'planet_production'"), before)

    def test_concurrent_scheduler_skips_locked_task(self):
        self.sql("UPDATE scheduler_tasks SET last_run = NOW() - INTERVAL '2 minutes 5 seconds' WHERE task_name = 'planet_production'")
        body = r'''$scheduler = new BNT\Core\Scheduler($db, $config);
            $scheduler->registerTask('planet_production', function() use ($tasks) {
                echo "locked\n"; flush(); usleep(800000); return $tasks->planetProduction();
            }, 2);
            echo json_encode($scheduler->run());'''
        process = subprocess.Popen(['php', '-r', self.php_code(body)], cwd=ROOT, env=self.env,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            self.assertEqual(process.stdout.readline().strip(), 'locked')
            result = json.loads(self.php(r'''$scheduler = new BNT\Core\Scheduler($db, $config);
                $scheduler->registerTask('planet_production', [$tasks, 'planetProduction'], 2);
                echo json_encode($scheduler->run());'''))
            self.assertEqual(result['planet_production']['status'], 'skipped')
            output, error = process.communicate(timeout=10)
            self.assertEqual(process.returncode, 0, error)
            self.assertEqual(json.loads(output)['planet_production']['status'], 'success')
            self.assertEqual(self.sql('SELECT colonists FROM planets'), '10005')
        finally:
            if process.poll() is None:
                process.kill()
                process.communicate()

    def seed_events(self):
        self.sql("""INSERT INTO attack_logs (attacker_id, attacker_name, defender_name, attack_type, result, timestamp)
            VALUES (1, '<script>alert(1)</script>', 'Victim', 'ship', 'destroyed', NOW() - INTERVAL '2 days'),
                   (1, 'Owner', 'Colony', 'planet', 'destroyed', NOW()),
                   (1, 'Owner', 'Escaped', 'ship', 'escaped', NOW()),
                   (1, 'Owner', 'Damaged', 'planet', 'success', NOW());""")

    def test_news_backfill_idempotence_retention_and_escaping(self):
        self.seed_events()
        self.php('$tasks->generateNews(); $tasks->generateNews();')
        self.assertEqual(self.sql('SELECT count(*) FROM news'), '2')
        self.assertEqual(self.sql("SELECT count(*) FROM news WHERE news_type = 'planet_capture'"), '1')
        html = self.php(r'''$items = (new BNT\Models\News($db))->recent();
                          include 'src/Views/news.php';''')
        self.assertIn('&lt;script&gt;alert(1)&lt;/script&gt;', html)
        self.assertNotIn('<script>alert(1)</script>', html)
        self.sql("""INSERT INTO attack_logs (attacker_id, attacker_name, attack_type, result)
                    SELECT 1, 'Owner', 'ship', 'destroyed' FROM generate_series(1, 105)""")
        self.php('$tasks->generateNews();')
        self.assertEqual(self.sql('SELECT count(*) FROM news'), '100')
        self.sql('DELETE FROM news')
        self.php('$tasks->generateNews();')
        self.assertEqual(self.sql('SELECT count(*) FROM news'), '0')
        self.assertIn('The galaxy is quiet', self.php("$items = []; include 'src/Views/news.php';"))

    def test_news_rollback_keeps_events_retryable(self):
        self.seed_events()
        self.sql("ALTER TABLE news ADD CONSTRAINT reject_capture CHECK (news_type != 'planet_capture')")
        self.php('try { $tasks->generateNews(); } catch (Throwable $e) {}')
        self.assertEqual(self.sql('SELECT count(*) FROM news'), '0')
        self.assertEqual(self.sql('SELECT count(*) FROM attack_logs WHERE news_published'), '0')
        self.sql('ALTER TABLE news DROP CONSTRAINT reject_capture')
        self.php('$tasks->generateNews();')
        self.assertEqual(self.sql('SELECT count(*) FROM news'), '2')

    def test_migration_repeatability_and_missing_task_registration(self):
        # Reproduce a pre-feature schema with existing news and planets.
        self.sql("""ALTER TABLE news DROP COLUMN source_attack_log_id;
                    ALTER TABLE attack_logs DROP COLUMN news_published;
                    INSERT INTO news (headline, newstext, user_id) VALUES ('Legacy', 'Keep me', NULL);""")
        before = self.sql('SELECT row_to_json(planets) FROM planets')
        for _ in range(2):
            self.command(['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-d', self.name,
                          '-f', 'database/migrations/add_planet_economy_news.sql'])
        self.assertEqual(self.sql('SELECT row_to_json(planets) FROM planets'), before)
        self.assertEqual(self.sql("SELECT newstext FROM news WHERE headline = 'Legacy'"), 'Keep me')
        self.sql("DELETE FROM scheduler_tasks WHERE task_name = 'news_generation'")
        result = json.loads(self.php(r'''$scheduler = new BNT\Core\Scheduler($db, $config);
            $scheduler->registerTask('news_generation', [$tasks, 'generateNews'], 15);
            echo json_encode($scheduler->run());'''))
        self.assertEqual(result['news_generation']['status'], 'skipped')
        self.assertEqual(self.sql("SELECT interval_minutes FROM scheduler_tasks WHERE task_name = 'news_generation'"), '15')

    def test_player_pages_and_credit_collection(self):
        self.seed_events()
        self.php("$tasks->generateNews(); $tasks->planetProduction(); $db->execute('UPDATE ships SET password_hash = :hash, on_planet = TRUE, planet_id = 1', ['hash' => password_hash('test-password', PASSWORD_DEFAULT)]);")
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with tempfile.TemporaryDirectory() as sessions, tempfile.TemporaryFile(mode='w+') as log:
            server = subprocess.Popen(['php', '-d', 'display_errors=1', '-d', 'session.save_path=' + sessions,
                '-S', f'127.0.0.1:{port}', '-t', 'public', 'public/router.php'],
                cwd=ROOT, env=self.env, stdout=log, stderr=log)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.05)
                connection = http.client.HTTPConnection('127.0.0.1', port, timeout=10)
                self.addCleanup(connection.close)
                connection.request('GET', '/news')
                response = connection.getresponse()
                self.assertEqual(response.status, 302)
                self.assertEqual(response.getheader('Location'), '/')
                response.read()
                connection.request('POST', '/login', 'email=owner%40example.com&password=test-password',
                                   {'Content-Type': 'application/x-www-form-urlencoded'})
                response = connection.getresponse()
                self.assertEqual(response.status, 302)
                cookie = response.getheader('Set-Cookie').split(';')[0]
                response.read()
                for path, expected in [('/news', 'Planet captured'), ('/galaxy', 'Galaxy map'), ('/skills', 'Character Skills'), ('/planet/manage/1', 'Economy forecast')]:
                    connection.request('GET', path, headers={'Cookie': cookie})
                    response = connection.getresponse()
                    html = response.read().decode()
                    self.assertEqual(response.status, 200, html)
                    self.assertIn(expected, html)
                    self.assertNotIn('Warning:', html)
                    self.assertNotIn('Fatal error', html)
                token = re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)
                connection.request('POST', '/planet/transfer/1',
                    f'csrf_token={token}&resource_type=credits&amount=15&direction=to_ship',
                    {'Cookie': cookie, 'Content-Type': 'application/x-www-form-urlencoded'})
                response = connection.getresponse()
                self.assertEqual(response.status, 302)
                response.read()
                self.assertEqual(self.sql('SELECT credits FROM ships'), '1015')
                self.assertEqual(self.sql('SELECT credits FROM planets'), '10000')
            finally:
                server.terminate()
                server.wait(timeout=10)

    def test_galaxy_contains_public_topology_and_safe_json(self):
        self.sql("""INSERT INTO universe (sector_id, sector_name, port_type, is_starbase)
                    VALUES (9, '</script><script>bad()</script>', 'ore', TRUE);
                    INSERT INTO links (link_start, link_dest) VALUES (1, 9);""")
        data = json.loads(self.php(r"echo json_encode((new BNT\Models\Universe($db))->getGalaxyMap());"))
        self.assertEqual(data['links'], [[1, 9]])
        self.assertEqual(set(data['sectors'][0]), {'id', 'name', 'port', 'starbase'})
        html = self.php(r"""$session = new BNT\Core\Session(); $session->setUserId(1);
            $controller = new BNT\Controllers\GameController(new BNT\Models\Ship($db),
                new BNT\Models\Universe($db), new BNT\Models\Planet($db),
                new BNT\Models\Combat($db), $session, $config);
            $_GET['sector'] = '9'; $controller->galaxy();""")
        self.assertNotIn('</script><script>bad()', html)
        self.assertIn('action="/move/9"', html)
        self.assertIn('name="csrf_token"', html)
        self.assertIn('data-select-sector="9"', html)

    def test_skill_schema_supports_progression_and_repeat_migration(self):
        self.php(r"$skills = new BNT\Models\Skill($db); $skills->awardSkillPoints(1, 5); $skills->allocateSkillPoints(1, 'trading', 2);")
        self.assertEqual(self.sql('SELECT skill_trading, skill_points FROM ships'), '2|3')
        for _ in range(2):
            self.command(['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-d', self.name,
                          '-f', 'database/migrations/add_skills.sql'])
        self.assertEqual(self.sql('SELECT skill_trading, skill_points FROM ships'), '2|3')
