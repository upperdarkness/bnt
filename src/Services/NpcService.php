<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\ApiAuth;
use BNT\Core\Database;

/**
 * NPC accounts: spawning, respawning, population targets and tokens.
 * An NPC is an ordinary ship row (is_npc = TRUE) plus an npc_profiles row.
 */
class NpcService
{
    private const FIRST = ['Kraal', 'Vex', 'Mara', 'Dun', 'Tessa', 'Orin', 'Jax', 'Ilsa', 'Brek', 'Soren', 'Nyla', 'Garrick', 'Lio', 'Pete', 'Hana', 'Rurik', 'Zed', 'Calla', 'Oskar', 'Wren'];
    private const LAST = ['Vesh', 'Morrow', 'Halloran', 'Quill', 'Strand', 'Okoro', 'Draven', 'Kessler', 'Thorne', 'Vance', 'Mbeki', 'Lund', 'Orsk', 'Pike', 'Reyes', 'Sato', 'Voss', 'Yarrow', 'Ashby', 'Brandt'];

    private const TEMPERAMENTS = [
        'police' => ['stern and procedural', 'weary but dutiful'],
        'guild' => ['cautious and courteous', 'shrewd and talkative'],
        'xenobe' => ['cruel and opportunistic', 'patient and vengeful'],
        'free' => ['restless and curious', 'roguish and witty'],
        'press' => ['wry and observant', 'earnest and nosy'],
    ];

    private const GOALS = [
        'police' => ['Hunt Wanted ships and keep FedSpace safe.', 'Never attack ships that are not Wanted.'],
        'guild' => ['Make steady profit trading between ports.', 'Avoid fights; flee toward FedSpace if outgunned.'],
        'xenobe' => ['Prey on weak traders and lay mines on busy lanes.', 'Never attack other Xenobe Raiders.'],
        'free' => ['Trade for profit and seize clear opportunities.', 'Avoid combat unless the odds are overwhelming.'],
        'press' => ['Report the news accurately and fairly.', 'Never take sides or invent anything.'],
    ];

    public function __construct(
        private Database $db,
        private AlignmentService $alignment,
        private ApiAuth $apiAuth,
        private SectorRules $sectors,
        private array $config
    ) {}

    public function factions(): array
    {
        return $this->config['npc']['factions'];
    }

    public function targetCount(string $faction, int $sectorCount): int
    {
        $per = (float)($this->config['npc']['factions'][$faction]['count_per_1000'] ?? 0);
        if ($per <= 0 || $sectorCount <= 0) {
            return 0;
        }
        return max(1, (int)round($per * $sectorCount / 1000));
    }

    // ---------------------------------------------------------------- spawn

    /**
     * Create a brand-new NPC account.
     *
     * @param array{name?: string, sector?: int, controller?: string, model?: string, temporary?: bool, persona?: array} $opts
     * @return array{ship_id: int, name: string, email: string}
     */
    public function spawn(string $faction, array $opts = []): array
    {
        $tpl = $this->config['npc']['factions'][$faction] ?? null;
        if (!$tpl) {
            throw new \InvalidArgumentException("Unknown faction '$faction'");
        }
        $name = $opts['name'] ?? $this->randomName($faction);
        $character = '[' . $tpl['tag'] . '] ' . $name;
        $character = $this->uniqueName($character);
        $slug = $this->slug($character);
        $email = $slug . '@npc.invalid';
        $loadout = $tpl['loadout'];
        $sector = $opts['sector'] ?? $this->homeSector((int)$tpl['home_zone']);

        $pdo = $this->db->getConnection();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->db->fetchOne(
                'INSERT INTO ships (email, password_hash, character_name, ship_name, ship_type,
                    hull, engines, power, computer, sensors, beams, torp_launchers, shields, armor, cloak,
                    ship_fighters, torps, armor_pts, credits, turns, ship_energy, sector, is_npc, last_login, skill_trading, skill_combat, protection_state)
                 VALUES (:email, :hash, :name, :ship_name, :ship_type,
                    :hull, :engines, :power, :computer, :sensors, :beams, :torp_launchers, :shields, :armor, 0,
                    :fighters, :torps, :armor_pts, :credits, :turns, :energy, :sector, TRUE, NOW(), :skill_trading, :skill_combat, :prot)
                 RETURNING ship_id',
                [
                    'email' => $email,
                    // Not a valid hash for any password: web login is impossible.
                    'hash' => '!' . bin2hex(random_bytes(32)),
                    'name' => $character,
                    'ship_name' => $name . "'s ship",
                    'ship_type' => $loadout['ship_type'] ?? 'balanced',
                    'hull' => $loadout['hull'] ?? 0, 'engines' => $loadout['engines'] ?? 0,
                    'power' => $loadout['power'] ?? 0, 'computer' => $loadout['computer'] ?? 0,
                    'sensors' => $loadout['sensors'] ?? 0, 'beams' => $loadout['beams'] ?? 0,
                    'torp_launchers' => $loadout['torp_launchers'] ?? 0, 'shields' => $loadout['shields'] ?? 0,
                    'armor' => $loadout['armor'] ?? 0,
                    'fighters' => $loadout['ship_fighters'] ?? 0, 'torps' => $loadout['torps'] ?? 0,
                    'armor_pts' => (int)round(pow(1.5, (int)($loadout['armor'] ?? 0)) * 100),
                    'credits' => $loadout['credits'] ?? 0,
                    'turns' => (int)$this->config['game']['start_turns'],
                    'energy' => (int)$this->config['game']['start_energy'],
                    'sector' => $sector,
                    'skill_trading' => (int)($loadout['skill_trading'] ?? 0),
                    'skill_combat' => (int)($loadout['skill_combat'] ?? 0),
                    'prot' => 'none',   // NPCs never get protection
                ]
            );
            $shipId = (int)$row['ship_id'];
            $this->db->execute('INSERT INTO ibank_accounts (ship_id, balance, loan) VALUES (:id, 0, 0)', ['id' => $shipId]);
            $persona = ($opts['persona'] ?? []) + $this->defaultPersona($faction, $name);
            $this->db->execute(
                "INSERT INTO npc_profiles (ship_id, faction, archetype, controller, persona, model, home_zone, state)
                 VALUES (:id, :faction, :arch, :controller, CAST(:persona AS JSONB), :model, :zone, CAST(:state AS JSONB))",
                [
                    'id' => $shipId, 'faction' => $faction, 'arch' => $tpl['archetype'],
                    'controller' => $opts['controller'] ?? 'scripted', 'persona' => json_encode($persona),
                    'model' => $opts['model'] ?? null, 'zone' => $this->zoneOrNull((int)$tpl['home_zone']),
                    'state' => json_encode(!empty($opts['temporary']) ? ['temporary' => true] : new \stdClass()),
                ]
            );
            $this->setFactionAlignment($shipId, (int)$tpl['alignment'], 'npc_spawn');
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['ship_id' => $shipId, 'name' => $character, 'email' => $email];
    }

    /** Issue an API token for an NPC (valid npc.token_lifetime_days, 30 by default). */
    public function issueToken(int $shipId): array
    {
        $this->apiAuth->revokeAllTokens($shipId);
        return $this->apiAuth->generateToken($shipId, 'npc-worker', (int)$this->config['npc']['token_lifetime_days']);
    }

    private function setFactionAlignment(int $shipId, int $target, string $reason): void
    {
        $this->db->execute('UPDATE ships SET alignment = 0 WHERE ship_id = :id', ['id' => $shipId]);
        if ($target !== 0) {
            $this->alignment->apply($shipId, $target, $reason);
        }
        // Pirates are Wanted automatically (apply() handles that for negative targets).
    }

    // -------------------------------------------------------------- respawn

    /**
     * Reset a destroyed NPC to a fresh ship under the same profile. The ship row, API token,
     * persona and notebook are kept, so an LLM NPC remembers who killed it.
     */
    public function respawn(int $shipId): bool
    {
        $profile = $this->profile($shipId);
        if (!$profile) {
            return false;
        }
        $tpl = $this->config['npc']['factions'][$profile['faction']];
        $loadout = $tpl['loadout'];
        $sector = $this->homeSector((int)($profile['home_zone'] ?? $tpl['home_zone']));
        $this->db->execute(
            'UPDATE ships SET ship_destroyed = FALSE, on_planet = FALSE, planet_id = 0, sector = :sector,
                hull = :hull, engines = :engines, power = :power, computer = :computer, sensors = :sensors,
                beams = :beams, torp_launchers = :tl, shields = :shields, armor = :armor, cloak = 0,
                ship_ore = 0, ship_organics = 0, ship_goods = 0, ship_energy = :energy, ship_colonists = 0,
                ship_fighters = :fighters, torps = :torps, armor_pts = :armor_pts, credits = :credits,
                turns = :turns, skill_trading = :skill_trading, skill_combat = :skill_combat,
                wanted_until = NULL, last_known_sector = NULL, last_known_at = NULL, last_login = NOW()
             WHERE ship_id = :id',
            [
                'sector' => $sector, 'hull' => $loadout['hull'] ?? 0, 'engines' => $loadout['engines'] ?? 0,
                'power' => $loadout['power'] ?? 0, 'computer' => $loadout['computer'] ?? 0, 'sensors' => $loadout['sensors'] ?? 0,
                'beams' => $loadout['beams'] ?? 0, 'tl' => $loadout['torp_launchers'] ?? 0, 'shields' => $loadout['shields'] ?? 0,
                'armor' => $loadout['armor'] ?? 0, 'energy' => (int)$this->config['game']['start_energy'],
                'fighters' => $loadout['ship_fighters'] ?? 0, 'torps' => $loadout['torps'] ?? 0,
                'armor_pts' => (int)round(pow(1.5, (int)($loadout['armor'] ?? 0)) * 100),
                'credits' => $loadout['credits'] ?? 0, 'turns' => (int)$this->config['game']['start_turns'],
                'skill_trading' => (int)($loadout['skill_trading'] ?? 0), 'skill_combat' => (int)($loadout['skill_combat'] ?? 0), 'id' => $shipId,
            ]
        );
        $current = (int)$this->db->fetchOne('SELECT alignment FROM ships WHERE ship_id = :id', ['id' => $shipId])['alignment'];
        if ($current !== (int)$tpl['alignment']) {
            $this->alignment->apply($shipId, (int)$tpl['alignment'] - $current, 'npc_respawn');
        }
        $this->db->execute("UPDATE npc_profiles SET respawn_at = NULL, state = '{}'::jsonb WHERE ship_id = :id", ['id' => $shipId]);
        return true;
    }

    // ------------------------------------------------------------- queries

    public function profile(int $shipId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT p.*, s.character_name, s.sector, s.ship_destroyed, s.alignment, s.turns, s.credits
             FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id WHERE p.ship_id = :id',
            ['id' => $shipId]
        );
        if ($row) {
            foreach (['persona', 'state'] as $k) {
                $row[$k] = is_string($row[$k]) ? (json_decode($row[$k], true) ?: []) : $row[$k];
            }
        }
        return $row;
    }

    public function isNpc(int $shipId): bool
    {
        return (bool)$this->db->fetchOne('SELECT 1 AS x FROM npc_profiles WHERE ship_id = :id', ['id' => $shipId]);
    }

    /** Merge a patch into npc_profiles.state (so scripted and LLM code never clobber each other). */
    public function patchState(int $shipId, string $namespace, array $patch): void
    {
        $this->db->execute(
            "UPDATE npc_profiles SET state = jsonb_set(state, ARRAY[CAST(:ns AS TEXT)],
                COALESCE(state -> CAST(:ns2 AS TEXT), '{}'::jsonb) || CAST(:patch AS JSONB), TRUE)
             WHERE ship_id = :id",
            ['ns' => $namespace, 'ns2' => $namespace, 'patch' => json_encode($patch), 'id' => $shipId]
        );
    }

    public function replaceState(int $shipId, string $namespace, array $value): void
    {
        $this->db->execute(
            "UPDATE npc_profiles SET state = jsonb_set(state, ARRAY[CAST(:ns AS TEXT)], CAST(:v AS JSONB), TRUE) WHERE ship_id = :id",
            ['ns' => $namespace, 'v' => json_encode($value === [] ? new \stdClass() : $value), 'id' => $shipId]
        );
    }

    // -------------------------------------------------------------- helpers

    /** home_zone has a foreign key; a configured zone that does not exist yet is stored as NULL. */
    private function zoneOrNull(int $zoneId): ?int
    {
        return ($zoneId > 0 && $this->db->fetchOne('SELECT 1 AS x FROM zones WHERE zone_id = :z', ['z' => $zoneId])) ? $zoneId : null;
    }

    public function homeSector(int $zoneId): int
    {
        $row = $this->db->fetchOne(
            'SELECT sector_id FROM universe WHERE zone_id = :z AND is_starbase = FALSE ORDER BY RANDOM() LIMIT 1',
            ['z' => $zoneId]
        );
        if (!$row) {
            $row = $this->db->fetchOne('SELECT sector_id FROM universe WHERE is_starbase = FALSE ORDER BY RANDOM() LIMIT 1');
        }
        if (!$row) {
            $row = $this->db->fetchOne('SELECT sector_id FROM universe ORDER BY sector_id LIMIT 1');
        }
        if (!$row) {
            throw new \RuntimeException('Universe is empty; create it before spawning NPCs');
        }
        return (int)$row['sector_id'];
    }

    public function randomName(string $faction): string
    {
        return self::FIRST[array_rand(self::FIRST)] . ' ' . self::LAST[array_rand(self::LAST)];
    }

    private function uniqueName(string $base): string
    {
        $name = $base;
        $i = 2;
        while ($this->db->fetchOne('SELECT 1 AS x FROM ships WHERE character_name = :n', ['n' => $name])) {
            $name = $base . ' ' . $this->roman($i++);
        }
        return $name;
    }

    private function roman(int $n): string
    {
        return ['', '', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'][$n] ?? (string)$n;
    }

    public function slug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $base = $slug ?: 'npc';
        $slug = $base;
        $i = 2;
        while ($this->db->fetchOne('SELECT 1 AS x FROM ships WHERE email = :e', ['e' => $slug . '@npc.invalid'])) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    public function defaultPersona(string $faction, string $name): array
    {
        $temper = self::TEMPERAMENTS[$faction];
        return [
            'name' => $name,
            'temperament' => $temper[array_rand($temper)],
            'speech_style' => 'terse, in character, never more than two sentences',
            'goals' => self::GOALS[$faction],
        ];
    }
}
