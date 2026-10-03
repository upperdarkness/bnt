<?php

declare(strict_types=1);

namespace BNT\Tests;

class RumourTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        self::$config['rumours']['enabled'] = true;
        self::$svc = \BNT\Core\Services::create(self::$config, self::$db);
        $this->makeUniverse(40);
        // Sector 5: ordinary port. Sector 1: the starbase. Zone 4 (Raider space) holds sector 30.
        $this->db()->execute("UPDATE universe SET zone_id = 4 WHERE sector_id = 30");
        $this->svc('sectorGraph')->refresh();
    }

    public function tearDown(): void
    {
        self::$config['rumours']['enabled'] = false;
        self::$svc = \BNT\Core\Services::create(self::$config, self::$db);
    }

    private function r(): \BNT\Services\RumourService
    {
        return $this->svc('rumourService');
    }

    private function seed(string $type, string $state, array $shown, array $true, string $expires = '+6 hours'): int
    {
        $st = $this->db()->getConnection()->prepare(
            "INSERT INTO rumour_seeds (type, shown_facts, true_facts, truth_state, expires_at)
             VALUES (:t, CAST(:s AS JSONB), CAST(:r AS JSONB), :st, now() + CAST(:e AS INTERVAL)) RETURNING id"
        );
        $st->execute(['t' => $type, 's' => json_encode($shown), 'r' => json_encode($true), 'st' => $state, 'e' => ltrim($expires, '+')]);
        return (int)$st->fetch()['id'];
    }

    // ------------------------------------------------------------ truth model

    public function testTruthSplitOverTenThousandDrawsMatchesConfigWithinTwoPoints(): void
    {
        mt_srand(2026);
        $cases = [
            'default port' => [false, false, 'tavern', ['true' => 60, 'stale' => 25, 'false' => 15]],
            'starbase' => [true, false, 'tavern', ['true' => 75, 'stale' => 15.625, 'false' => 9.375]],
            'raider zone' => [false, true, 'tavern', ['true' => 45, 'stale' => 34.375, 'false' => 20.625]],
            'informant' => [false, false, 'informant', ['true' => 75, 'stale' => 15.625, 'false' => 9.375]],
        ];
        foreach ($cases as $label => [$starbase, $raider, $tier, $expect]) {
            $counts = ['true' => 0, 'stale' => 0, 'false' => 0];
            $pct = $this->r()->truePercent($starbase, $raider, $tier);
            for ($i = 0; $i < 10000; $i++) {
                $counts[$this->r()->drawState($pct, mt_rand(0, 999999) / 10000)]++;
            }
            foreach ($expect as $state => $want) {
                $got = $counts[$state] / 100;
                $this->assertTrue(abs($got - $want) <= 2.0, "$label: $state was $got%, expected $want% (+-2)");
            }
        }
        // The base assignment of freshly built seeds follows the configured 60/25/15.
        $counts = ['true' => 0, 'stale' => 0, 'false' => 0];
        for ($i = 0; $i < 10000; $i++) {
            $counts[$this->r()->assignTruth(mt_rand(0, 999999) / 10000)]++;
        }
        $this->assertTrue(abs($counts['true'] / 100 - 60) <= 2 && abs($counts['stale'] / 100 - 25) <= 2 && abs($counts['false'] / 100 - 15) <= 2, json_encode($counts));
        $this->assertSame(90.0, $this->r()->truePercent(true, false, 'informant'), 'the informant adds 15 points');
    }

    public function testPerturbationMovesTheSectorTwoToFiveHopsOrPicksADecoy(): void
    {
        $graph = $this->svc('sectorGraph');
        for ($i = 0; $i < 60; $i++) {
            $moved = $this->r()->walk(10, mt_rand(2, 5));
            $this->assertTrue($moved !== 10);
            $path = $graph->path(10, $moved, 6);
            $this->assertTrue($path !== null && count($path) - 1 <= 5, 'within five hops');
        }
        $shown = $this->r()->perturb('rich_world', ['planet_id' => 1, 'sector' => 10]);
        $this->assertTrue($shown['sector'] !== 10);
    }

    // ------------------------------------------------------------ seed building from game state

    public function testSeedsAreBuiltFromRealStateAndNeverConcernProtectedPlayers(): void
    {
        $rich = $this->makePlayer('Hauler', ['sector' => 12, 'ship_goods' => 60000, 'hull' => 20]);
        $this->db()->execute('INSERT INTO movement_log (ship_id, sector_id) VALUES (:s, 12)', ['s' => $rich]);
        $rookie = $this->makePlayer('Rookie Hauler', ['sector' => 13, 'ship_goods' => 90000, 'protection_state' => 'protected']);
        $this->db()->execute('INSERT INTO movement_log (ship_id, sector_id) VALUES (:s, 13)', ['s' => $rookie]);
        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, fighters) VALUES ('Weak', 14, :o, 3)", ['o' => $rich]);
        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, fighters) VALUES ('Rookie World', 15, :o, 3)", ['o' => $rookie]);
        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, ore, organics, goods, energy, colonists) VALUES ('Empty', 16, NULL, 9000, 9000, 9000, 9000, 9000)");
        $this->db()->execute("INSERT INTO planets (planet_name, sector_id, owner, ore, organics, goods, energy, colonists) VALUES ('Barren', 17, NULL, 0, 0, 0, 0, 0)");
        $outlaw = $this->makePlayer('Vex', ['sector' => 20]);
        $this->svc('alignmentService')->apply($outlaw, -1500, 'bad');
        $this->svc('alignmentService')->setWanted($outlaw, 'test', 21);
        for ($i = 0; $i < 3; $i++) {
            $this->svc('npcService')->spawn('xenobe', ['sector' => 25]);
        }
        $fat = $this->r()->candidates('fat_cargo');
        $this->assertSame([12], array_column($fat, 'sector'), 'only the unprotected ship with a fat hold, seen moving');
        $soft = $this->r()->candidates('soft_target');
        $this->assertSame([14], array_column($soft, 'sector'), 'a protected player\'s planet is never offered');
        $wanted = array_values(array_filter($this->r()->candidates('wanted_sighting'), fn($c) => $c['name'] === 'Vex'));
        $this->assertSame([21], array_column($wanted, 'sector'));
        $this->assertSame([25], array_column($this->r()->candidates('raider_nest'), 'sector'));
        $this->assertSame([16], array_column($this->r()->candidates('rich_world'), 'sector'));
        // Generation stores the truth state and an expiry per type.
        $made = $this->r()->generate();
        $this->assertGreaterThan(0, array_sum($made['created']));
        foreach ($this->db()->fetchAll('SELECT * FROM rumour_seeds') as $s) {
            $expect = in_array($s['type'], ['fat_cargo', 'wanted_sighting'], true) ? 6 : 24;
            $hours = (strtotime($s['expires_at']) - time()) / 3600;
            $this->assertTrue(abs($hours - $expect) < 0.1, $s['type'] . " expires after $expect hours");
            $this->assertTrue(in_array($s['truth_state'], ['true', 'stale', 'false'], true));
            $shown = json_decode($s['shown_facts'], true);
            $this->assertTrue(!isset($shown['ship_id']) && !isset($shown['owner_id']) && !isset($shown['planet_id']), 'buyers are never shown entity ids');
        }
        $seen = $this->db()->fetchAll("SELECT true_facts FROM rumour_seeds");
        $this->assertNotContains('Rookie', json_encode($seen));
        // No duplicates on a second run for the same entities.
        $before = (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM rumour_seeds')['c'];
        $this->r()->generate();
        $this->assertSame($before, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM rumour_seeds')['c']);
    }

    // ------------------------------------------------------------ buying

    private function buyer(array $over = []): int
    {
        return $this->makePlayer('Buyer' . bin2hex(random_bytes(3)), $over + ['sector' => 5, 'credits' => 1000000, 'turns' => 100]);
    }

    public function testBuyingChargesATurnAndCreditsAndLogsTheRumour(): void
    {
        $this->seed('rich_world', 'true', ['sector' => 233], ['sector' => 233, 'planet_id' => 99]);
        $a = $this->buyer();
        $r = $this->r()->buy($a, 'informant');
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertContains('sector 233', $r['rumour']['text'], 'the informant gives the exact sector');
        $ship = $this->ship($a);
        $this->assertSame(1000000 - 10000, (int)$ship['credits']);
        $this->assertSame(99, (int)$ship['turns']);
        $this->seed('rich_world', 'true', ['sector' => 233], ['sector' => 233]);
        $t = $this->r()->buy($a, 'tavern');
        $this->assertTrue($t['success'], json_encode($t));
        $this->assertTrue(preg_match('/sectors (\d+) to (\d+)/', $t['rumour']['text'], $m) === 1, $t['rumour']['text']);
        $this->assertSame(9, (int)$m[2] - (int)$m[1], 'a region of ten sectors');
        $this->assertTrue((int)$m[1] <= 233 && 233 <= (int)$m[2], 'which includes the real sector');
        $this->assertSame(1000000 - 10000 - 1000, (int)$this->ship($a)['credits']);
        $this->assertCount(2, $this->r()->log($a));
    }

    public function testLimitsNeverTheSameRumourTwiceAndRefusalsCostNothing(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->seed('rich_world', 'true', ['sector' => 100 + $i], ['sector' => 100 + $i]);
        }
        $a = $this->buyer();
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->r()->buy($a, 'tavern')['success']);
        }
        $r = $this->r()->buy($a, 'tavern');
        $this->assertFalse($r['success']);
        $this->assertSame('RUMOUR_LIMIT', $r['code'], 'three per port per real day');
        $this->assertSame(3, (int)$this->db()->fetchOne('SELECT COUNT(DISTINCT seed_id) AS c FROM rumour_purchases WHERE ship_id = :a', ['a' => $a])['c'], 'never the same rumour twice');
        $this->assertSame(1000000 - 3000, (int)$this->ship($a)['credits'], 'the refused purchase was free');
        // Another port has its own allowance.
        $this->db()->execute('UPDATE ships SET sector = 6 WHERE ship_id = :a', ['a' => $a]);
        $this->assertTrue($this->r()->buy($a, 'tavern')['success']);
        // Out of unseen rumours.
        $b = $this->buyer();
        $this->db()->execute('DELETE FROM rumour_seeds');
        $this->seed('rich_world', 'true', ['sector' => 7], ['sector' => 7]);
        $this->assertTrue($this->r()->buy($b, 'tavern')['success']);
        $none = $this->r()->buy($b, 'tavern');
        $this->assertSame('NO_RUMOURS', $none['code']);
        // Validation and affordability.
        $this->assertSame('INVALID_TIER', $this->r()->buy($b, 'free')['code']);
        $poor = $this->buyer(['credits' => 500]);
        $this->assertSame('INSUFFICIENT_CREDITS', $this->r()->buy($poor, 'tavern')['code']);
        $tired = $this->buyer(['turns' => 0]);
        $this->assertSame('INSUFFICIENT_TURNS', $this->r()->buy($tired, 'tavern')['code']);
        $nowhere = $this->buyer(['sector' => 2]);   // sector 2 has no port
        $this->assertSame('NO_PORT', $this->r()->buy($nowhere, 'tavern')['code']);
        $this->assertSame(500, (int)$this->ship($poor)['credits']);
    }

    public function testRaiderZonePortsSellAtHalfPrice(): void
    {
        $this->seed('rich_world', 'true', ['sector' => 7], ['sector' => 7]);
        $this->db()->execute("UPDATE universe SET port_type = 'ore' WHERE sector_id = 30");
        $a = $this->buyer(['sector' => 30]);
        $offers = $this->r()->offers($this->ship($a));
        $this->assertSame(500, $offers['tavern']['price']);
        $this->assertSame(5000, $offers['informant']['price']);
        $this->assertTrue($this->r()->buy($a, 'tavern')['success']);
        $this->assertSame(1000000 - 500, (int)$this->ship($a)['credits']);
        $normal = $this->r()->offers($this->ship($this->buyer()));
        $this->assertSame(1000, $normal['tavern']['price']);
        $this->assertSame(10000, $normal['informant']['price']);
    }

    public function testExpiredRumoursAreNotSoldAndLogRevealsTheOutcome(): void
    {
        $this->seed('rich_world', 'true', ['sector' => 7], ['sector' => 7], '-1 hour');
        $a = $this->buyer();
        $this->assertSame('NO_RUMOURS', $this->r()->buy($a, 'tavern')['code'], 'expired rumours are off sale');
        $id = $this->seed('wanted_sighting', 'false', ['name' => 'Vex', 'sector' => 300], ['name' => 'Vex', 'sector' => 21, 'ship_id' => 0]);
        $this->assertTrue($this->r()->buy($a, 'informant')['success']);
        $log = $this->r()->log($a);
        $this->assertFalse($log[0]['expired']);
        $this->assertSame(null, $log[0]['outcome'], 'no spoilers before expiry');
        $this->db()->execute("UPDATE rumour_seeds SET expires_at = now() - interval '1 minute' WHERE id = :id", ['id' => $id]);
        $log = $this->r()->log($a);
        $this->assertTrue($log[0]['expired']);
        $this->assertSame('false', $log[0]['outcome']);
        $this->assertContains('sector 21', $log[0]['explanation']);
        $this->assertContains('Vex', $log[0]['text']);
        $this->assertContains('sector 300', $log[0]['text'], 'the buyer was told the shown (false) sector');
    }

    public function testRumoursNeverConcernAShipThatBecameProtectedAfterwards(): void
    {
        $victim = $this->makePlayer('Respawned', ['sector' => 12]);
        $this->seed('fat_cargo', 'true', ['sector' => 12], ['sector' => 12, 'ship_id' => $victim]);
        $a = $this->buyer();
        $this->db()->execute("UPDATE ships SET respawn_shield_until = now() + interval '5 hours' WHERE ship_id = :id", ['id' => $victim]);
        $this->assertSame('NO_RUMOURS', $this->r()->buy($a, 'tavern')['code']);
    }

    public function testTruthStatesAreSoldInProportionToThePlace(): void
    {
        // A pool large enough for every draw: starbase sector 1 sells 75% true rumours.
        for ($i = 0; $i < 120; $i++) {
            $this->seed('rich_world', ['true', 'stale', 'false'][$i % 3], ['sector' => 100 + $i], ['sector' => 100 + $i]);
        }
        mt_srand(7);
        $counts = ['true' => 0, 'stale' => 0, 'false' => 0];
        for ($i = 0; $i < 40; $i++) {
            $a = $this->buyer(['sector' => 1]);
            $r = $this->r()->buy($a, 'tavern');
            $this->assertTrue($r['success'], json_encode($r));
            $state = $this->db()->fetchOne('SELECT s.truth_state FROM rumour_purchases p JOIN rumour_seeds s ON s.id = p.seed_id WHERE p.id = :id', ['id' => $r['rumour']['id']])['truth_state'];
            $counts[$state]++;
        }
        $this->assertGreaterThan(24, $counts['true'], 'starbase sellers are mostly truthful: ' . json_encode($counts));
    }

    // ------------------------------------------------------------ flavour lines

    public function testSubmittedLinesAreValidatedAndQueuedForApproval(): void
    {
        $r = $this->r()->submitLines([
            ['type' => 'rich_world', 'text' => 'Nobody has settled {{sector}} and the soil there is a gift.'],
            ['type' => 'rich_world', 'text' => 'A fine world at {{sector}} awaits 3 settlers.'],
            ['type' => 'rich_world', 'text' => 'Rumour: Kraal Vesh knows about {{sector}}.'],
            ['type' => 'rich_world', 'text' => 'The soil is rich but I forgot where.'],
            ['type' => 'price_spike', 'text' => 'Pay well for {{commodity}} at {{sector}}.'],
            ['type' => 'nonsense', 'text' => 'Anything {{sector}}'],
        ]);
        $this->assertSame(2, $r['accepted']);
        $this->assertCount(4, $r['rejected']);
        $row = $this->db()->fetchOne("SELECT approved FROM rumour_lines WHERE type = 'rich_world'");
        $this->assertFalse((bool)$row['approved'], 'manual approval is on by default');
        $this->assertSame(['accepted' => 0, 'rejected' => []], array_intersect_key($this->r()->submitLines([['type' => 'rich_world', 'text' => 'Nobody has settled {{sector}} and the soil there is a gift.']]), ['accepted' => 1, 'rejected' => 1]), 'duplicates are ignored');
        $pool = $this->r()->poolStatus();
        $this->assertSame(0, $pool['rich_world']['approved']);
        $this->assertSame(1, $pool['rich_world']['pending']);
        $this->assertSame(5, $pool['rich_world']['needed'], 'pending lines count toward the pool when approval is required');
        $this->assertSame(6, $pool['fat_cargo']['needed']);
        // Approved lines are used in sales; a line that stops validating falls back to a template.
        $this->db()->execute("UPDATE rumour_lines SET approved = TRUE WHERE type = 'rich_world'");
        $this->seed('rich_world', 'true', ['sector' => 77], ['sector' => 77]);
        $a = $this->buyer();
        $bought = $this->r()->buy($a, 'informant');
        $this->assertSame('Nobody has settled sector 77 and the soil there is a gift.', $bought['rumour']['text']);
        $this->assertSame(1, (int)$this->db()->fetchOne("SELECT uses FROM rumour_lines WHERE type = 'rich_world'")['uses']);
        $this->db()->execute("UPDATE rumour_lines SET template = 'This has 9 problems at {{sector}}' WHERE type = 'rich_world'");
        $this->seed('rich_world', 'true', ['sector' => 78], ['sector' => 78]);
        $second = $this->r()->buy($a, 'informant');
        $this->assertNotContains('problems', $second['rumour']['text'], 'an invalid pool line is replaced by a template');
        $this->assertTrue(preg_match('/\d/', preg_replace('/sector \d+/', '', $second['rumour']['text'])) === 0, 'no digit outside the filled slot');
    }

    public function testDisabledSwitchMeansNoRumours(): void
    {
        self::$config['rumours']['enabled'] = false;
        $off = \BNT\Core\Services::create(self::$config, self::$db);
        $this->seed('rich_world', 'true', ['sector' => 7], ['sector' => 7]);
        $this->assertSame('RUMOURS_DISABLED', $off['rumourService']->buy($this->buyer(), 'tavern')['code']);
        $this->assertSame(['created' => array_fill_keys(\BNT\Services\RumourService::TYPES, 0), 'pruned' => 0], $off['rumourService']->generate());
    }
}
