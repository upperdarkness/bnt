<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Core\SchedulerTasks;

/** Port production is driven by imports, stock is capped, and consumption drains what players sell. */
class PortEconomyTest extends DbTestCase
{
    private function tasks(): SchedulerTasks
    {
        return new SchedulerTasks(self::$db, self::$config);
    }

    private function port(int $sector, string $type, int $ore, int $organics, int $goods, int $energy): void
    {
        $this->db()->execute('UPDATE universe SET port_type = :t, port_ore = :o, port_organics = :g, port_goods = :go, port_energy = :e WHERE sector_id = :s',
            ['t' => $type, 'o' => $ore, 'g' => $organics, 'go' => $goods, 'e' => $energy, 's' => $sector]);
    }

    private function stock(int $sector, string $commodity): int
    {
        return (int)$this->db()->fetchOne("SELECT port_$commodity AS v FROM universe WHERE sector_id = :s", ['s' => $sector])['v'];
    }

    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(20);
        // Only the ports under test are produced: park the rest as non-ports.
        $this->db()->execute("UPDATE universe SET port_type = 'none' WHERE sector_id > 1");
    }

    public function testProductionDoesNotDependOnImports(): void
    {
        $cfg = self::$config['trading']['ore'];
        $half = (int)($cfg['limit'] * 0.5);
        $this->port(5, 'ore', $half, $cfg['limit'], $cfg['limit'], $cfg['limit']);   // every import stocked
        $this->port(6, 'ore', $half, 0, 0, 0);                                         // no imports at all
        $this->tasks()->portProduction();
        $expected = $half + (int)ceil(($cfg['limit'] - $half) * $cfg['regeneration_rate']);
        $this->assertSame($expected, $this->stock(5, 'ore'), 'a fully supplied port refills its product');
        $this->assertSame($expected, $this->stock(6, 'ore'), 'and so does a port with empty imports');
    }

    public function testProductRecoversAsFastAsImportsDecline(): void
    {
        foreach (self::$config['trading'] as $commodity => $c) {
            $this->assertSame($c['consumption_rate'], $c['regeneration_rate'], "$commodity refills at the rate imports are consumed");
        }
        // Over many runs: the product's gap and an import's stock both shrink by the same fraction each run.
        $limit = self::$config['trading']['goods']['limit'];
        $this->port(5, 'goods', $limit, 0, (int)($limit / 2), $limit);
        for ($i = 0; $i < 50; $i++) {
            $this->tasks()->portProduction();
        }
        $rate = self::$config['trading']['ore']['consumption_rate'];
        $gap = $limit - $this->stock(5, 'goods');
        $this->assertTrue(abs($gap - ($limit / 2) * pow(1 - $rate, 50)) < $limit * 0.001, 'the gap closes by the same fraction per run');
        $this->assertTrue(abs($this->stock(5, 'ore') - $limit * pow(1 - $rate, 50)) < $limit * 0.001, 'while imports decay by it');
    }

    public function testPortsConsumeTheirImports(): void
    {
        $this->port(5, 'goods', 10000, 10000, 0, 10000);   // goods port: imports ore, organics, energy
        $this->tasks()->portProduction();
        $rate = self::$config['trading']['ore']['consumption_rate'];
        $this->assertSame(10000 - (int)(10000 * $rate), $this->stock(5, 'ore'), 'a goods port consumes a share of its ore');
        $this->assertSame(10000 - (int)(10000 * $rate), $this->stock(5, 'energy'), 'and of its energy');
    }

    public function testStockIsClampedToTheLimitAtOnce(): void
    {
        $limit = self::$config['trading']['ore']['limit'];
        $this->port(5, 'ore', 85033451, 99999999, 99999999, 850132221);
        $this->tasks()->portProduction();
        $this->assertTrue($this->stock(5, 'ore') <= $limit, 'inflated product stock is pulled back to the limit');
        foreach (['organics', 'goods'] as $c) {
            $this->assertTrue($this->stock(5, $c) <= self::$config['trading'][$c]['limit'], "$c clamped");
        }
        $this->assertTrue($this->stock(5, 'energy') <= self::$config['trading']['energy']['limit']);
    }

    public function testSellingToAFullPortIsPaidButStockStaysAtTheLimit(): void
    {
        $limit = self::$config['trading']['goods']['limit'];
        $ship = $this->makePlayer('Hauler', ['sector' => 5, 'ship_ore' => 500, 'hull' => 6, 'turns' => 50]);
        $this->port(5, 'goods', $limit - 100, 0, $limit, 0);
        $before = (int)$this->ship($ship)['credits'];
        $r = $this->svc('tradeService')->trade($ship, 'ore', 'sell', 500);
        $this->assertTrue($r['success'], json_encode($r));
        $this->assertSame($limit, $this->stock(5, 'ore'), 'the port holds no more than its limit');
        $this->assertTrue((int)$this->ship($ship)['credits'] > $before, 'the sale is still paid');
        $this->assertSame(0, (int)$this->ship($ship)['ship_ore']);
    }

    public function testPricesStayInTheirBandWhateverTheStock(): void
    {
        $t = self::$config['trading']['ore'];
        $trade = $this->svc('tradeService');
        $this->port(5, 'ore', $t['limit'] * 500, 0, 0, 0);       // absurdly over the limit
        $over = $trade->prices($this->db()->fetchOne('SELECT * FROM universe WHERE sector_id = 5'))['ore']['buy'];
        $this->port(5, 'ore', $t['limit'], 0, 0, 0);
        $full = $trade->prices($this->db()->fetchOne('SELECT * FROM universe WHERE sector_id = 5'))['ore']['buy'];
        $this->port(5, 'ore', 0, 0, 0, 0);
        $empty = $trade->prices($this->db()->fetchOne('SELECT * FROM universe WHERE sector_id = 5'))['ore']['buy'];
        $this->assertSame($t['price'], $over);
        $this->assertSame($t['price'], $full);
        $this->assertSame($t['price'] + $t['delta'], $empty);
    }

    public function testNewUniversesStartWithinTheLimits(): void
    {
        $eco = self::$config['port_economy'];
        $this->assertTrue($eco['import_start_min_pct'] < $eco['import_start_max_pct'] && $eco['import_start_max_pct'] <= 100);
        foreach (self::$config['trading'] as $c => $t) {
            $this->assertTrue($t['limit'] <= 1000000, "$c limit is small enough for a trade to move it");
        }
    }
}
