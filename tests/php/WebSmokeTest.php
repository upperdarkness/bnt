<?php

declare(strict_types=1);

namespace BNT\Tests;

/** Drives the real web UI (sessions, CSRF, views) to make sure every new page renders and the new actions work. */
class WebSmokeTest extends DbTestCase
{
    private static int $port = 0;
    private static string $jar = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (self::$skip) {
            return;
        }
        $root = dirname(__DIR__, 2);
        try {
            self::$port = HttpServers::start($root . '/public', $root . '/public/router.php', [
                'DB_HOST' => self::$config['database']['host'], 'DB_PORT' => (string)self::$config['database']['port'],
                'DB_NAME' => self::$dbName, 'DB_USER' => self::$config['database']['username'], 'DB_PASS' => 'x',
                'NPC_GRAPH_CACHE' => sys_get_temp_dir() . '/bnt_web_graph_' . self::$dbName . '.json',
                'display_errors' => '1', 'CONTRABAND_ENABLED' => 'true',
            ], $root);
        } catch (\Throwable $e) {
            self::$skip = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        HttpServers::stop(self::$port);
        @unlink(sys_get_temp_dir() . '/bnt_web_graph_' . self::$dbName . '.json');
        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(20);
        self::$jar = tempnam(sys_get_temp_dir(), 'bntjar');
    }

    public function tearDown(): void
    {
        @unlink(self::$jar);
    }

    /** @return array{status: int, body: string, location: ?string} */
    private function web(string $method, string $path, array $form = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => self::$jar,
            CURLOPT_COOKIEFILE => self::$jar, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr($raw, 0, $size);
        return ['status' => $status, 'body' => substr($raw, $size), 'location' => preg_match('/^Location:\s*(\S+)/mi', $head, $m) ? $m[1] : null];
    }

    /** NpcSettings caches per instance (one instance per request in production), so read through a fresh one. */
    private function freshSettings(): \BNT\Services\NpcSettings
    {
        return new \BNT\Services\NpcSettings($this->db(), self::$config);
    }

    private function csrf(string $path = '/'): string
    {
        $r = $this->web('GET', $path);
        preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $m);
        $this->assertTrue(isset($m[1]), "csrf token on $path (status {$r['status']})");
        return $m[1];
    }

    private function login(int $shipId): void
    {
        $email = $this->ship($shipId)['email'];
        $r = $this->web('POST', '/login', ['csrf_token' => $this->csrf('/'), 'email' => $email, 'password' => 'password123']);
        $this->assertSame('/main', $r['location'], 'login redirects to the main screen');
    }

    private function assertPage(array $r, string $label, array $contains = []): void
    {
        $this->assertSame(200, $r['status'], "$label status");
        foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Stack trace', 'Uncaught'] as $bad) {
            $this->assertNotContains($bad, $r['body'], "$label must not emit PHP errors");
        }
        foreach ($contains as $c) {
            $this->assertContains($c, $r['body'], $label);
        }
    }

    public function testPlayerPagesRenderWithAlignmentInformation(): void
    {
        $me = $this->makePlayer('Skipper', ['sector' => 5]);
        $other = $this->makePlayer('Hostile Pete', ['sector' => 5]);
        $this->db()->execute('UPDATE ships SET alignment = -400 WHERE ship_id = :id', ['id' => $other]);
        $this->svc('alignmentService')->setWanted($other, 'test');
        $npc = $this->svc('npcService')->spawn('guild', ['sector' => 5, 'name' => 'Trader Joe']);
        $this->login($me);

        $main = $this->web('GET', '/main');
        $this->assertPage($main, 'main', ['Alignment:', 'Neutral', 'Hostile Pete', 'Outlaw', 'WANTED', '[Guild] Trader Joe', '[NPC]']);
        $this->assertNotContains('-400', $main['body'], 'other players\' exact alignment is never shown');
        $this->assertPage($this->web('GET', '/scan'), 'scan', ['Hostile Pete', 'Outlaw']);
        $this->assertPage($this->web('GET', '/combat'), 'combat', ['Hostile Pete']);
        $this->assertPage($this->web('GET', '/status'), 'status');
        $this->assertPage($this->web('GET', '/news'), 'news', ['Federation declares ship Wanted']);
        $this->assertPage($this->web('GET', '/alignment'), 'alignment', ['Tier', 'Place a bounty', 'How tiers work']);
        $rank = $this->web('GET', '/ranking?sort=alignment');
        $this->assertPage($rank, 'ranking', ['Alignment', 'Faction Rankings']);
        $this->assertNotContains('Trader Joe', $rank['body'], 'NPCs are not in the player top 100');
        $this->assertPage($this->web('GET', '/ranking/factions'), 'factions', ['Merchant Guild']);
        $profile = $this->web('GET', '/player/' . $other);
        $this->assertPage($profile, 'profile', ['Hostile Pete', 'Outlaw']);
        $this->assertNotContains('-400', $profile['body']);
        $npcProfile = $this->web('GET', '/player/' . $npc['ship_id']);
        $this->assertPage($npcProfile, 'npc profile', ['NPC', 'Merchant Guild']);
        $this->assertNotContains('LLM', $npcProfile['body'], 'never reveal whether an LLM controls an NPC');
        $own = $this->web('GET', '/player/' . $me);
        $this->assertPage($own, 'own profile', ['Neutral']);
    }

    public function testCombatLogsShowTiersAndWebAttackUsesTheSharedRules(): void
    {
        $me = $this->makePlayer('Skipper', $this->strong(['sector' => 6]));
        $victim = $this->makePlayer('Victim', ['sector' => 6]);
        $this->login($me);
        $r = $this->web('POST', '/combat/attack/ship/' . $victim, ['csrf_token' => $this->csrf('/combat')]);
        $this->assertSame('/combat', $r['location']);
        $combat = $this->web('GET', '/combat');
        $this->assertContains('Target destroyed', $combat['body']);
        $this->assertSame(-350, $this->alignmentOf($me));
        $logs = $this->web('GET', '/logs/made');
        $this->assertPage($logs, 'logs', ['Victim', 'Neutral']);
        // Starbase block through the web UI keeps its message.
        $this->db()->execute('UPDATE ships SET sector = 1 WHERE ship_id = :id', ['id' => $me]);
        $other = $this->makePlayer('Docked', ['sector' => 1]);
        $this->web('POST', '/combat/attack/ship/' . $other, ['csrf_token' => $this->csrf('/alignment')]);
        $this->assertContains('Combat is not allowed in starbase sectors', $this->web('GET', '/combat')['body']);
    }

    public function testPortFinePageAndBountyForm(): void
    {
        $me = $this->makePlayer('Rogue', ['sector' => 1, 'credits' => 500000]);
        $this->db()->execute("UPDATE universe SET port_type = 'ore' WHERE sector_id = 1");
        $this->svc('alignmentService')->apply($me, -400, 'bad');
        $this->svc('alignmentService')->setWanted($me, 'test');
        $this->login($me);
        $port = $this->web('GET', '/port');
        $this->assertPage($port, 'port', ['Federation Office', 'Pay fine']);
        $r = $this->web('POST', '/port/fine', ['csrf_token' => $this->csrf('/port')]);
        $this->assertSame('/port', $r['location']);
        $this->assertSame(-99, $this->alignmentOf($me));
        $this->assertSame(null, $this->ship($me)['wanted_until']);
        // Pirates see the refusal banner and no fine button.
        $this->svc('alignmentService')->apply($me, -1500, 'worse');
        $port = $this->web('GET', '/port');
        $this->assertPage($port, 'pirate port', ['Service refused', 'will not accept a fine from a Pirate']);

        $target = $this->makePlayer('Mark', ['sector' => 5]);
        $this->db()->execute('UPDATE ibank_accounts SET balance = 90000 WHERE ship_id = :id', ['id' => $me]);
        $this->web('POST', '/alignment/bounty', ['csrf_token' => $this->csrf('/alignment'), 'target' => 'Mark', 'amount' => '25000']);
        $this->assertSame(25000, $this->svc('bountyService')->openTotal($target));
        $this->assertContains('Bounty of 25,000', $this->web('GET', '/alignment')['body']);
        $this->assertSame(65000, (int)$this->db()->fetchOne('SELECT balance FROM ibank_accounts WHERE ship_id = :id', ['id' => $me])['balance']);
    }

    public function testBlackMarketPageAndWebTrade(): void
    {
        $this->db()->execute("UPDATE universe SET is_blackmarket = TRUE, port_type = 'special', port_contraband = 100 WHERE sector_id = 9");
        $me = $this->makePlayer('Dealer', ['sector' => 9, 'hull' => 5, 'credits' => 500000]);
        $this->login($me);
        $this->assertPage($this->web('GET', '/port'), 'black market', ['Black Market: Void Relics', 'costs', 'alignment']);
        $this->web('POST', '/port/trade', ['csrf_token' => $this->csrf('/port'), 'commodity' => 'contraband', 'action' => 'buy', 'amount' => '5']);
        $this->assertSame(5, (int)$this->ship($me)['ship_contraband']);
        $this->assertSame(-200, $this->alignmentOf($me));
        $this->assertPage($this->web('GET', '/port'), 'port after trade', ['you carry 5 / 50']);
    }

    public function testAdminNpcAndAlignmentPages(): void
    {
        $player = $this->makePlayer('Subject', ['sector' => 5]);
        $npc = $this->svc('npcService')->spawn('free', ['sector' => 5, 'controller' => 'llm', 'name' => 'Kestrel']);
        $id = $npc['ship_id'];
        $wake = '11111111-2222-4333-8444-555555555555';
        $this->db()->execute("INSERT INTO npc_action_log (ship_id, wake_id, step, tool, result) VALUES (:i, CAST(:w AS UUID), 0, '_observation', CAST(:r AS JSONB))",
            ['i' => $id, 'w' => $wake, 'r' => json_encode(['text' => 'TURN BUDGET: 99 turns'])]);
        $this->db()->execute("INSERT INTO npc_action_log (ship_id, wake_id, step, model, tool, arguments, result) VALUES (:i, CAST(:w AS UUID), 1, 'mock/m', 'go_to', CAST(:a AS JSONB), CAST(:r AS JSONB))",
            ['i' => $id, 'w' => $wake, 'a' => json_encode(['sector' => 6]), 'r' => json_encode(['ok' => true])]);
        $this->db()->execute("INSERT INTO npc_action_log (ship_id, wake_id, step, tool, result) VALUES (:i, CAST(:w AS UUID), 2, 'end_turn', CAST(:r AS JSONB))",
            ['i' => $id, 'w' => $wake, 'r' => json_encode(['ok' => true, 'summary' => 'Scouted'])]);

        $r = $this->web('POST', '/admin/login', ['csrf_token' => $this->csrf('/admin/login'), 'password' => 'secret']);
        $this->assertSame('/admin', $r['location'], 'admin login with the default password');
        $this->assertPage($this->web('GET', '/admin'), 'dashboard', ['NPCs']);
        $list = $this->web('GET', '/admin/npcs');
        $this->assertPage($list, 'npc list', ['Kestrel', 'Faction advantages', 'Merchant Guild', 'fallback']);
        $detail = $this->web('GET', '/admin/npcs/' . $id);
        $this->assertPage($detail, 'npc detail', ['Persona', 'Wake now', 'Notebook', 'Scouted', 'Replay']);
        $this->assertPage($this->web('GET', "/admin/npcs/$id/wakes/$wake"), 'replay', ['TURN BUDGET: 99 turns', 'go_to', 'Scouted']);
        $global = $this->web('GET', '/admin/npcs/global');
        $this->assertPage($global, 'global', ['Worker heartbeat', 'Enable LLM control', 'OpenRouter error rate']);

        // Edit persona/model, switch controller, wake now, toggle the LLM switch.
        $csrf = $this->csrf('/admin/npcs/' . $id);
        $this->web('POST', "/admin/npcs/$id/update", ['csrf_token' => $csrf, 'controller' => 'scripted', 'model' => 'x/y', 'fallback_models' => 'a/b, c/d',
            'temperament' => 'wry', 'speech_style' => 'dry', 'goals' => "Trade.\nStay alive."]);
        $profile = $this->svc('npcService')->profile($id);
        $this->assertSame('scripted', $profile['controller']);
        $this->assertSame('x/y', $profile['model']);
        $this->assertSame(['Trade.', 'Stay alive.'], $profile['persona']['goals']);
        $this->assertSame(['a/b', 'c/d'], $profile['persona']['fallback_models']);
        $this->web('POST', "/admin/npcs/$id/update", ['csrf_token' => $csrf, 'controller' => 'llm', 'model' => 'x/y', 'goals' => 'Trade.']);
        $this->web('POST', "/admin/npcs/$id/wake", ['csrf_token' => $csrf]);
        $this->assertSame(1, (int)$this->db()->fetchOne("SELECT COUNT(*) AS c FROM npc_events WHERE ship_id = :i AND kind = 'admin_wake'", ['i' => $id])['c']);
        $csrfG = $this->csrf('/admin/npcs/global');
        $this->web('POST', '/admin/npcs/global', ['csrf_token' => $csrfG, 'action' => 'enable_llm']);
        $this->assertTrue($this->freshSettings()->llmEnabled());
        $this->web('POST', '/admin/npcs/global', ['csrf_token' => $csrfG, 'action' => 'kill_switch']);
        $this->assertFalse($this->freshSettings()->llmEnabled(), 'kill switch');
        $this->web('POST', '/admin/npcs/global', ['csrf_token' => $csrfG, 'action' => 'save', 'default_model' => 'm/n', 'daily_budget_usd' => '0.5', 'global_daily_budget_usd' => '5', 'wake_interval_min' => '15']);
        $this->assertSame('m/n', $this->freshSettings()->get('default_model'));
        $this->assertSame(0.5, $this->freshSettings()->get('daily_budget_usd'));
        $this->assertSame(15, $this->freshSettings()->get('wake_interval_min'));

        // Alignment tools: view, adjust (reason mandatory), clear Wanted.
        $this->svc('alignmentService')->apply($player, -300, 'bad');
        $this->svc('alignmentService')->setWanted($player, 'test');
        $page = $this->web('GET', "/admin/players/$player/alignment");
        $this->assertPage($page, 'alignment admin', ['-300', 'Outlaw', 'YES', 'Adjust alignment']);
        $csrfA = $this->csrf("/admin/players/$player/alignment");
        $this->web('POST', "/admin/players/$player/alignment", ['csrf_token' => $csrfA, 'delta' => '100', 'reason' => '']);
        $this->assertSame(-300, $this->alignmentOf($player), 'a reason is mandatory');
        $this->web('POST', "/admin/players/$player/alignment", ['csrf_token' => $csrfA, 'delta' => '100', 'reason' => 'Reversing a bug']);
        $this->assertSame(-200, $this->alignmentOf($player));
        $row = $this->db()->fetchOne("SELECT reason FROM alignment_log WHERE ship_id = :i ORDER BY id DESC LIMIT 1", ['i' => $player]);
        $this->assertContains('Reversing a bug', $row['reason']);
        $this->web('POST', "/admin/players/$player/clear-wanted", ['csrf_token' => $csrfA, 'reason' => 'appeal granted']);
        $this->assertSame(null, $this->ship($player)['wanted_until']);
        $this->assertPage($this->web('GET', '/admin/players'), 'admin players', ['Alignment']);
        // Non-admin sessions are rejected.
        self::$jar = tempnam(sys_get_temp_dir(), 'bntjar');
        $r = $this->web('GET', '/admin/npcs');
        $this->assertTrue($r['status'] === 302 || str_contains($r['location'] ?? '', 'login') || str_contains($r['body'], 'Admin Login'), 'admin pages require admin auth');
    }
}
