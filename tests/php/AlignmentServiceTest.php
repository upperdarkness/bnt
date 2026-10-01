<?php

declare(strict_types=1);

namespace BNT\Tests;

class AlignmentServiceTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(12);
    }

    public function testApplyLogsEveryChangeWithReasonAndClamps(): void
    {
        $a = $this->makePlayer('Alice');
        $al = $this->svc('alignmentService');
        $this->assertSame(120, $al->apply($a, 120, 'unit_test'));
        $this->assertSame(1, $this->logCount($a, 'unit_test'));
        $row = $this->db()->fetchOne('SELECT * FROM alignment_log WHERE ship_id = :id', ['id' => $a]);
        $this->assertSame(120, (int)$row['delta']);
        $this->assertSame(120, (int)$row['new_value']);
        // Clamped at +10000: the log records the applied amount, not the requested one.
        $al->apply($a, 20000, 'big');
        $this->assertSame(10000, $this->alignmentOf($a));
        $big = $this->db()->fetchOne("SELECT delta FROM alignment_log WHERE reason = 'big'");
        $this->assertSame(9880, (int)$big['delta']);
        $al->apply($a, -50000, 'huge_negative');
        $this->assertSame(-10000, $this->alignmentOf($a));
        // Nothing to apply: no row.
        $before = $this->logCount($a);
        $al->apply($a, 0, 'noop');
        $this->assertSame($before, $this->logCount($a));
    }

    public function testDisabledSystemDoesNothing(): void
    {
        $a = $this->makePlayer('Alice');
        $config = self::$config;
        $config['alignment']['enabled'] = false;
        $svc = new \BNT\Services\AlignmentService($this->db(), self::$svc['alignmentRules'], self::$svc['bountyService'], $config);
        $this->assertSame(null, $svc->apply($a, -500, 'x'));
        $this->assertSame(0, $this->alignmentOf($a));
        $this->assertFalse($svc->isWanted(['alignment' => -5000, 'wanted_until' => null]));
    }

    public function testPirateTierIsAutomaticallyWantedWithBountyAndOneNewsItem(): void
    {
        $a = $this->makePlayer('Blackbeard');
        $al = $this->svc('alignmentService');
        $al->apply($a, -999, 'step1');
        $this->assertFalse($al->isWanted($this->ship($a)), 'Outlaw tier alone is not Wanted');
        $al->apply($a, -1, 'step2');
        $ship = $this->ship($a);
        $this->assertSame(-1000, (int)$ship['alignment']);
        $this->assertTrue($al->isWanted($ship));
        $this->assertTrue($ship['wanted_until'] !== null);
        $bounty = $this->db()->fetchOne('SELECT * FROM bounties WHERE target_id = :id AND placed_by IS NULL', ['id' => $a]);
        $this->assertSame(10000, (int)$bounty['amount'], '1,000 credits per 100 points below 0');
        $al->apply($a, -500, 'step3');
        $bounty = $this->db()->fetchAll('SELECT amount FROM bounties WHERE target_id = :id AND placed_by IS NULL', ['id' => $a]);
        $this->assertCount(1, $bounty, 'a single Federation bounty is resized, not stacked');
        $this->assertSame(15000, (int)$bounty[0]['amount']);
        $news = $this->db()->fetchAll("SELECT * FROM news WHERE news_type = 'wanted'");
        $this->assertCount(1, $news, 'Galactic News announces Wanted only when first set');
        $this->assertContains('Blackbeard', $news[0]['newstext']);
    }

    public function testPortTradingGainIsCappedPerDay(): void
    {
        $a = $this->makePlayer('Trader');
        $al = $this->svc('alignmentService');
        $al->recordTrade($a, 9999);
        $this->assertSame(0, $this->alignmentOf($a), 'under 10,000 credits earns nothing yet');
        $al->recordTrade($a, 1);
        $this->assertSame(1, $this->alignmentOf($a), 'remainders carry over');
        $al->recordTrade($a, 1_000_000);
        $this->assertSame(20, $this->alignmentOf($a), 'capped at +20 per day');
        $al->recordTrade($a, 1_000_000);
        $this->assertSame(20, $this->alignmentOf($a));
        $this->db()->execute("UPDATE alignment_log SET created_at = now() - interval '2 days' WHERE ship_id = :id", ['id' => $a]);
        $al->recordTrade($a, 50000);
        $this->assertSame(25, $this->alignmentOf($a), 'a new day starts a new allowance');
    }

    public function testRealTradeThroughTheServiceEarnsAlignment(): void
    {
        // Sector 3 is an ore port; sell organics at it. Trading skill makes the price non-trivial.
        $a = $this->makePlayer('Merchant', ['sector' => 3, 'ship_organics' => 90, 'hull' => 3]);
        $this->db()->execute('UPDATE ships SET skill_trading = 100 WHERE ship_id = :id', ['id' => $a]);
        $r = $this->svc('tradeService')->trade($a, 'organics', 'sell', 90);
        $this->assertTrue($r['success'], json_encode($r));
        $this->db()->execute('UPDATE ships SET ship_organics = 90 WHERE ship_id = :id', ['id' => $a]);
        for ($i = 0; $i < 500; $i++) {
            // trade enough credits to pass the 10,000 mark
            $this->svc('alignmentService')->recordTrade($a, 2000);
        }
        $this->assertSame(20, $this->alignmentOf($a));
    }

    public function testFineRestoresAlignmentClearsWantedAndChargesByDepth(): void
    {
        $a = $this->makePlayer('Rogue', ['sector' => 1, 'credits' => 1_000_000]);
        $al = $this->svc('alignmentService');
        $al->apply($a, -600, 'bad');
        $al->setWanted($a, 'test');
        $this->assertTrue($al->isWanted($this->ship($a)));
        $this->assertSame(1, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM bounties WHERE target_id = :id', ['id' => $a])['c']);
        $r = $al->payFine($a);
        $this->assertTrue($r['success'], json_encode($r));
        $ship = $this->ship($a);
        $this->assertSame(-99, (int)$ship['alignment']);
        $this->assertSame(null, $ship['wanted_until']);
        $this->assertSame(1_000_000 - 60000, (int)$ship['credits'], 'fine scales with depth: 600 pts x 100');
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM bounties WHERE target_id = :id', ['id' => $a])['c']);
        $this->assertSame(1, $this->logCount($a, 'federation_fine'));
    }

    public function testFineRulesPiratesNotAtStarbaseAndPoverty(): void
    {
        $al = $this->svc('alignmentService');
        $pirate = $this->makePlayer('Pirate', ['sector' => 1, 'credits' => 5_000_000]);
        $al->apply($pirate, -1500, 'bad');
        $r = $al->payFine($pirate);
        $this->assertFalse($r['success']);
        $this->assertContains('Pirate', $r['error']);
        $this->assertSame(-1500, $this->alignmentOf($pirate));

        $away = $this->makePlayer('Away', ['sector' => 5, 'credits' => 5_000_000]);
        $al->apply($away, -300, 'bad');
        $this->assertFalse($al->payFine($away)['success'], 'fines are paid at a starbase');

        $poor = $this->makePlayer('Poor', ['sector' => 1, 'credits' => 100]);
        $al->apply($poor, -300, 'bad');
        $r = $al->payFine($poor);
        $this->assertFalse($r['success']);
        $this->assertSame(-300, $this->alignmentOf($poor));
        $this->assertSame(100, (int)$this->ship($poor)['credits']);

        $clean = $this->makePlayer('Clean', ['sector' => 1, 'credits' => 100000]);
        $this->assertFalse($al->payFine($clean)['success'], 'nothing to pay');
    }

    public function testDailyDriftMovesAllAlignmentsTowardZeroAndLogsEachOne(): void
    {
        $al = $this->svc('alignmentService');
        $up = $this->makePlayer('Up');
        $down = $this->makePlayer('Down');
        $tiny = $this->makePlayer('Tiny');
        $zero = $this->makePlayer('Zero');
        $al->apply($up, 1000, 'seed');
        $al->apply($down, -500, 'seed');
        $al->apply($tiny, 3, 'seed');
        $r = $al->dailyDrift();
        $this->assertSame(990, $this->alignmentOf($up));
        $this->assertSame(-495, $this->alignmentOf($down));
        $this->assertSame(2, $this->alignmentOf($tiny), 'minimum step of 1');
        $this->assertSame(0, $this->alignmentOf($zero));
        $this->assertSame(3, $r['drifted']);
        foreach ([$up, $down, $tiny] as $id) {
            $this->assertSame(1, $this->logCount($id, 'daily_drift'));
        }
        $this->assertSame(0, $this->logCount($zero, 'daily_drift'));
        // SQL drift agrees with the PHP rule for a spread of values.
        foreach ([1, 2, 49, 50, 51, 149, 150, 151, 999, 1234, 9999, -150, -151, -3000] as $v) {
            $id = $this->makePlayer("Drift$v");
            $this->db()->execute('UPDATE ships SET alignment = :v WHERE ship_id = :id', ['v' => $v, 'id' => $id]);
        }
        $before = $this->db()->fetchAll("SELECT ship_id, alignment FROM ships WHERE character_name LIKE 'Drift%'");
        $al->dailyDrift();
        foreach ($before as $row) {
            $this->assertSame(self::$svc['alignmentRules']->drift((int)$row['alignment']), $this->alignmentOf((int)$row['ship_id']), 'drift(' . $row['alignment'] . ')');
        }
    }

    public function testWantedExpiresButPiratesStayWanted(): void
    {
        $al = $this->svc('alignmentService');
        $offender = $this->makePlayer('Offender');
        $al->apply($offender, -300, 'bad');
        $al->setWanted($offender, 'test');
        $pirate = $this->makePlayer('Pirate');
        $al->apply($pirate, -2500, 'bad');
        $this->db()->execute("UPDATE ships SET wanted_until = now() - interval '1 hour'");
        $r = $al->dailyDrift();
        $this->assertFalse($al->isWanted($this->ship($offender)), 'Wanted lapses 7 days after the last offence');
        $this->assertSame(0, (int)$this->db()->fetchOne('SELECT COUNT(*) AS c FROM bounties WHERE target_id = :id', ['id' => $offender])['c']);
        $this->assertTrue($al->isWanted($this->ship($pirate)), 'Pirates are Wanted automatically');
        $this->assertTrue(strtotime($this->ship($pirate)['wanted_until']) > time());
        $this->assertSame(1, $r['expired']);
    }

    public function testNpcsDoNotDrift(): void
    {
        $npc = $this->svc('npcService')->spawn('police', ['sector' => 2]);
        $before = $this->alignmentOf($npc['ship_id']);
        $this->svc('alignmentService')->dailyDrift();
        $this->assertSame($before, $this->alignmentOf($npc['ship_id']));
    }

    public function testPublicViewHidesNumbersAndLlmControl(): void
    {
        $al = $this->svc('alignmentService');
        $view = $al->publicView(['ship_id' => 4, 'alignment' => -1200, 'wanted_until' => null, 'is_npc' => true, 'faction' => 'xenobe']);
        $this->assertSame('Pirate', $view['alignment_tier']);
        $this->assertTrue($view['wanted']);
        $this->assertTrue(!array_key_exists('alignment', $view));
        $this->assertSame('Xenobe Raiders', $view['faction']);
        $this->assertTrue(!array_key_exists('controller', $view));
    }

    public function testAdminAdjustmentsAreLogged(): void
    {
        $a = $this->makePlayer('Adjusted');
        $this->svc('alignmentService')->apply($a, 40, 'admin_adjustment: compensation');
        $row = $this->db()->fetchOne('SELECT reason FROM alignment_log WHERE ship_id = :id', ['id' => $a]);
        $this->assertContains('compensation', $row['reason']);
    }
}
