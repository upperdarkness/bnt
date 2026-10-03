<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;
use BNT\Models\Ship;

/**
 * Deterministic state machines for every NPC archetype. These are the only
 * controller for most NPCs and the fallback for LLM-controlled ones.
 *
 * The brain acts exclusively through the same service methods the API uses
 * (MovementService, TradeService, CombatService), so it cannot bypass a rule.
 * Decisions use the cached SectorGraph rather than per-hop queries.
 */
class NpcScriptedBrain
{
    private const MAX_ACTIONS_PER_TICK = 3;

    public function __construct(
        private Database $db,
        private Ship $shipModel,
        private MovementService $movement,
        private TradeService $trade,
        private CombatService $combat,
        private AlignmentService $alignment,
        private CombatRating $rating,
        private SectorGraph $graph,
        private NpcService $npcs,
        private PoliceService $police,
        private array $config,
        private ?ContrabandService $contraband = null,
        private ?ProtectionRules $protection = null
    ) {}

    /**
     * Run one NPC for one tick.
     *
     * @param array $profile npc_profiles row joined with ships (ship_id, faction, archetype, state, home_zone)
     * @param array $ctx     ['deadline' => float microtime, 'busy_sectors' => int[]]
     * @return string        short summary for logs
     */
    public function tick(array $profile, array $ctx): string
    {
        $shipId = (int)$profile['ship_id'];
        $ship = $this->shipModel->find($shipId);
        if (!$ship || $ship['ship_destroyed']) {
            return 'dead';
        }
        $state = is_array($profile['state']) ? $profile['state'] : (json_decode((string)$profile['state'], true) ?: []);
        $actions = [];
        for ($i = 0; $i < self::MAX_ACTIONS_PER_TICK; $i++) {
            if (microtime(true) > ($ctx['deadline'] ?? PHP_FLOAT_MAX)) {
                break;
            }
            if ((int)$ship['turns'] < 1) {
                $actions[] = 'no_turns';
                break;
            }
            $this->repairIfHome($ship, $profile);
            $result = match ($profile['archetype']) {
                'trader' => $this->trader($ship, $profile, $state, false),
                'free_captain' => $this->trader($ship, $profile, $state, true),
                'raider' => $this->raider($ship, $profile, $state, $ctx),
                'police' => $this->policeUnit($ship, $profile, $state),
                default => ['idle', false],
            };
            [$summary, $continue] = $result;
            $actions[] = $summary;
            $state = $this->reloadState($shipId);
            $ship = $this->shipModel->find($shipId);
            if (!$continue || !$ship || $ship['ship_destroyed']) {
                break;
            }
        }
        $this->npcs->patchState($shipId, 'script', ['last_tick' => date('c'), 'last_actions' => $actions]);
        return $profile['faction'] . '#' . $shipId . ': ' . implode(', ', $actions);
    }

    private function reloadState(int $shipId): array
    {
        $row = $this->db->fetchOne('SELECT state FROM npc_profiles WHERE ship_id = :id', ['id' => $shipId]);
        return $row ? (json_decode((string)$row['state'], true) ?: []) : [];
    }

    // ----------------------------------------------------------- helpers

    /** Other ships in the NPC's sector, with the fields the rules need. */
    private function shipsHere(int $sector, int $excludeId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.*, p.faction FROM ships s LEFT JOIN npc_profiles p ON p.ship_id = s.ship_id
             WHERE s.sector = :sector AND s.ship_destroyed = FALSE AND s.on_planet = FALSE AND s.ship_id != :me',
            ['sector' => $sector, 'me' => $excludeId]
        );
        // Protected ships are invisible to NPCs (no targeting, no fleeing from them).
        $rows = array_values(array_filter($rows, fn($r) => !$this->protection?->isProtected($r)));
        foreach ($rows as &$r) {
            $r['wanted'] = $this->alignment->isWanted($r);
            $r['rating'] = $this->rating->rate($r);
        }
        return $rows;
    }

    /** Move one hop toward a sector. @return array|null move result, null if no route/step possible */
    private function stepToward(array $ship, int $target, array $avoid = []): ?array
    {
        $path = $this->graph->path((int)$ship['sector'], $target, (int)$this->config['npc']['max_path_depth'], $avoid);
        if ($path === null || count($path) < 2) {
            return null;
        }
        return $this->movement->move($ship, $path[1]);
    }

    private function starbaseSectors(): array
    {
        $out = [];
        foreach ($this->graph->data()['ports'] as $sector => $port) {
            if (!empty($port['starbase'])) {
                $out[] = (int)$sector;
            }
        }
        return $out;
    }

    private function homeSectorFor(array $ship, array $profile): ?int
    {
        $zone = (int)($profile['home_zone'] ?? 0);
        if ($zone <= 0) {
            return null;
        }
        $here = (int)$ship['sector'];
        if ($this->graph->zoneOf($here) === $zone) {
            return $here;
        }
        return $this->graph->nearest($here, fn(int $s) => $this->graph->zoneOf($s) === $zone, (int)$this->config['npc']['max_path_depth']);
    }

    private function repairIfHome(array $ship, array $profile): void
    {
        if (empty($this->config['npc']['repair_at_home'])) {
            return;
        }
        $zone = (int)($profile['home_zone'] ?? 0);
        if ($zone <= 0 || $this->graph->zoneOf((int)$ship['sector']) !== $zone) {
            return;
        }
        $maxArmor = (int)round(pow(1.5, (int)$ship['armor']) * 100);
        $tpl = $this->config['npc']['factions'][$profile['faction']]['loadout'] ?? [];
        if ((int)$ship['armor_pts'] < $maxArmor || (int)$ship['ship_fighters'] < (int)($tpl['ship_fighters'] ?? 0)) {
            $this->db->execute(
                'UPDATE ships SET armor_pts = GREATEST(armor_pts, :a), ship_fighters = GREATEST(ship_fighters, :f), torps = GREATEST(torps, :t) WHERE ship_id = :id',
                ['a' => $maxArmor, 'f' => (int)($tpl['ship_fighters'] ?? 0), 't' => (int)($tpl['torps'] ?? 0), 'id' => (int)$ship['ship_id']]
            );
        }
    }

    // ------------------------------------------------------- trader / free captain

    private function trader(array $ship, array $profile, array $state, bool $opportunist): array
    {
        $shipId = (int)$ship['ship_id'];
        $here = $this->shipsHere((int)$ship['sector'], $shipId);
        $myRating = $this->rating->rate($ship);
        $rules = $this->alignment->rules();

        // Opportunism: a lone Outlaw we would clearly beat.
        if ($opportunist && count($here) === 1) {
            $other = $here[0];
            $isPolice = !empty($other['is_npc']) && ($other['faction'] ?? null) === 'police';
            if (!$isPolice && $rules->isOutlaw((int)$other['alignment']) && $other['rating'] < 0.5 * $myRating) {
                $r = $this->combat->attackShip($ship, (int)$other['ship_id']);
                return ['attack:' . ($r['data']['outcome'] ?? $r['code']), $r['success']];
            }
        }

        // Flee: a hostile/lawless ship with a higher rating.
        $me = $ship + ['faction' => $profile['faction'], 'is_npc' => true];
        foreach ($here as $other) {
            if ($other['rating'] > $myRating && FactionRelations::threatens($me, $other, $rules)) {
                $fed = $this->police->nearestFederationSector((int)$ship['sector']);
                if ($fed !== (int)$ship['sector']) {
                    $r = $this->stepToward($ship, $fed);
                    $this->npcs->patchState($shipId, 'trader', ['phase' => 'flee']);
                    return ['flee', (bool)($r['success'] ?? false)];
                }
                return ['flee:already_safe', false];
            }
        }

        $route = $state['trader'] ?? [];
        $phase = $route['phase'] ?? 'plan';
        if ($phase === 'flee') {
            $phase = 'plan';
        }

        if ($phase === 'plan' || empty($route['a']) || empty($route['b'])) {
            $plan = $this->planRoute($ship);
            if ($plan === null) {
                return $this->wander($ship, 'no_route');
            }
            $this->npcs->replaceState($shipId, 'trader', $plan + ['phase' => 'to_buy']);
            return ['plan:' . $plan['commodity'] . ' ' . $plan['a'] . '->' . $plan['b'], true];
        }

        $commodity = (string)$route['commodity'];
        if ($phase === 'to_buy') {
            if ((int)$ship['sector'] !== (int)$route['a']) {
                return $this->travel($ship, (int)$route['a'], 'trader');
            }
            $sector = $this->db->fetchOne('SELECT * FROM universe WHERE sector_id = :s', ['s' => (int)$ship['sector']]);
            $unit = max(1, $this->trade->prices($sector, $this->trade->bonusFor($ship))[$commodity]['buy']);
            $space = TradeService::holds((int)$ship['hull'], (string)$ship['ship_type']) - TradeService::usedHolds($ship);
            $amount = min($space, intdiv((int)$ship['credits'], $unit), (int)$sector["port_$commodity"]);
            if ($amount <= 0) {
                $this->npcs->replaceState($shipId, 'trader', ['phase' => 'plan']);
                return ['buy:nothing', true];
            }
            $r = $this->trade->trade($shipId, $commodity, 'buy', $amount);
            $this->npcs->patchState($shipId, 'trader', ['phase' => $r['success'] ? 'to_sell' : 'plan']);
            return ['buy:' . ($r['success'] ? "$amount $commodity" : $r['code']), true];
        }

        // to_sell
        if ((int)$ship['sector'] !== (int)$route['b']) {
            return $this->travel($ship, (int)$route['b'], 'trader');
        }
        $have = (int)$ship["ship_$commodity"];
        if ($have > 0) {
            $r = $this->trade->trade($shipId, $commodity, 'sell', $have);
            $this->npcs->replaceState($shipId, 'trader', ['phase' => 'plan']);
            return ['sell:' . ($r['success'] ? "$have $commodity" : $r['code']), true];
        }
        $this->npcs->replaceState($shipId, 'trader', ['phase' => 'plan']);
        return ['sell:empty', true];
    }

    /** Travel one hop, replanning if movement fails or a defence is hit. */
    private function travel(array $ship, int $target, string $ns): array
    {
        $r = $this->stepToward($ship, $target);
        if ($r === null || !$r['success'] || !empty($r['destroyed'])) {
            $this->npcs->replaceState((int)$ship['ship_id'], $ns, ['phase' => 'plan']);
            return ['travel:blocked', false];
        }
        if (!empty($r['hazard'])) {
            $this->npcs->replaceState((int)$ship['ship_id'], $ns, ['phase' => 'plan']);
        }
        return ['travel->' . (int)$r['ship']['sector'], empty($r['hazard'])];
    }

    /**
     * Most profitable port pair within 5 hops, using cached sector data and live prices.
     * @return array{a:int,b:int,commodity:string}|null
     */
    private function planRoute(array $ship): ?array
    {
        $from = (int)$ship['sector'];
        $reach = $this->graph->distances($from, 5);
        $ports = array_intersect_key($this->graph->data()['ports'], $reach);
        if (count($ports) < 2) {
            return null;
        }
        $ids = '{' . implode(',', array_keys($ports)) . '}';
        $bonus = $this->trade->bonusFor($ship);
        $rows = [];
        foreach ($this->db->fetchAll('SELECT * FROM universe WHERE sector_id = ANY(CAST(:ids AS INT[]))', ['ids' => $ids]) as $r) {
            $rows[(int)$r['sector_id']] = ['row' => $r, 'prices' => $this->trade->prices($r, $bonus)];
        }
        $holds = TradeService::holds((int)$ship['hull'], (string)$ship['ship_type']);
        $credits = (int)$ship['credits'];

        // Nearest few exporters keep the BFS-per-candidate cost bounded.
        $sellers = [];
        foreach ($rows as $id => $info) {
            if (in_array($info['row']['port_type'], TradeService::COMMODITIES, true)) {
                $sellers[$id] = $reach[$id];
            }
        }
        asort($sellers);
        $best = null;
        $bestScore = 0.0;
        foreach (array_slice(array_keys($sellers), 0, 6) as $a) {
            $commodity = $rows[$a]['row']['port_type'];
            $buyUnit = $rows[$a]['prices'][$commodity]['buy'];
            if ($buyUnit <= 0) {
                continue;
            }
            $qty = min($holds, intdiv($credits, $buyUnit), (int)$rows[$a]['row']["port_$commodity"]);
            if ($qty <= 0) {
                continue;
            }
            $fromA = $this->graph->distances($a, 5);
            foreach ($rows as $b => $info) {
                if ($b === $a || !isset($fromA[$b]) || !$info['prices'][$commodity]['canBuy']) {
                    continue;
                }
                $profit = ($info['prices'][$commodity]['sell'] - $buyUnit) * $qty;
                if ($profit <= 0) {
                    continue;
                }
                $score = $profit / (1 + $reach[$a] + $fromA[$b]);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['a' => $a, 'b' => $b, 'commodity' => $commodity];
                }
            }
        }
        return $best;
    }

    private function wander(array $ship, string $why, bool $avoidFed = false): array
    {
        $options = $this->graph->neighbours((int)$ship['sector']);
        if ($avoidFed) {
            $fed = $this->police->federationSectors();
            $options = array_values(array_filter($options, static fn($s) => !isset($fed[$s])));
        }
        $options = array_values(array_diff($options, $this->starbaseSectors()));
        if (!$options) {
            return ["idle:$why", false];
        }
        $r = $this->movement->move($ship, $options[array_rand($options)]);
        return ["wander:$why", $r['success'] && empty($r['hazard'])];
    }

    // ---------------------------------------------------------------- raider

    private function raider(array $ship, array $profile, array $state, array $ctx): array
    {
        $shipId = (int)$ship['ship_id'];
        $myRating = $this->rating->rate($ship);
        $hull = $this->rating->hullPercent($ship);

        // Retreat to the home zone below 40% hull.
        if ($hull < 40) {
            $home = $this->homeSectorFor($ship, $profile);
            if ($home !== null && $home !== (int)$ship['sector']) {
                $r = $this->stepToward($ship, $home);
                return ['retreat', (bool)($r['success'] ?? false)];
            }
            return ['retreat:resting', false];
        }

        $here = $this->shipsHere((int)$ship['sector'], $shipId);
        $flags = ['starbase' => in_array((int)$ship['sector'], $this->starbaseSectors(), true)];
        // Attack: weak target here (raiders pace themselves: one strike per cooldown window).
        $cooldownUntil = isset($state['raider']['cooldown_until']) ? strtotime((string)$state['raider']['cooldown_until']) : 0;
        if (!$flags['starbase'] && $cooldownUntil <= time()) {
            $skip = $state['raider']['skip'] ?? [];
            $targets = array_filter($here, fn($o) => ($o['faction'] ?? null) !== 'xenobe'
                && $o['rating'] < 0.7 * $myRating && !in_array((int)$o['ship_id'], $skip, true));
            usort($targets, static fn($a, $b) => $a['rating'] <=> $b['rating']);
            if ($targets) {
                $t = $targets[0];
                $r = $this->combat->attackShip($ship, (int)$t['ship_id']);
                if (!$r['success']) {
                    $skip[] = (int)$t['ship_id'];
                    $this->npcs->patchState($shipId, 'raider', ['skip' => array_slice($skip, -10)]);
                } else {
                    $pause = (int)($this->config['npc']['raider_attack_cooldown_min'] ?? 30);
                    $this->npcs->patchState($shipId, 'raider', ['cooldown_until' => date('c', time() + $pause * 60)]);
                }
                return ['attack:' . ($r['data']['outcome'] ?? $r['code']), $r['success']];
            }
        }

        // Smuggling: Raiders run Void Relics between black markets (slowly, and never through FedSpace).
        if ($this->contraband?->enabled()) {
            $smuggle = $this->smuggle($ship, $state);
            if ($smuggle !== null) {
                return $smuggle;
            }
        }

        // Lay mines on the busiest trade lane.
        $mined = $state['raider']['mined'] ?? [];
        if ((int)$ship['torps'] >= 5 && !empty($ctx['busy_sectors'])) {
            $fed = $this->police->federationSectors();
            $sb = $this->starbaseSectors();
            $targetLane = null;
            foreach ($ctx['busy_sectors'] as $s) {
                if (!isset($fed[$s]) && !in_array($s, $sb, true) && !in_array($s, $mined, true)) {
                    $targetLane = (int)$s;
                    break;
                }
            }
            if ($targetLane !== null) {
                if ((int)$ship['sector'] === $targetLane) {
                    $r = $this->combat->deployDefence($ship, 'M', 5);
                    $mined[] = $targetLane;
                    $this->npcs->patchState($shipId, 'raider', ['mined' => array_slice($mined, -20)]);
                    return ['mines:' . ($r['success'] ? 'laid' : $r['code']), true];
                }
                $r = $this->stepToward($ship, $targetLane);
                if ($r !== null && $r['success']) {
                    return ['patrol->' . (int)$r['ship']['sector'], empty($r['hazard'])];
                }
                $mined[] = $targetLane; // unreachable: do not retry forever
                $this->npcs->patchState($shipId, 'raider', ['mined' => array_slice($mined, -20)]);
            }
        }

        // Stalk: weakest eligible target one hop away (only when ready to strike again).
        $victim = $cooldownUntil <= time() ? $this->weakestNeighbour($ship, $myRating) : null;
        if ($victim !== null) {
            $r = $this->movement->move($ship, $victim);
            return ['stalk->' . $victim, $r['success'] && empty($r['hazard'])];
        }
        return $this->wander($ship, 'patrol', true);
    }

    /** @return array|null an action result, or null when there is nothing to do */
    private function smuggle(array $ship, array $state): ?array
    {
        $shipId = (int)$ship['ship_id'];
        $plan = $state['smuggle'] ?? [];
        if (isset($plan['next_at']) && strtotime((string)$plan['next_at']) > time()) {
            return null;
        }
        $avoid = array_merge($this->starbaseSectors(), array_keys($this->police->federationSectors()));
        $here = (int)$ship['sector'];
        $carried = (int)$ship['ship_contraband'];
        $markets = $this->graph->blackMarkets();
        if (count($markets) < 2) {
            return null;
        }
        $rows = [];
        foreach ($this->db->fetchAll('SELECT * FROM universe WHERE sector_id = ANY(CAST(:ids AS INT[]))', ['ids' => '{' . implode(',', $markets) . '}']) as $r) {
            $rows[(int)$r['sector_id']] = $this->contraband->prices($r);
        }
        $later = date('c', time() + 120 * 60);

        if ($carried > 0) {
            $dest = (int)($plan['to'] ?? 0);
            if (!isset($rows[$dest])) {
                uasort($rows, static fn($a, $b) => $b['sell'] <=> $a['sell']);
                $dest = (int)array_key_first(array_diff_key($rows, [$here => 1]));
            }
            if ($here === $dest) {
                $r = $this->trade->trade($shipId, 'contraband', 'sell', $carried);
                $this->npcs->replaceState($shipId, 'smuggle', ['next_at' => $later]);
                return ['smuggle:sell ' . ($r['success'] ? $carried : $r['code']), true];
            }
            $this->npcs->patchState($shipId, 'smuggle', ['to' => $dest]);
            $step = $this->stepToward($ship, $dest, $avoid);
            if ($step === null || !$step['success']) {
                $this->npcs->replaceState($shipId, 'smuggle', ['next_at' => $later]);
                return ['smuggle:lost_route', false];
            }
            return ['smuggle->' . (int)$step['ship']['sector'], empty($step['hazard'])];
        }

        // Not carrying: find the best (buy here, sell there) pair and go to the buying market.
        $best = null;
        $bestScore = 0.0;
        foreach ($rows as $a => $pa) {
            $pathA = $this->graph->path($here, $a, 8, $avoid);
            if ($pathA === null || $pa['stock'] < 5) {
                continue;
            }
            foreach ($rows as $b => $pb) {
                if ($b === $a || $this->graph->path($a, $b, 8, $avoid) === null) {
                    continue;
                }
                $profit = $pb['sell'] - $pa['buy'];
                $score = $profit / (1 + count($pathA));
                if ($profit > 0 && $score > $bestScore) {
                    $bestScore = $score;
                    $best = ['from' => $a, 'to' => $b, 'buy' => $pa['buy']];
                }
            }
        }
        if ($best === null || (int)$ship['credits'] < $best['buy'] * 5) {
            $this->npcs->replaceState($shipId, 'smuggle', ['next_at' => date('c', time() + 30 * 60)]);
            return null;
        }
        if ($here === $best['from']) {
            $units = min(10, intdiv((int)$ship['credits'], $best['buy']), $rows[$here]['stock']);
            $r = $this->trade->trade($shipId, 'contraband', 'buy', $units);
            $this->npcs->replaceState($shipId, 'smuggle', $r['success'] ? ['to' => $best['to']] : ['next_at' => $later]);
            return ['smuggle:buy ' . ($r['success'] ? $units : $r['code']), true];
        }
        $step = $this->stepToward($ship, $best['from'], $avoid);
        if ($step === null || !$step['success']) {
            return null;
        }
        return ['smuggle->' . (int)$step['ship']['sector'], empty($step['hazard'])];
    }

    private function weakestNeighbour(array $ship, int $myRating): ?int
    {
        $neighbours = $this->graph->neighbours((int)$ship['sector']);
        if (!$neighbours) {
            return null;
        }
        $fed = $this->police->federationSectors();
        $sb = $this->starbaseSectors();
        $neighbours = array_values(array_filter($neighbours, static fn($s) => !isset($fed[$s]) && !in_array($s, $sb, true)));
        if (!$neighbours) {
            return null;
        }
        $rows = $this->db->fetchAll(
            'SELECT s.*, p.faction FROM ships s LEFT JOIN npc_profiles p ON p.ship_id = s.ship_id
             WHERE s.sector = ANY(CAST(:ids AS INT[])) AND s.ship_destroyed = FALSE AND s.on_planet = FALSE',
            ['ids' => '{' . implode(',', $neighbours) . '}']
        );
        $best = null;
        $bestRating = PHP_INT_MAX;
        foreach ($rows as $r) {
            if ($this->protection?->isProtected($r)) {
                continue;
            }
            if (($r['faction'] ?? null) === 'xenobe') {
                continue;
            }
            $rt = $this->rating->rate($r);
            if ($rt < 0.7 * $myRating && $rt < $bestRating) {
                $bestRating = $rt;
                $best = (int)$r['sector'];
            }
        }
        return $best;
    }

    // ---------------------------------------------------------------- police

    private function policeUnit(array $ship, array $profile, array $state): array
    {
        $shipId = (int)$ship['ship_id'];
        $here = $this->shipsHere((int)$ship['sector'], $shipId);

        // Only ever engage Wanted ships (the combat rules enforce this too).
        foreach ($here as $other) {
            if ($other['wanted']) {
                $this->alignment->noteSighting((int)$other['ship_id'], (int)$ship['sector']);
                $police = $state['police'] ?? [];
                if (($police['target'] ?? null) === (int)$other['ship_id']) {
                    $this->npcs->patchState($shipId, 'police', ['last_contact_at' => date('c')]);
                }
                $r = $this->combat->attackShip($ship, (int)$other['ship_id']);
                if ($r['success']) {
                    return ['engage:' . ($r['data']['outcome'] ?? 'ok'), true];
                }
            }
        }

        $police = $state['police'] ?? [];
        $targetId = $police['target'] ?? null;
        $avoid = $this->starbaseSectors();

        if ($targetId !== null) {
            $t = $this->db->fetchOne('SELECT sector, last_known_sector, last_known_at, ship_destroyed FROM ships WHERE ship_id = :id', ['id' => (int)$targetId]);
            if (!$t || $t['ship_destroyed']) {
                $this->npcs->replaceState($shipId, 'police', ['target' => null, 'mode' => 'return']);
                return ['target_gone', true];
            }
            $goal = (int)($t['last_known_sector'] ?? 0);
            if ($goal > 0 && !in_array($goal, $avoid, true)) {
                if ($goal !== (int)$ship['sector']) {
                    $r = $this->stepToward($ship, $goal, $avoid);
                    if ($r !== null && $r['success']) {
                        return ['pursue->' . (int)$r['ship']['sector'], empty($r['hazard'])];
                    }
                    return ['pursue:blocked', false];
                }
                // At the last known position but the target is not here: search nearby.
                return $this->wander($ship, 'search');
            }
            return ['pursue:waiting_for_sighting', false];
        }

        // Return to FedSpace, then patrol its edges.
        $fed = $this->police->federationSectors();
        if (!isset($fed[(int)$ship['sector']])) {
            $home = $this->police->nearestFederationSector((int)$ship['sector']);
            $r = $this->stepToward($ship, $home);
            return ['return', (bool)($r['success'] ?? false)];
        }
        if (($police['mode'] ?? '') === 'return') {
            $this->npcs->patchState($shipId, 'police', ['mode' => 'patrol']);   // home again: available for new tasking
        }
        $edges = array_values(array_filter(array_keys($fed), fn($s) => count(array_filter(
            $this->graph->neighbours((int)$s), static fn($n) => !isset($fed[$n]))) > 0));
        if ($edges && random_int(1, 3) === 1) {
            $edge = $edges[array_rand($edges)];
            if ($edge !== (int)$ship['sector']) {
                $r = $this->stepToward($ship, $edge, $avoid);
                return ['patrol->edge', (bool)($r['success'] ?? false)];
            }
        }
        return $this->wanderWithin($ship, $fed, $avoid);
    }

    private function wanderWithin(array $ship, array $zone, array $avoid): array
    {
        $options = array_values(array_filter($this->graph->neighbours((int)$ship['sector']),
            static fn($s) => isset($zone[$s]) && !in_array($s, $avoid, true)));
        if (!$options) {
            return ['patrol:idle', false];
        }
        $r = $this->movement->move($ship, $options[array_rand($options)]);
        return ['patrol', false];
    }
}
