<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Models\Planet;
use BNT\Models\Ship;

/**
 * Compact plain-text observation for LLM NPCs (about 1,500 tokens at most).
 * Every string a player wrote appears only inside <<< >>> delimiters.
 */
class ObservationBuilder
{
    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private Planet $planetModel,
        private TradeService $trade,
        private AlignmentService $alignment,
        private CombatRating $rating,
        private NpcEvents $events,
        private TextFilter $filter,
        private array $config,
        private ?ContrabandService $contraband = null
    ) {}

    /** @return array{text: string, events: array, last_event_id: int} */
    public function build(array $ship): array
    {
        $rules = $this->alignment->rules();
        $sectorId = (int)$ship['sector'];
        $sector = $this->db->fetchOne(
            'SELECT u.*, z.zone_name FROM universe u LEFT JOIN zones z ON z.zone_id = u.zone_id WHERE u.sector_id = :s',
            ['s' => $sectorId]
        );
        $links = array_map(static fn($r) => (int)$r['sector_id'], $this->db->fetchAll(
            'SELECT link_dest AS sector_id FROM links WHERE link_start = :s ORDER BY link_dest', ['s' => $sectorId]
        ));

        $wanted = $this->alignment->isWanted($ship);
        $lines = [];
        $lines[] = sprintf('TURN BUDGET: %d turns | CREDITS: %s | HULL %d%% | ALIGNMENT: %s (%s)%s',
            (int)$ship['turns'], number_format((int)$ship['credits']), $this->rating->hullPercent($ship),
            $rules->label((int)$ship['alignment']), number_format((int)$ship['alignment']), $wanted ? ' WANTED' : '');

        $zone = $sector['zone_name'] ?? 'unknown';
        $port = 'none';
        if ($sector && ($sector['port_type'] !== 'none' || $sector['is_starbase'])) {
            $prices = $this->trade->prices($sector, $this->trade->bonusFor($ship));
            $sells = [];
            $buys = [];
            foreach ($prices as $c => $p) {
                if ($p['canSell']) {
                    $sells[] = "$c {$p['buy']}";
                }
                if ($p['canBuy']) {
                    $buys[] = "$c {$p['sell']}";
                }
            }
            $port = strtoupper($sector['port_type']) . ($sector['is_starbase'] ? ' STARBASE' : '')
                . ' sells ' . ($sells ? implode(', ', $sells) : 'nothing')
                . ', buys ' . ($buys ? implode(', ', $buys) : 'nothing');
        }
        $flags = [];
        if ($sector && $sector['is_starbase']) {
            $flags[] = 'NO COMBAT';
        }
        $cbService = $this->contraband;
        if ($cbService && $cbService->enabled() && $sector && $sector['is_blackmarket']) {
            $cp = $cbService->prices($sector);
            $port .= sprintf(' | BLACK MARKET (illegal, costs alignment): %s buy %d, sell %d, stock %d', $cbService->name(), $cp['buy'], $cp['sell'], $cp['stock']);
        }
        $lines[] = sprintf('LOCATION: Sector %d (zone: %s)%s | Port: %s', $sectorId, UntrustedText::sanitize((string)$zone, 40),
            $flags ? ' [' . implode(', ', $flags) . ']' : '', $port);
        $lines[] = 'LINKS: ' . ($links ? implode(', ', $links) : 'none');

        // Ships here
        $here = $this->db->fetchAll(
            'SELECT s.*, p.faction, t.team_name FROM ships s
             LEFT JOIN npc_profiles p ON p.ship_id = s.ship_id LEFT JOIN teams t ON t.id = s.team
             WHERE s.sector = :sec AND s.ship_destroyed = FALSE AND s.on_planet = FALSE AND s.ship_id != :me LIMIT 10',
            ['sec' => $sectorId, 'me' => (int)$ship['ship_id']]
        );
        $myRating = $this->rating->rate($ship);
        $shipLines = [];
        foreach ($here as $o) {
            $s = sprintf('[ship %d] name=%s type=%s rating~%s alignment=%s', (int)$o['ship_id'], UntrustedText::wrap($o['character_name'], 50),
                $o['ship_type'], $this->rating->bucket($this->rating->rate($o), $myRating), $rules->label((int)$o['alignment']));
            if ($this->alignment->isWanted($o)) {
                $s .= ' WANTED';
            }
            if (!empty($o['is_npc'])) {
                $s .= ' NPC=' . ($this->config['npc']['factions'][$o['faction']]['label'] ?? 'unknown');
            }
            if (!empty($o['team_name'])) {
                $s .= ' team=' . UntrustedText::wrap($o['team_name'], 50);
            }
            if ((int)$ship['team'] !== 0 && (int)$o['team'] === (int)$ship['team']) {
                $s .= ' TEAMMATE';
            }
            $shipLines[] = $s;
        }
        $lines[] = 'SHIPS HERE: ' . ($shipLines ? implode(' ; ', $shipLines) : 'none');

        $planets = [];
        foreach ($this->planetModel->getPlanetsInSector($sectorId) as $pl) {
            $planets[] = sprintf('[planet %d] name=%s owner=%s', (int)$pl['planet_id'], UntrustedText::wrap($pl['planet_name'], 50),
                $pl['owner'] ? ((int)$pl['owner'] === (int)$ship['ship_id'] ? 'you' : UntrustedText::wrap($pl['owner_name'], 50)) : 'unowned');
        }
        if ($planets) {
            $lines[] = 'PLANETS HERE: ' . implode(' ; ', $planets);
        }

        $defs = $this->db->fetchAll(
            'SELECT sd.defence_type, sd.quantity, sd.ship_id, s.character_name FROM sector_defence sd
             JOIN ships s ON s.ship_id = sd.ship_id WHERE sd.sector_id = :s ORDER BY sd.quantity DESC LIMIT 5',
            ['s' => $sectorId]
        );
        $defLines = array_map(static fn($d) => sprintf('%d %s owner=%s', (int)$d['quantity'],
            $d['defence_type'] === 'F' ? 'fighters' : 'mines',
            (int)$d['ship_id'] === (int)$ship['ship_id'] ? 'you' : UntrustedText::wrap($d['character_name'], 50)), $defs);
        $lines[] = 'DEFENCES HERE: ' . ($defLines ? implode(' ; ', $defLines) : 'none');

        $holds = TradeService::holds((int)$ship['hull'], (string)$ship['ship_type']);
        $lines[] = sprintf('CARGO: ore %d, organics %d, goods %d, energy %d, colonists %d%s (holds %d/%d) | FIGHTERS %d | TORPS %d',
            $ship['ship_ore'], $ship['ship_organics'], $ship['ship_goods'], $ship['ship_energy'], $ship['ship_colonists'],
            (int)$ship['ship_contraband'] > 0 ? ', CONTRABAND ' . (int)$ship['ship_contraband'] : '',
            TradeService::usedHolds($ship), $holds, $ship['ship_fighters'], $ship['torps']);

        $events = $this->events->pending((int)$ship['ship_id'], 10);
        $lines[] = 'EVENTS SINCE LAST WAKE:' . ($events ? '' : ' none');
        $lastId = 0;
        foreach ($events as $e) {
            $lastId = max($lastId, (int)$e['id']);
            $lines[] = '- ' . $this->age((float)$e['age_seconds']) . ': ' . $this->describeEvent($e);
        }
        return ['text' => implode("\n", $lines), 'events' => array_map(static fn($e) => [
            'id' => (int)$e['id'], 'kind' => $e['kind'],
        ], $events), 'last_event_id' => $lastId];
    }

    private function describeEvent(array $e): string
    {
        $p = is_array($e['payload']) ? $e['payload'] : (json_decode((string)$e['payload'], true) ?: []);
        $by = isset($p['by']) ? sprintf('[ship %d] name=%s', (int)$p['by'], UntrustedText::wrap($p['by_name'] ?? '', 50)) : 'unknown';
        return match ($e['kind']) {
            'attacked' => !empty($p['destroyed'])
                ? "destroyed by $by"
                : "attacked by $by, lost " . (int)($p['hull_lost_pct'] ?? 0) . '% hull',
            'hit_defences' => 'hit sector ' . ($p['defence'] ?? 'defences') . ' in sector ' . (int)($p['sector'] ?? 0),
            'message' => sprintf('message from [ship %d]: %s', (int)($p['from'] ?? 0), UntrustedText::wrap($p['text'] ?? '', 280)),
            'planet_lost' => sprintf('planet [planet %d] name=%s captured by %s', (int)($p['planet_id'] ?? 0), UntrustedText::wrap($p['planet_name'] ?? '', 50), $by),
            'planet_attacked' => sprintf('planet [planet %d] attacked by %s', (int)($p['planet_id'] ?? 0), $by),
            'admin_wake' => 'woken by an administrator',
            default => UntrustedText::sanitize((string)$e['kind'], 40),
        };
    }

    private function age(float $seconds): string
    {
        return match (true) {
            $seconds < 90 => 'just now',
            $seconds < 5400 => (int)round($seconds / 60) . 'm ago',
            default => (int)round($seconds / 3600) . 'h ago',
        };
    }
}
