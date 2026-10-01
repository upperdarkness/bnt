<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/** Server-side helpers behind the agent/* endpoints. */
class AgentService
{
    public function __construct(
        private Database $db,
        private TradeService $trade,
        private SectorGraph $graph,
        private array $config
    ) {}

    /**
     * Best buy/sell pairs among ports this ship has discovered, within $maxHops.
     * Only ports in ship_known_ports are considered, so NPCs explore like players.
     */
    public function findTrades(array $ship, int $maxHops): array
    {
        $maxHops = max(1, min(10, $maxHops));
        $from = (int)$ship['sector'];
        $reach = $this->graph->distances($from, $maxHops);
        $known = array_map(static fn($r) => (int)$r['sector_id'], $this->db->fetchAll(
            'SELECT sector_id FROM ship_known_ports WHERE ship_id = :id', ['id' => (int)$ship['ship_id']]
        ));
        $candidates = array_values(array_intersect($known, array_keys($reach)));
        if (count($candidates) < 2) {
            return ['trades' => [], 'known_ports_in_range' => count($candidates)];
        }
        $rows = [];
        foreach ($this->db->fetchAll('SELECT * FROM universe WHERE sector_id = ANY(CAST(:ids AS INT[]))',
            ['ids' => '{' . implode(',', $candidates) . '}']) as $r) {
            $rows[(int)$r['sector_id']] = ['row' => $r, 'prices' => $this->trade->prices($r, $this->trade->bonusFor($ship))];
        }
        $holds = TradeService::holds((int)$ship['hull'], (string)$ship['ship_type']);
        $out = [];
        foreach ($rows as $a => $ia) {
            foreach (TradeService::COMMODITIES as $c) {
                $buy = $ia['prices'][$c]['buy'];
                if ($buy <= 0) {
                    continue;
                }
                $distA = $this->graph->distances($a, $maxHops);
                foreach ($rows as $b => $ib) {
                    if ($a === $b || !isset($distA[$b]) || !$ib['prices'][$c]['canBuy']) {
                        continue;
                    }
                    $profit = $ib['prices'][$c]['sell'] - $buy;
                    if ($profit <= 0) {
                        continue;
                    }
                    $out[] = [
                        'commodity' => $c, 'buy_sector' => $a, 'buy_price' => $buy, 'sell_sector' => $b,
                        'sell_price' => $ib['prices'][$c]['sell'], 'profit_per_unit' => $profit,
                        'hops_to_buy' => $reach[$a], 'hops_buy_to_sell' => $distA[$b],
                        'est_profit_full_hold' => $profit * $holds,
                    ];
                }
            }
        }
        usort($out, static fn($x, $y) => ($y['est_profit_full_hold'] / (1 + $y['hops_to_buy'] + $y['hops_buy_to_sell']))
            <=> ($x['est_profit_full_hold'] / (1 + $x['hops_to_buy'] + $x['hops_buy_to_sell'])));
        return ['trades' => array_slice($out, 0, 5), 'known_ports_in_range' => count($candidates)];
    }

    /** @return array{success: bool, error?: string} */
    public function saveNotebook(int $shipId, string $text): array
    {
        $max = (int)$this->config['npc']['notebook_max_chars'];
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        if (mb_strlen($text) > $max) {
            return ['success' => false, 'error' => "Notebook is limited to $max characters"];
        }
        $this->db->execute('UPDATE npc_profiles SET notebook = :t WHERE ship_id = :id', ['t' => $text, 'id' => $shipId]);
        return ['success' => true];
    }
}
