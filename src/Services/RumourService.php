<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Rumours: short tips built from real game state, bought at ports. The game decides in advance whether each
 * one is true, stale or false; the LLM only writes flavour text around slots the system fills, so it cannot
 * make a rumour more or less accurate than intended. Template text works with the LLM switched off.
 */
class RumourService
{
    public const TYPES = ['fat_cargo', 'soft_target', 'price_spike', 'wanted_sighting', 'raider_nest', 'rich_world'];

    /** Slots each type's flavour lines must use. */
    public const SLOTS = [
        'fat_cargo' => ['sector'], 'soft_target' => ['sector'], 'price_spike' => ['commodity', 'sector'],
        'wanted_sighting' => ['name', 'sector'], 'raider_nest' => ['sector'], 'rich_world' => ['sector'],
    ];

    /** Plain template lines used until approved LLM lines exist (and as the validation-failure fallback). */
    public const FALLBACK = [
        'fat_cargo' => [
            'A hauler came through loaded to the hatches. Last seen around {{sector}}.',
            'Word on the dock is a fat cargo ship is working near {{sector}}.',
            'Somebody is running a very heavy hold near {{sector}}. Make of that what you will.',
        ],
        'soft_target' => [
            'The colony at {{sector}} has its defences down for repairs.',
            'A settlement near {{sector}} is thin on fighters. Careless, that.',
            'Heard the planet at {{sector}} could not stop a determined scout.',
        ],
        'price_spike' => [
            'They are paying silly money for {{commodity}} at {{sector}}.',
            'A port at {{sector}} is short of {{commodity}} and paying for it.',
            'If you have spare {{commodity}}, try {{sector}}. Prices are ridiculous there.',
        ],
        'wanted_sighting' => [
            '{{name}} was spotted drinking near {{sector}}.',
            'Somebody saw {{name}} slip through {{sector}} with the Federation on their tail.',
            'The Federation wants {{name}}, and {{name}} was last seen at {{sector}}.',
        ],
        'raider_nest' => [
            'Xenobe gather around {{sector}}. Steer clear, or do not.',
            'A pack of Raiders has made a den at {{sector}}. Fly carefully.',
            'The Xenobe are thick around {{sector}} these days.',
        ],
        'rich_world' => [
            'Nobody has claimed the world at {{sector}} yet. Good soil, they say.',
            'There is an empty planet near {{sector}} with rich pickings.',
            'A prospector swears the unclaimed world at {{sector}} is worth the trip.',
        ],
    ];

    public function __construct(
        private Database $db,
        private SectorGraph $graph,
        private ProtectionService $protection,
        private ContentValidator $validator,
        private array $config
    ) {}

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config['rumours'][$key] ?? $default;
    }

    public function enabled(): bool
    {
        return (bool)$this->cfg('enabled', false);
    }

    // =================================================================== truth model (pure)

    /** Chance (percent) a rumour sold here is true. */
    public function truePercent(bool $starbase, bool $raiderZone, string $tier): float
    {
        $split = $this->cfg('truth_split', ['true' => 60, 'stale' => 25, 'false' => 15]);
        $pct = (float)$split['true'];
        if ($starbase) {
            $pct = (float)$this->cfg('starbase_true_pct', 75);
        } elseif ($raiderZone) {
            $pct = (float)$this->cfg('raider_zone_true_pct', 45);
        }
        if ($tier === 'informant') {
            $pct += (float)$this->cfg('informant_bonus', 15);
        }
        return min(100.0, $pct);
    }

    /** Turn a roll in [0,100) into a truth state; the non-true remainder keeps the stale:false ratio. */
    public function drawState(float $truePct, float $roll): string
    {
        if ($roll < $truePct) {
            return 'true';
        }
        $split = $this->cfg('truth_split', ['true' => 60, 'stale' => 25, 'false' => 15]);
        $rest = max(0.0001, (float)$split['stale'] + (float)$split['false']);
        $staleShare = (100.0 - $truePct) * ((float)$split['stale'] / $rest);
        return $roll < $truePct + $staleShare ? 'stale' : 'false';
    }

    /** Deterministic truth assignment for a freshly built seed, from the base split. */
    public function assignTruth(float $roll): string
    {
        $split = $this->cfg('truth_split', ['true' => 60, 'stale' => 25, 'false' => 15]);
        return $this->drawState((float)$split['true'], $roll);
    }

    // =================================================================== seed generation

    /**
     * Hourly: expire old seeds and build new ones from the current game state.
     * @return array{created: array<string,int>, pruned: int}
     */
    public function generate(): array
    {
        $created = array_fill_keys(self::TYPES, 0);
        if (!$this->enabled()) {
            return ['created' => $created, 'pruned' => 0];
        }
        $max = (int)$this->cfg('max_seeds_per_type', 6);
        foreach (self::TYPES as $type) {
            $live = (int)$this->db->fetchOne('SELECT COUNT(*) AS c FROM rumour_seeds WHERE type = :t AND expires_at > now()', ['t' => $type])['c'];
            $candidates = $this->candidates($type);
            shuffle($candidates);
            $existing = $this->liveEntities($type);
            foreach ($candidates as $facts) {
                if ($live >= $max) {
                    break;
                }
                if (isset($existing[$this->entityKey($facts)])) {
                    continue;
                }
                $this->createSeed($type, $facts);
                $live++;
                $created[$type]++;
            }
        }
        $pruned = $this->db->query(
            "DELETE FROM rumour_seeds WHERE expires_at < now() - interval '14 days'
             AND NOT EXISTS (SELECT 1 FROM rumour_purchases p WHERE p.seed_id = rumour_seeds.id)"
        )->rowCount();
        return ['created' => $created, 'pruned' => $pruned];
    }

    private function entityKey(array $facts): string
    {
        return ($facts['ship_id'] ?? 0) . ':' . ($facts['planet_id'] ?? 0) . ':' . ($facts['sector'] ?? 0) . ':' . ($facts['commodity'] ?? '');
    }

    /** @return array<string,true> */
    private function liveEntities(string $type): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT true_facts FROM rumour_seeds WHERE type = :t AND expires_at > now()', ['t' => $type]) as $r) {
            $out[$this->entityKey(json_decode((string)$r['true_facts'], true) ?: [])] = true;
        }
        return $out;
    }

    private function createSeed(string $type, array $facts): void
    {
        $state = $this->assignTruth(mt_rand(0, 999999) / 10000);
        $shown = $facts;
        $true = $facts;
        if ($state === 'stale') {
            $old = $this->staleSource($type);
            if ($old !== null) {
                $shown = $old['facts'];
                $true = $facts + ['stale_of' => $old['facts'], 'stale_as_of' => $old['created_at']];
            } else {
                $state = 'true';   // no history yet: nothing stale to show
            }
        } elseif ($state === 'false') {
            $shown = $this->perturb($type, $facts);
            $true = $facts;
        }
        $hours = in_array($type, ['fat_cargo', 'wanted_sighting'], true) ? (int)$this->cfg('expiry_hours_moving', 6) : (int)$this->cfg('expiry_hours_other', 24);
        $zone = $this->db->fetchOne('SELECT zone_id FROM universe WHERE sector_id = :s', ['s' => (int)($facts['sector'] ?? 0)]);
        // Straight PDO: Database::query() would turn the string 'true' into a boolean.
        $this->db->getConnection()->prepare(
            'INSERT INTO rumour_seeds (type, shown_facts, true_facts, truth_state, zone_scope, expires_at)
             VALUES (:t, CAST(:shown AS JSONB), CAST(:true AS JSONB), :state, NULL, now() + make_interval(hours => :h))'
        )->execute(['t' => $type, 'shown' => json_encode($this->publicFacts($shown)),
            'true' => json_encode($true + ['_zone' => $zone['zone_id'] ?? null]), 'state' => $state, 'h' => $hours]);
    }

    /** Only the facts a buyer is told: never entity ids. */
    private function publicFacts(array $facts): array
    {
        return array_intersect_key($facts, array_flip(['sector', 'commodity', 'name']));
    }

    /** A 12-48 hour old expired seed of this type, whose facts are re-presented as if fresh. */
    private function staleSource(string $type): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT true_facts, shown_facts, created_at FROM rumour_seeds
             WHERE type = :t AND truth_state = 'true' AND created_at BETWEEN now() - interval '48 hours' AND now() - interval '12 hours'
             ORDER BY RANDOM() LIMIT 1",
            ['t' => $type]
        );
        return $row ? ['facts' => json_decode((string)$row['shown_facts'], true) ?: [], 'created_at' => (string)$row['created_at']] : null;
    }

    /** A true seed made false: sector moved 2-5 hops, or a decoy target (or, for amounts, multiplied). */
    public function perturb(string $type, array $facts): array
    {
        $shown = $facts;
        $mode = mt_rand(1, 2);
        if ($mode === 1 && isset($facts['sector'])) {
            $shown['sector'] = $this->walk((int)$facts['sector'], mt_rand(2, 5));
            return $shown;
        }
        $decoy = $this->decoy($type, $facts);
        if ($decoy !== null) {
            return $decoy;
        }
        if (isset($facts['sector'])) {
            $shown['sector'] = $this->walk((int)$facts['sector'], mt_rand(2, 5));
        }
        return $shown;
    }

    /** A sector exactly-ish $hops away by random walk (never the start). */
    public function walk(int $from, int $hops): int
    {
        $cur = $from;
        $prev = 0;
        for ($i = 0; $i < $hops; $i++) {
            $options = array_values(array_diff($this->graph->neighbours($cur), [$prev, $from]));
            if (!$options) {
                $options = $this->graph->neighbours($cur);
            }
            if (!$options) {
                break;
            }
            $prev = $cur;
            $cur = $options[array_rand($options)];
        }
        return $cur === $from ? $from + 1 : $cur;
    }

    private function decoy(string $type, array $facts): ?array
    {
        $others = array_values(array_filter($this->candidates($type), fn($c) => $this->entityKey($c) !== $this->entityKey($facts)));
        return $others ? $this->publicFacts($others[array_rand($others)]) : null;
    }

    // ------------------------------------------------------------------ candidate builders

    /** @return array<int,array> fact arrays; entity ids (ship_id, owner_id, planet_id) are kept for later checks */
    public function candidates(string $type): array
    {
        return match ($type) {
            'fat_cargo' => $this->fatCargo(),
            'soft_target' => $this->softTargets(),
            'price_spike' => $this->priceSpikes(),
            'wanted_sighting' => $this->wantedSightings(),
            'raider_nest' => $this->raiderNests(),
            'rich_world' => $this->richWorlds(),
            default => [],
        };
    }

    private function fatCargo(): array
    {
        $t = $this->config['trading'];
        $unit = [$t['ore']['price'], $t['organics']['price'], $t['goods']['price'], $t['energy']['price']];
        $cb = (int)($this->config['contraband']['base_price'] ?? 1000);
        $rows = $this->db->fetchAll(
            "SELECT s.ship_id, s.character_name AS name, s.sector, s.protection_state, s.respawn_shield_until, s.is_npc,
                    (s.ship_ore * :o + s.ship_organics * :g + s.ship_goods * :go + s.ship_energy * :e + s.ship_contraband * :c) AS cargo_value
             FROM ships s
             WHERE s.ship_destroyed = FALSE
               AND EXISTS (SELECT 1 FROM movement_log m WHERE m.ship_id = s.ship_id AND m.time > now() - interval '6 hours')",
            ['o' => $unit[0], 'g' => $unit[1], 'go' => $unit[2], 'e' => $unit[3], 'c' => $cb]
        );
        $min = (int)$this->cfg('fat_cargo_value', 500000);
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['cargo_value'] >= $min && !$this->protection->isProtected($r)) {
                $out[] = ['ship_id' => (int)$r['ship_id'], 'sector' => (int)$r['sector'], 'cargo_value' => (int)$r['cargo_value']];
            }
        }
        return $out;
    }

    private function softTargets(): array
    {
        $cap = (int)$this->cfg('soft_planet_fighter_capacity', 1000);
        $rows = $this->db->fetchAll(
            'SELECT p.planet_id, p.owner, p.sector_id, p.fighters, s.protection_state, s.respawn_shield_until, s.is_npc
             FROM planets p JOIN ships s ON s.ship_id = p.owner WHERE p.owner IS NOT NULL AND p.fighters < :f',
            ['f' => max(1, (int)floor($cap * 0.10))]
        );
        $out = [];
        foreach ($rows as $r) {
            if (!$this->protection->isProtected($r)) {
                $out[] = ['planet_id' => (int)$r['planet_id'], 'owner_id' => (int)$r['owner'], 'sector' => (int)$r['sector_id']];
            }
        }
        return $out;
    }

    private function priceSpikes(): array
    {
        $ratio = (float)$this->cfg('price_spike_ratio', 2.0);
        $ports = $this->db->fetchAll("SELECT sector_id, port_type, port_ore, port_organics, port_goods, port_energy FROM universe WHERE port_type IN ('ore','organics','goods','energy') OR is_starbase");
        $price = fn(string $c, int $stock) => $this->config['trading'][$c]['price'] + $this->config['trading'][$c]['delta'] * (($this->config['trading'][$c]['limit'] - $stock) / $this->config['trading'][$c]['limit']);
        $sums = [];
        $rows = [];
        foreach ($ports as $p) {
            foreach (['ore', 'organics', 'goods', 'energy'] as $c) {
                if (($p['port_type'] ?? '') === $c) {
                    continue;   // a port sells its own commodity and buys the others
                }
                $v = $price($c, (int)$p["port_$c"]);
                $sums[$c][] = $v;
                $rows[] = ['sector' => (int)$p['sector_id'], 'commodity' => $c, 'price' => $v];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $avg = array_sum($sums[$r['commodity']]) / max(1, count($sums[$r['commodity']]));
            if ($r['price'] > $ratio * $avg) {
                $out[] = ['sector' => $r['sector'], 'commodity' => $r['commodity']];
            }
        }
        return $out;
    }

    private function wantedSightings(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT ship_id, character_name AS name, last_known_sector, protection_state, respawn_shield_until, is_npc FROM ships
             WHERE ship_destroyed = FALSE AND last_known_sector IS NOT NULL AND (wanted_until > now() OR alignment <= :p)
               AND last_known_at > now() - interval '6 hours'",
            ['p' => (int)$this->config['alignment']['tiers']['outlaw'] - 1]
        );
        $out = [];
        foreach ($rows as $r) {
            if (!$this->protection->isProtected($r)) {
                $out[] = ['ship_id' => (int)$r['ship_id'], 'name' => (string)$r['name'], 'sector' => (int)$r['last_known_sector']];
            }
        }
        return $out;
    }

    private function raiderNests(): array
    {
        $min = (int)$this->cfg('raider_nest_min', 3);
        return array_map(static fn($r) => ['sector' => (int)$r['sector']], $this->db->fetchAll(
            "SELECT s.sector FROM ships s JOIN npc_profiles p ON p.ship_id = s.ship_id
             WHERE p.faction = 'xenobe' AND s.ship_destroyed = FALSE GROUP BY s.sector HAVING COUNT(*) >= :n",
            ['n' => $min]
        ));
    }

    private function richWorlds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT planet_id, sector_id, (ore + organics + goods + energy + colonists) AS wealth FROM planets WHERE owner IS NULL'
        );
        if (!$rows) {
            return [];
        }
        $avg = array_sum(array_column($rows, 'wealth')) / count($rows);
        $out = [];
        foreach ($rows as $r) {
            if ($r['wealth'] > $avg) {
                $out[] = ['planet_id' => (int)$r['planet_id'], 'sector' => (int)$r['sector_id']];
            }
        }
        return $out;
    }

    // =================================================================== selling

    /** @return array{tavern: array, informant: array, port: bool, daily_limit: int, bought_today: int} */
    public function offers(array $ship): array
    {
        $sector = $this->db->fetchOne('SELECT sector_id, port_type, is_starbase, zone_id FROM universe WHERE sector_id = :s', ['s' => (int)$ship['sector']]);
        $port = $sector && ($sector['port_type'] !== 'none' || $sector['is_starbase']);
        $factor = $port ? $this->priceFactor((int)$sector['zone_id']) : 1.0;
        $bought = $port ? $this->boughtToday((int)$ship['ship_id'], (int)$ship['sector']) : 0;
        return [
            'port' => (bool)$port,
            'tavern' => ['tier' => 'tavern', 'price' => (int)round((int)$this->cfg('price_tavern', 1000) * $factor), 'turns' => 1,
                'description' => 'Tavern talk: a region of about ' . (int)$this->cfg('tavern_region_size', 10) . ' sectors'],
            'informant' => ['tier' => 'informant', 'price' => (int)round((int)$this->cfg('price_informant', 10000) * $factor), 'turns' => 1,
                'description' => 'Paid informant: the exact sector, and more likely to be true'],
            'daily_limit' => (int)$this->cfg('daily_limit_per_port', 3),
            'bought_today' => $bought,
        ];
    }

    private function priceFactor(int $zoneId): float
    {
        return $zoneId === (int)$this->cfg('raider_zone_id', 4) ? (float)$this->cfg('raider_zone_price_factor', 0.5) : 1.0;
    }

    private function boughtToday(int $shipId, int $sector): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM rumour_purchases WHERE ship_id = :s AND port_sector = :p AND purchased_at >= date_trunc('day', now())",
            ['s' => $shipId, 'p' => $sector]
        )['c'];
    }

    /**
     * Buy one rumour. @return array{success: bool, error?: string, code?: string, rumour?: array}
     */
    public function buy(int $shipId, string $tier): array
    {
        if (!$this->enabled()) {
            return $this->fail('Nobody here is talking', 'RUMOURS_DISABLED');
        }
        if (!in_array($tier, ['tavern', 'informant'], true)) {
            return $this->fail('Tier must be tavern or informant', 'INVALID_TIER');
        }
        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $ship = $this->db->fetchOne('SELECT * FROM ships WHERE ship_id = :id FOR UPDATE', ['id' => $shipId]);
            if (!$ship || $ship['ship_destroyed']) {
                throw new TradeRefused('Ship not found', 'NOT_FOUND');
            }
            $sector = $this->db->fetchOne('SELECT sector_id, port_type, is_starbase, zone_id FROM universe WHERE sector_id = :s', ['s' => (int)$ship['sector']]);
            if (!$sector || ($sector['port_type'] === 'none' && !$sector['is_starbase'])) {
                throw new TradeRefused('There is no port here to buy a rumour at', 'NO_PORT');
            }
            if ($this->boughtToday($shipId, (int)$ship['sector']) >= (int)$this->cfg('daily_limit_per_port', 3)) {
                throw new TradeRefused('The locals have told you all they will today. Try another port.', 'RUMOUR_LIMIT');
            }
            $price = (int)round((int)$this->cfg($tier === 'tavern' ? 'price_tavern' : 'price_informant', 1000) * $this->priceFactor((int)$sector['zone_id']));
            if ((int)$ship['turns'] < 1) {
                throw new TradeRefused('Not enough turns', 'INSUFFICIENT_TURNS');
            }
            if ((int)$ship['credits'] < $price) {
                throw new TradeRefused('A rumour costs ' . number_format($price) . ' credits', 'INSUFFICIENT_CREDITS');
            }
            $truePct = $this->truePercent((bool)$sector['is_starbase'], (int)$sector['zone_id'] === (int)$this->cfg('raider_zone_id', 4), $tier);
            $seed = $this->pickSeed($shipId, (int)$sector['zone_id'], $this->drawState($truePct, mt_rand(0, 999999) / 10000));
            if ($seed === null) {
                throw new TradeRefused('Nobody has anything new to say. Try again later.', 'NO_RUMOURS');
            }
            $this->db->execute('UPDATE ships SET credits = credits - :p, turns = turns - 1, turns_used = turns_used + 1 WHERE ship_id = :id', ['p' => $price, 'id' => $shipId]);
            [$text, $lineId] = $this->render($seed, $tier);
            $row = $this->db->fetchOne(
                'INSERT INTO rumour_purchases (ship_id, seed_id, line_id, tier, port_sector, price, text)
                 VALUES (:ship, :seed, :line, :tier, :port, :price, :text) RETURNING id',
                ['ship' => $shipId, 'seed' => (int)$seed['id'], 'line' => $lineId, 'tier' => $tier, 'port' => (int)$ship['sector'], 'price' => $price, 'text' => $text]
            );
            if ($own) {
                $pdo->commit();
            }
            return ['success' => true, 'rumour' => ['id' => (int)$row['id'], 'tier' => $tier, 'text' => $text, 'price' => $price, 'expires_at' => $seed['expires_at']]];
        } catch (TradeRefused $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->fail($e->getMessage(), $e->errorCode);
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Pick a live, unbought seed in the wanted truth state, falling back to any state. Never concerns a protected player. */
    private function pickSeed(int $shipId, int $zoneId, string $wanted): ?array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM rumour_seeds s WHERE s.expires_at > now() AND (s.zone_scope IS NULL OR s.zone_scope = :z)
               AND NOT EXISTS (SELECT 1 FROM rumour_purchases p WHERE p.seed_id = s.id AND p.ship_id = :ship)
             ORDER BY RANDOM()",
            ['z' => $zoneId, 'ship' => $shipId]
        );
        $rows = array_values(array_filter($rows, fn($r) => !$this->concernsProtected($r)));
        foreach ($rows as $r) {
            if ($r['truth_state'] === $wanted) {
                return $r;
            }
        }
        return $rows[0] ?? null;
    }

    /** Does either side of the seed involve a protected player's ship or planet? */
    private function concernsProtected(array $seed): bool
    {
        $true = json_decode((string)$seed['true_facts'], true) ?: [];
        foreach (['ship_id', 'owner_id'] as $k) {
            if (!empty($true[$k]) && $this->protection->isProtectedId((int)$true[$k])) {
                return true;
            }
        }
        if (!empty($true['planet_id'])) {
            $planet = $this->db->fetchOne('SELECT planet_id, owner FROM planets WHERE planet_id = :p', ['p' => (int)$true['planet_id']]);
            if ($planet && $this->protection->isPlanetProtected($planet)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: string, 1: ?int} rendered text and the line id used (null for a fallback template) */
    private function render(array $seed, string $tier): array
    {
        $shown = json_decode((string)$seed['shown_facts'], true) ?: [];
        $line = $this->db->fetchOne(
            'SELECT id, template FROM rumour_lines WHERE type = :t AND approved AND NOT retired ORDER BY RANDOM() LIMIT 1',
            ['t' => $seed['type']]
        );
        $template = $line['template'] ?? self::FALLBACK[$seed['type']][array_rand(self::FALLBACK[$seed['type']])];
        $values = ['sector' => $this->sectorText((int)($shown['sector'] ?? 0), $tier),
            'commodity' => (string)($shown['commodity'] ?? ''), 'name' => (string)($shown['name'] ?? '')];
        // The system, not the model, supplies every fact. A line that fails validation is replaced by a template.
        $errors = $this->validator->validate($template, self::SLOTS[$seed['type']], self::SLOTS[$seed['type']], ['max_words' => (int)$this->cfg('line_max_words', 40)]);
        if ($errors && $line) {
            $template = self::FALLBACK[$seed['type']][array_rand(self::FALLBACK[$seed['type']])];
            $line = null;
        }
        if ($line) {
            $this->db->execute('UPDATE rumour_lines SET uses = uses + 1 WHERE id = :id', ['id' => (int)$line['id']]);
        }
        return [ContentValidator::fill($template, $values), $line ? (int)$line['id'] : null];
    }

    /** Exact sector for the informant; a region of about ten sectors for tavern talk. */
    public function sectorText(int $sector, string $tier): string
    {
        if ($tier === 'informant') {
            return "sector $sector";
        }
        $size = max(2, (int)$this->cfg('tavern_region_size', 10));
        $start = max(1, $sector - mt_rand(0, $size - 1));
        return "sectors $start to " . ($start + $size - 1);
    }

    // =================================================================== the buyer's log

    /** @return array<int,array> purchases with outcomes for expired rumours */
    public function log(int $shipId, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.tier, p.port_sector, p.price, p.text, p.purchased_at, s.type, s.truth_state, s.true_facts, s.expires_at,
                    (s.expires_at <= now()) AS expired
             FROM rumour_purchases p JOIN rumour_seeds s ON s.id = p.seed_id
             WHERE p.ship_id = :id ORDER BY p.purchased_at DESC, p.id DESC LIMIT ' . max(1, min(200, $limit)),
            ['id' => $shipId]
        );
        return array_map(function ($r) {
            $out = ['id' => (int)$r['id'], 'tier' => $r['tier'], 'port_sector' => (int)$r['port_sector'], 'price' => (int)$r['price'],
                'text' => $r['text'], 'purchased_at' => $r['purchased_at'], 'expires_at' => $r['expires_at'], 'expired' => (bool)$r['expired'],
                'outcome' => null, 'explanation' => null];
            if ($r['expired']) {
                $out['outcome'] = $r['truth_state'];
                $out['explanation'] = $this->describeTruth((string)$r['type'], (string)$r['truth_state'], json_decode((string)$r['true_facts'], true) ?: []);
            }
            return $out;
        }, $rows);
    }

    public function describeTruth(string $type, string $state, array $true): string
    {
        $where = isset($true['sector']) ? 'sector ' . (int)$true['sector'] : 'somewhere';
        return match ($state) {
            'true' => "True: it matched what was really happening ($where).",
            'stale' => 'Stale: it was true once, but the situation had moved on. What was real by the end: ' . $where . '.',
            default => "False: the real situation was at $where.",
        };
    }

    // =================================================================== flavour-line pool (worker side)

    /** @return array<string, array{approved: int, needed: int}> which types need new flavour lines */
    public function poolStatus(): array
    {
        $min = (int)$this->cfg('pool_min', 6);
        $have = [];
        foreach ($this->db->fetchAll('SELECT type, COUNT(*) AS c FROM rumour_lines WHERE approved AND NOT retired GROUP BY type') as $r) {
            $have[$r['type']] = (int)$r['c'];
        }
        $pending = [];
        foreach ($this->db->fetchAll('SELECT type, COUNT(*) AS c FROM rumour_lines WHERE NOT approved AND NOT retired GROUP BY type') as $r) {
            $pending[$r['type']] = (int)$r['c'];
        }
        $out = [];
        foreach (self::TYPES as $t) {
            $count = ($have[$t] ?? 0) + (!empty($this->cfg('require_line_approval', true)) ? ($pending[$t] ?? 0) : 0);
            $out[$t] = ['approved' => $have[$t] ?? 0, 'pending' => $pending[$t] ?? 0, 'needed' => max(0, $min - $count), 'slots' => self::SLOTS[$t]];
        }
        return $out;
    }

    /**
     * Validate and store flavour lines from the worker.
     * @param array<int,array{type?: string, text?: string}> $lines
     * @return array{accepted: int, rejected: array<int,array{text: string, errors: string[]}>}
     */
    public function submitLines(array $lines): array
    {
        $accepted = 0;
        $rejected = [];
        $autoApprove = empty($this->cfg('require_line_approval', true));
        foreach (array_slice($lines, 0, (int)$this->cfg('lines_batch_size', 20) * 2) as $line) {
            $type = (string)($line['type'] ?? '');
            $text = trim((string)($line['text'] ?? ''));
            if (!isset(self::SLOTS[$type])) {
                $rejected[] = ['text' => mb_substr($text, 0, 120), 'errors' => ['unknown rumour type']];
                continue;
            }
            $errors = $this->validator->validate($text, self::SLOTS[$type], self::SLOTS[$type], ['max_words' => (int)$this->cfg('line_max_words', 40), 'max_chars' => 400]);
            if ($errors) {
                $rejected[] = ['text' => mb_substr($text, 0, 120), 'errors' => $errors];
                continue;
            }
            if ($this->db->fetchOne('SELECT 1 AS x FROM rumour_lines WHERE type = :t AND template = :x', ['t' => $type, 'x' => $text])) {
                continue;   // duplicate
            }
            $this->db->query('INSERT INTO rumour_lines (type, template, approved) VALUES (:t, :x, :a)', ['t' => $type, 'x' => $text, 'a' => $autoApprove ? 't' : 'f']);
            $accepted++;
        }
        return ['accepted' => $accepted, 'rejected' => $rejected];
    }

    private function fail(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }
}
