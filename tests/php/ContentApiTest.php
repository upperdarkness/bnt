<?php

declare(strict_types=1);

namespace BNT\Tests;

/** REST endpoints for protection, rumours, news and the press NPC's agent endpoints. */
class ContentApiTest extends DbTestCase
{
    private static int $port = 0;

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
                'NPC_GRAPH_CACHE' => sys_get_temp_dir() . '/bnt_content_graph_' . self::$dbName . '.json',
                'RUMOURS_ENABLED' => 'true', 'NEWS_JOURNALIST_ENABLED' => 'true', 'NEWS_REVIEW_MODE' => 'false',
            ], $root);
        } catch (\Throwable $e) {
            self::$skip = $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        HttpServers::stop(self::$port);
        @unlink(sys_get_temp_dir() . '/bnt_content_graph_' . self::$dbName . '.json');
        parent::tearDownAfterClass();
    }

    public function setUp(): void
    {
        parent::setUp();
        self::$config['rumours']['enabled'] = true;
        self::$config['news']['journalist_enabled'] = true;
        self::$config['news']['review_mode'] = false;
        self::$svc = \BNT\Core\Services::create(self::$config, self::$db);
        $this->makeUniverse(30);
    }

    public function tearDown(): void
    {
        self::$config['rumours']['enabled'] = false;
        self::$config['news']['journalist_enabled'] = false;
        self::$config['news']['review_mode'] = true;
        self::$svc = \BNT\Core\Services::create(self::$config, self::$db);
    }

    private function http(string $method, string $path, ?string $token = null, ?array $body = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $headers = ['Accept: application/json'];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
        $raw = (string)curl_exec($ch);
        return ['status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'json' => json_decode($raw, true)];
    }

    private function player(string $name, array $over = []): array
    {
        $id = $this->makePlayer($name, $over);
        return [$id, $this->svc('apiAuth')->generateToken($id, 't')['token']];
    }

    private function rookie(string $name, array $over = []): array
    {
        return $this->player($name, $over + ['protection_state' => 'protected']);
    }

    private function pressToken(): array
    {
        $id = $this->svc('pressService')->reporterId();
        return [$id, $this->svc('npcService')->issueToken($id)['token']];
    }

    // ------------------------------------------------------------ protection

    public function testProtectionStateAndOptOut(): void
    {
        [$id, $tok] = $this->rookie('Rookie', ['active_days' => 6, 'score' => 4210]);
        $r = $this->http('GET', '/api/v1/game/protection', $tok);
        $this->assertSame(200, $r['status']);
        $d = $r['json']['data'];
        $this->assertSame('protected', $d['state']);
        $this->assertTrue($d['protected']);
        $this->assertSame(6, $d['active_days']);
        $this->assertSame(14, $d['active_days_needed']);
        $this->assertSame(10000, $d['score_needed']);
        $this->assertSame('Protected: 6 of 14 active days, score 4,210 of 10,000', $d['text']);
        $this->assertSame(400, $this->http('POST', '/api/v1/game/protection/opt-out', $this->player('Vet')[1])['status'], 'a veteran has nothing to give up');
        $this->assertSame(200, $this->http('POST', '/api/v1/game/protection/opt-out', $tok)['status']);
        $this->assertSame('none', $this->ship($id)['protection_state']);
        $this->assertFalse($this->http('GET', '/api/v1/game/protection', $tok)['json']['data']['protected']);
    }

    public function testProtectedTargetsAndAttackersOverTheApi(): void
    {
        [$rid, $rtok] = $this->rookie('Rookie', ['sector' => 8, 'beams' => 12, 'ship_fighters' => 500]);
        [$vid, $vtok] = $this->player('Vet', $this->strong(['sector' => 8]));
        $r = $this->http('POST', "/api/v1/game/attack/ship/$rid", $vtok);
        $this->assertSame(403, $r['status']);
        $this->assertSame('PROTECTED_TARGET', $r['json']['error']['code']);
        // The protected player is told what attacking costs, and must confirm.
        $this->db()->execute("UPDATE ships SET protection_state = 'none' WHERE ship_id = :id", ['id' => $vid]);
        $r = $this->http('POST', "/api/v1/game/attack/ship/$vid", $rtok);
        $this->assertSame(409, $r['status']);
        $this->assertSame('PROTECTION_CONFIRM', $r['json']['error']['code']);
        $this->assertSame('protected', $this->ship($rid)['protection_state']);
        $r = $this->http('POST', "/api/v1/game/attack/ship/$vid", $rtok, ['confirm_end_protection' => true]);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertSame('none', $this->ship($rid)['protection_state']);
        // Others see the shield (never the internals) in sector lists.
        [, $obs] = $this->rookie('Watcher', ['sector' => 9]);
        [$pid] = $this->rookie('Pup', ['sector' => 9]);
        $scan = $this->http('GET', '/api/v1/game/scan', $obs);
        $ships = $scan['json']['data']['ships_in_sector'];
        $this->assertTrue($ships[0]['protected']);
        $this->assertTrue(!array_key_exists('protection_state', $ships[0]));
    }

    public function testMinefieldTurnsBackAProtectedShip(): void
    {
        [$oid, $otok] = $this->player('Owner', $this->strong(['sector' => 7, 'torps' => 20]));
        $this->svc('combatService')->deployDefence($this->ship($oid), 'F', 100);
        [$rid, $rtok] = $this->rookie('Scout', ['sector' => 6, 'turns' => 20]);
        $r = $this->http('POST', '/api/v1/game/move/7', $rtok);
        $this->assertSame(400, $r['status']);
        $this->assertSame('DEFENCES_BLOCK_PROTECTED', $r['json']['error']['code']);
        $this->assertSame(6, (int)$this->ship($rid)['sector']);
    }

    // ------------------------------------------------------------ rumours

    public function testRumourOffersBuyLogAndLimit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $st = $this->db()->getConnection()->prepare("INSERT INTO rumour_seeds (type, shown_facts, true_facts, truth_state, expires_at) VALUES ('rich_world', CAST(:s AS JSONB), CAST(:s AS JSONB), 'true', now() + interval '5 hours')");
            $st->execute(['s' => json_encode(['sector' => 100 + $i])]);
        }
        [$id, $tok] = $this->player('Buyer', ['sector' => 5, 'credits' => 100000, 'turns' => 50]);
        $o = $this->http('GET', '/api/v1/game/rumours', $tok)['json']['data'];
        $this->assertTrue($o['port'] && $o['enabled']);
        $this->assertSame(1000, $o['tavern']['price']);
        $this->assertSame(10000, $o['informant']['price']);
        $this->assertSame(400, $this->http('POST', '/api/v1/game/rumours/buy', $tok, ['tier' => 'nope'])['status'], 'invalid tier is a client error');
        $r = $this->http('POST', '/api/v1/game/rumours/buy', $tok, ['tier' => 'informant']);
        $this->assertSame(200, $r['status'], json_encode($r['json']));
        $this->assertContains('sector 10', $r['json']['data']['rumour']['text']);
        $this->assertSame(90000, (int)$this->ship($id)['credits']);
        $this->http('POST', '/api/v1/game/rumours/buy', $tok, ['tier' => 'tavern']);
        $this->http('POST', '/api/v1/game/rumours/buy', $tok, ['tier' => 'tavern']);
        $r = $this->http('POST', '/api/v1/game/rumours/buy', $tok, ['tier' => 'tavern']);
        $this->assertSame(429, $r['status']);
        $this->assertSame('RUMOUR_LIMIT', $r['json']['error']['code']);
        $log = $this->http('GET', '/api/v1/game/rumours/log', $tok)['json']['data']['rumours'];
        $this->assertCount(3, $log);
        $this->assertSame(null, $log[0]['outcome']);
    }

    // ------------------------------------------------------------ news

    public function testNewsEndpointFiltersBySource(): void
    {
        [, $tok] = $this->player('Reader');
        $this->db()->execute("INSERT INTO news (headline, newstext, user_id, news_type, source, status) VALUES ('Wire item', 'x', NULL, 'ship_destroyed', 'system', 'published')");
        $press = $this->svc('pressService')->reporterId();
        $this->db()->execute("INSERT INTO news (headline, newstext, user_id, news_type, source, status) VALUES ('Courier item', 'y', :p, 'journalist', 'journalist', 'published')", ['p' => $press]);
        $this->db()->execute("INSERT INTO news (headline, newstext, user_id, news_type, source, status) VALUES ('Hidden draft', 'z', :p, 'journalist', 'journalist', 'pending_review')", ['p' => $press]);
        $all = $this->http('GET', '/api/v1/game/news', $tok)['json']['data']['news'];
        $this->assertSame(['Courier item', 'Wire item'], array_column($all, 'headline'), 'pending stories are not public');
        $j = $this->http('GET', '/api/v1/game/news?source=journalist', $tok)['json']['data']['news'];
        $this->assertSame(['Courier item'], array_column($j, 'headline'));
        $this->assertContains('Talia Venn', $j[0]['byline']);
    }

    public function testPressEndpointsAcceptOnlyThePressToken(): void
    {
        [, $human] = $this->player('Human');
        foreach (['GET /api/v1/agent/news/candidates', 'GET /api/v1/agent/rumours/pool-status', 'POST /api/v1/agent/news/stories', 'POST /api/v1/agent/rumours/lines'] as $call) {
            [$m, $p] = explode(' ', $call);
            $this->assertSame(403, $this->http($m, $p, $human, $m === 'POST' ? ['x' => 1] : null)['status'], "$call as a human");
        }
        $guild = $this->svc('npcService')->spawn('guild', ['sector' => 5]);
        $gtok = $this->svc('npcService')->issueToken($guild['ship_id'])['token'];
        foreach (['GET /api/v1/agent/news/candidates', 'GET /api/v1/agent/rumours/pool-status'] as $call) {
            [$m, $p] = explode(' ', $call);
            $this->assertSame(403, $this->http($m, $p, $gtok)['status'], "$call as another NPC");
        }
        [, $ptok] = $this->pressToken();
        $this->assertSame(200, $this->http('GET', '/api/v1/agent/news/candidates', $ptok)['status']);
        $this->assertSame(200, $this->http('GET', '/api/v1/agent/rumours/pool-status', $ptok)['status']);
    }

    public function testPressWorkflowCandidatesStoriesAndLines(): void
    {
        [, $ptok] = $this->pressToken();
        $vex = $this->makePlayer('Vex', ['score' => 60000, 'sector' => 8]);
        $pete = $this->makePlayer('Pete', ['score' => 60000, 'sector' => 8]);
        $this->db()->execute("INSERT INTO attack_logs (attacker_id, attacker_name, defender_id, defender_name, attack_type, result, damage_dealt, sector) VALUES (:a, 'Vex', :d, 'Pete', 'ship', 'destroyed', 500, 8)", ['a' => $vex, 'd' => $pete]);
        $this->svc('newsService')->scan();
        $this->db()->execute("UPDATE news_candidates SET status = 'open'");
        $cands = $this->http('GET', '/api/v1/agent/news/candidates', $ptok)['json']['data'];
        $this->assertCount(1, $cands['candidates']);
        $c = $cands['candidates'][0];
        $this->assertSame('<<<Vex>>>', $c['slots']['attacker'], 'player names are delimited');
        $this->assertSame(['attacker', 'defender'], $c['required_slots']);
        $this->assertSame(80, $cands['limits']['headline_chars']);
        $bad = $this->http('POST', '/api/v1/agent/news/stories', $ptok, ['candidate_id' => $c['id'], 'headline' => 'Twelve dead', 'body' => '{{attacker}} killed {{defender}} in sector 5.']);
        $this->assertSame(422, $bad['status']);
        $this->assertSame('STORY_REJECTED', $bad['json']['error']['code']);
        $this->assertTrue(count($bad['json']['error']['details']['errors']) > 0);
        $good = $this->http('POST', '/api/v1/agent/news/stories', $ptok, ['candidate_id' => $c['id'], 'headline' => 'Clash at {{sector}}', 'body' => '{{attacker}} destroyed the ship of {{defender}} at {{sector}}.']);
        $this->assertSame(200, $good['status'], json_encode($good['json']));
        $this->assertSame('published', $good['json']['data']['status']);
        $this->assertSame(404, $this->http('POST', '/api/v1/agent/news/stories', $ptok, ['candidate_id' => 9999, 'headline' => 'x', 'body' => '{{attacker}}'])['status']);

        $pool = $this->http('GET', '/api/v1/agent/rumours/pool-status', $ptok)['json']['data'];
        $this->assertSame(6, $pool['pool']['fat_cargo']['needed']);
        $this->assertSame(['name', 'sector'], $pool['pool']['wanted_sighting']['slots']);
        $res = $this->http('POST', '/api/v1/agent/rumours/lines', $ptok, ['lines' => [
            ['type' => 'fat_cargo', 'text' => 'A heavy hauler was working near {{sector}}.'],
            ['type' => 'fat_cargo', 'text' => 'A heavy hauler worked near sector 5.'],
        ]]);
        $this->assertSame(1, $res['json']['data']['accepted']);
        $this->assertCount(1, $res['json']['data']['rejected']);
    }

    public function testObservationMarksProtectedShipsAndTheSwitchesGateTheFeatures(): void
    {
        $npc = $this->svc('npcService')->spawn('free', ['sector' => 8]);
        $ntok = $this->svc('npcService')->issueToken($npc['ship_id'])['token'];
        $this->rookie('Pup', ['sector' => 8]);
        $obs = $this->http('GET', '/api/v1/agent/observation', $ntok)['json']['data']['observation'];
        $this->assertContains('PROTECTED(cannot be attacked)', $obs);
        $this->assertContains('type=balanced', $obs);
    }
}
