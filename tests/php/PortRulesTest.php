<?php

declare(strict_types=1);

namespace BNT\Tests;

use BNT\Services\TradeService;

/** What each port type sells and buys: every port buys every commodity except its own. */
class PortRulesTest extends DbTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->makeUniverse(20);
    }

    public function testEveryPortBuysEveryCommodityExceptItsOwn(): void
    {
        foreach (['ore', 'organics', 'goods', 'energy'] as $type) {
            $expected = array_values(array_diff(['ore', 'organics', 'goods', 'energy'], [$type]));
            $this->assertSame([$type], TradeService::portSells($type), "$type port sells its own product");
            $buys = TradeService::portBuys($type);
            sort($buys);
            sort($expected);
            $this->assertSame($expected, $buys, "$type port buys everything else");
        }
        $this->assertSame([], TradeService::portBuys('special'));
        $this->assertSame([], TradeService::portBuys('none'));
    }

    public function testEnergyCanBeSoldAtOreOrganicsAndGoodsPortsButNotEnergyPorts(): void
    {
        $ship = $this->makePlayer('Hauler', ['sector' => 5, 'ship_energy' => 500, 'turns' => 50]);
        foreach ([[5, 'goods'], [6, 'ore'], [7, 'organics']] as [$sector, $type]) {
            $this->db()->execute('UPDATE universe SET port_type = :t WHERE sector_id = :s', ['t' => $type, 's' => $sector]);
            $this->db()->execute('UPDATE ships SET sector = :s, ship_energy = 500 WHERE ship_id = :id', ['s' => $sector, 'id' => $ship]);
            $before = (int)$this->ship($ship)['credits'];
            $r = $this->svc('tradeService')->trade($ship, 'energy', 'sell', 100);
            $this->assertTrue($r['success'], "energy sells at a $type port: " . json_encode($r));
            $this->assertSame(400, (int)$this->ship($ship)['ship_energy'], "$type port took the energy");
            $this->assertTrue((int)$this->ship($ship)['credits'] > $before, "paid for energy at a $type port");
        }
        $this->db()->execute("UPDATE universe SET port_type = 'energy' WHERE sector_id = 8");
        $this->db()->execute('UPDATE ships SET sector = 8 WHERE ship_id = :id', ['id' => $ship]);
        $r = $this->svc('tradeService')->trade($ship, 'energy', 'sell', 100);
        $this->assertFalse($r['success']);
        $this->assertSame('PORT_WONT_BUY', $r['code'], 'an energy port does not buy energy');
    }
}
