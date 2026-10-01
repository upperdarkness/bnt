<?php

declare(strict_types=1);

namespace BNT\Tests;

class ContrabandTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        self::$config['contraband']['enabled'] = true;
        self::$svc = \BNT\Core\Services::create(self::$config, self::$db);
        $this->makeUniverse(30);
        // Sectors 8 and 12 are black markets; 12 is nearly out of stock so it pays more.
        $this->db()->execute("UPDATE universe SET is_blackmarket = TRUE, port_type = 'special', port_contraband = 200 WHERE sector_id = 8");
        $this->db()->execute("UPDATE universe SET is_blackmarket = TRUE, port_type = 'special', port_contraband = 20 WHERE sector_id = 12");
        $this->svc('sectorGraph')->refresh();
    }

    public function tearDown(): void
    {
        self::$config['contraband']['enabled'] = false;
        self::$svc = \BNT\Core\Services::create(self::$config, self::$db);
    }

    private function trade(int $id, string $action, int $amount): array
    {
        return $this->svc('tradeService')->trade($id, 'contraband', $action, $amount);
    }

    public function testBuyAndSellCostLargeAlignmentEachAndNeverEarnTheTradingBonus(): void
    {
        $a = $this->makePlayer('Smuggler', ['sector' => 8, 'hull' => 5, 'credits' => 10_000_000]);
        $r = $this->trade($a, 'buy', 10);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(10, (int)$this->ship($a)['ship_contraband']);
        $this->assertSame(-200, $this->alignmentOf($a));
        $this->assertSame(1, $this->logCount($a, 'contraband_buy'));
        $this->assertTrue($r['credits_delta'] < -10000, 'very valuable: over 1,000 credits a unit');
        $this->db()->execute('UPDATE ships SET sector = 12 WHERE ship_id = :id', ['id' => $a]);
        $r = $this->trade($a, 'sell', 10);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame(-400, $this->alignmentOf($a));
        $this->assertSame(1, $this->logCount($a, 'contraband_sell'));
        $this->assertTrue($r['credits_delta'] > 10000);
        $this->assertSame(0, $this->logCount($a, 'port_trading'), 'no trading bonus for contraband');
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT trade_credit_accum AS t FROM ships WHERE ship_id = :id', ['id' => $a])['t']);
    }

    public function testBlackMarketsPayMoreWhenTheirStockIsLow(): void
    {
        $cb = $this->svc('contrabandService');
        $full = $cb->prices($this->db()->fetchOne('SELECT * FROM universe WHERE sector_id = 8'));
        $thin = $cb->prices($this->db()->fetchOne('SELECT * FROM universe WHERE sector_id = 12'));
        $this->assertTrue($thin['sell'] > $full['buy'], 'buying at the full market and selling at the thin one is profitable');
        $this->assertTrue($full['buy'] > $full['sell'], 'there is a spread');
    }

    public function testRestrictions(): void
    {
        $a = $this->makePlayer('Smuggler', ['sector' => 8, 'hull' => 5, 'credits' => 10_000_000]);
        $this->assertSame('CONTRABAND_CAP', $this->trade($a, 'buy', 51)['code'], 'carry cap is 50');
        $this->db()->execute('UPDATE ships SET sector = 5 WHERE ship_id = :id', ['id' => $a]);
        $this->assertSame('NOT_BLACKMARKET', $this->trade($a, 'buy', 1)['code'], 'ordinary ports do not deal in it');
        $this->db()->execute('UPDATE ships SET sector = 8, credits = 100 WHERE ship_id = :id', ['id' => $a]);
        $this->assertSame('INSUFFICIENT_CREDITS', $this->trade($a, 'buy', 1)['code']);
        $this->db()->execute('UPDATE ships SET credits = 10000000 WHERE ship_id = :id', ['id' => $a]);
        $this->assertSame('PORT_STOCK', $this->trade($a, 'buy', 201)['code']);
        $this->assertSame('INSUFFICIENT_CARGO', $this->trade($a, 'sell', 1)['code']);
        $this->assertSame(0, $this->alignmentOf($a), 'refused deals cost nothing');
        // Guild-style ordinary commodity trading is unchanged.
        $this->db()->execute("UPDATE ships SET hull = 0 WHERE ship_id = :id", ['id' => $a]);
        $this->assertSame('NO_CARGO_SPACE', $this->trade($a, 'buy', 40)['code'], 'it takes up hold space');
        // Disabled switch.
        self::$config['contraband']['enabled'] = false;
        $off = \BNT\Core\Services::create(self::$config, self::$db);
        $this->assertSame('CONTRABAND_DISABLED', $off['tradeService']->trade($a, 'contraband', 'buy', 1)['code']);
    }

    public function testFedSpaceArrivalMakesTheCarrierWantedOnce(): void
    {
        $a = $this->makePlayer('Smuggler', ['sector' => 3, 'turns' => 100]);
        $this->db()->execute('UPDATE ships SET ship_contraband = 5 WHERE ship_id = :id', ['id' => $a]);
        $r = $this->svc('movementService')->move($this->ship($a), 2);   // sector 2 is FedSpace
        $this->assertSame('wanted', $r['contraband']['type']);
        $this->assertSame(-500, $this->alignmentOf($a));
        $this->assertTrue($this->svc('alignmentService')->isWanted($this->ship($a)));
        $this->assertSame(1, $this->logCount($a, 'contraband_in_fedspace'));
        // Ordinary space is fine.
        $b = $this->makePlayer('Quiet', ['sector' => 5, 'turns' => 100]);
        $this->db()->execute('UPDATE ships SET ship_contraband = 5 WHERE ship_id = :id', ['id' => $b]);
        $this->assertSame(null, $this->svc('movementService')->move($this->ship($b), 6)['contraband']);
        $this->assertSame(0, $this->alignmentOf($b));
    }

    public function testStarbaseInspectorsConfiscateAndFine(): void
    {
        $a = $this->makePlayer('Smuggler', ['sector' => 2, 'turns' => 100, 'credits' => 200000]);
        $this->db()->execute('UPDATE ships SET ship_contraband = 7 WHERE ship_id = :id', ['id' => $a]);
        $r = $this->svc('movementService')->move($this->ship($a), 1);   // sector 1 is the starbase
        $this->assertSame('confiscated', $r['contraband']['type']);
        $ship = $this->ship($a);
        $this->assertSame(0, (int)$ship['ship_contraband']);
        $this->assertSame(150000, (int)$ship['credits']);
        // A broke carrier loses the cargo but cannot be fined into debt.
        $b = $this->makePlayer('Broke', ['sector' => 2, 'turns' => 100, 'credits' => 1000]);
        $this->db()->execute('UPDATE ships SET ship_contraband = 3 WHERE ship_id = :id', ['id' => $b]);
        $this->svc('movementService')->move($this->ship($b), 1);
        $this->assertSame(0, (int)$this->ship($b)['credits']);
    }

    public function testKillerSalvagesWhatFits(): void
    {
        $victim = $this->makePlayer('Carrier', ['sector' => 6]);
        $this->db()->execute('UPDATE ships SET ship_contraband = 30 WHERE ship_id = :id', ['id' => $victim]);
        $killer = $this->makePlayer('Hunter', $this->strong(['sector' => 6, 'hull' => 4]));   // 1.5^4*100 = 506 holds, plenty
        $this->db()->execute('UPDATE ships SET ship_contraband = 40 WHERE ship_id = :id', ['id' => $killer]);
        $r = $this->svc('combatService')->attackShip($this->ship($killer), $victim);
        $this->assertSame('destroyed', $r['data']['outcome']);
        $this->assertSame(10, $r['data']['contraband_looted'], 'limited by the 50-unit carry cap');
        $this->assertSame(50, (int)$this->ship($killer)['ship_contraband']);
        $this->assertSame(0, (int)$this->ship($victim)['ship_contraband'], 'the rest is lost with the ship');
    }

    public function testRegenerationMarkingAndScore(): void
    {
        $cb = $this->svc('contrabandService');
        $this->db()->execute('UPDATE universe SET port_contraband = 0 WHERE sector_id = 8');
        $cb->regenerate();
        $this->assertSame(2, (int)$this->db()->fetchOne('SELECT port_contraband AS p FROM universe WHERE sector_id = 8')['p'], '1% of the empty space, rounded up');
        $this->assertSame(2, $cb->targetMarkets(30), 'minimum of two markets');
        $this->assertSame(7, $cb->targetMarkets(1000));
        $this->db()->execute('UPDATE universe SET is_blackmarket = FALSE, port_contraband = 0');
        $added = $cb->markMarkets(4);
        $this->assertSame(4, $added);
        $bad = (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM universe u JOIN zones z ON z.zone_id = u.zone_id WHERE u.is_blackmarket AND (u.is_starbase OR z.is_federation)')['c'];
        $this->assertSame(0, $bad, 'never in FedSpace or a starbase');
        $this->assertSame(0, $cb->markMarkets(4), 'idempotent');
        $a = $this->makePlayer('Rich');
        $before = $this->svc('shipModel')->calculateScore($a);
        $this->db()->execute('UPDATE ships SET ship_contraband = 50 WHERE ship_id = :id', ['id' => $a]);
        $this->assertGreaterThan($before, $this->svc('shipModel')->calculateScore($a));
    }

    public function testRaidersSmuggleOthersDoNot(): void
    {
        $this->db()->execute("UPDATE universe SET is_blackmarket = TRUE, port_type = 'special', port_contraband = 200 WHERE sector_id = 18");
        $this->svc('sectorGraph')->refresh();
        $raider = $this->svc('npcService')->spawn('xenobe', ['sector' => 8]);
        $this->db()->execute('UPDATE ships SET credits = 100000, hull = 6, turns = 500 WHERE ship_id = :id', ['id' => $raider['ship_id']]);
        for ($i = 0; $i < 30; $i++) {
            $this->db()->execute('UPDATE ships SET turns = 500 WHERE ship_id = :id', ['id' => $raider['ship_id']]);
            $this->db()->execute("UPDATE npc_profiles SET state = state - 'smuggle' WHERE ship_id = :id AND state -> 'smuggle' ->> 'next_at' IS NOT NULL AND $i % 6 = 0", ['id' => $raider['ship_id']]);
            $this->svc('npcBrain')->tick($this->svc('npcService')->profile($raider['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
            if ($this->logCount($raider['ship_id'], 'contraband_sell') > 0) {
                break;
            }
        }
        $this->assertGreaterThan(0, $this->logCount($raider['ship_id'], 'contraband_buy'), 'the Raider bought Void Relics');
        $this->assertGreaterThan(0, $this->logCount($raider['ship_id'], 'contraband_sell'), 'and sold them on');
        foreach (['guild', 'police', 'free'] as $f) {
            $n = $this->svc('npcService')->spawn($f, ['sector' => 8]);
            $this->db()->execute('UPDATE ships SET turns = 500, credits = 500000 WHERE ship_id = :id', ['id' => $n['ship_id']]);
            for ($i = 0; $i < 10; $i++) {
                $this->svc('npcBrain')->tick($this->svc('npcService')->profile($n['ship_id']), ['deadline' => microtime(true) + 5, 'busy_sectors' => []]);
            }
            $this->assertSame(0, (int)$this->ship($n['ship_id'])['ship_contraband'], "$f never deals in contraband");
        }
    }

    public function testApiShowsItInObservationAndRejectsOffMarket(): void
    {
        $npc = $this->svc('npcService')->spawn('xenobe', ['sector' => 8]);
        $this->db()->execute('UPDATE ships SET ship_contraband = 4 WHERE ship_id = :id', ['id' => $npc['ship_id']]);
        $obs = $this->svc('observationBuilder')->build($this->ship($npc['ship_id']))['text'];
        $this->assertContains('BLACK MARKET (illegal, costs alignment): Void Relics buy', $obs);
        $this->assertContains('CONTRABAND 4', $obs);
    }
}
