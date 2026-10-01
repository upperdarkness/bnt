<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Federation police dispatch: assigns police NPCs to Wanted ships and recalls
 * those that are idle. Police state lives in npc_profiles.state.police:
 *   {target: shipId|null, assigned_at, last_contact_at, mode}
 */
class PoliceService
{
    public function __construct(
        private Database $db,
        private AlignmentService $alignment,
        private NpcService $npcs,
        private SectorGraph $graph,
        private array $config
    ) {}

    private ?array $fedCache = null;
    private int $fedCacheAt = 0;

    /**
     * @return array{assigned: int, spawned: int, recalled: int, wanted: int}
     */
    public function dispatch(): array
    {
        $rules = $this->alignment->rules();
        $pirateMax = (int)$this->config['alignment']['tiers']['outlaw'] - 1;
        $hours = (int)$rules->config('police_return_hours', 48);

        $wanted = $this->db->fetchAll(
            'SELECT s.ship_id, s.alignment, s.is_npc, s.sector, s.last_known_sector, s.last_known_at, s.wanted_until
             FROM ships s
             WHERE s.ship_destroyed = FALSE AND (s.wanted_until > now() OR s.alignment <= :p)',
            ['p' => $pirateMax]
        );
        $wantedIds = array_map(static fn($r) => (int)$r['ship_id'], $wanted);

        $police = $this->db->fetchAll(
            "SELECT p.ship_id, p.state, s.sector FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             WHERE p.faction = 'police' AND s.ship_destroyed = FALSE AND (p.state ->> 'retired') IS NULL"
        );
        $assignedCount = [];
        $idle = [];
        $recalled = 0;
        foreach ($police as $p) {
            $state = json_decode((string)$p['state'], true) ?: [];
            $target = $state['police']['target'] ?? null;
            $contact = isset($state['police']['last_contact_at']) ? strtotime((string)$state['police']['last_contact_at']) : null;
            $expired = $contact !== null && (time() - $contact) > $hours * 3600;
            if ($target !== null && (!in_array((int)$target, $wantedIds, true) || $expired)) {
                $this->npcs->replaceState((int)$p['ship_id'], 'police', ['target' => null, 'mode' => 'return']);
                $recalled++;
                $target = null;
                $state['police']['mode'] = 'return';
            }
            $mode = $state['police']['mode'] ?? 'patrol';
            if ($target === null) {
                // A unit that has just been recalled heads home first; it is not re-tasked until it is back.
                if ($mode !== 'return') {
                    $idle[] = ['ship_id' => (int)$p['ship_id'], 'sector' => (int)$p['sector']];
                }
            } else {
                $assignedCount[(int)$target] = ($assignedCount[(int)$target] ?? 0) + 1;
            }
        }

        $assigned = 0;
        $spawned = 0;
        foreach ($wanted as $w) {
            $need = $rules->policeCount((int)$w['alignment']) - ($assignedCount[(int)$w['ship_id']] ?? 0);
            if ($need <= 0) {
                continue;
            }
            $anchor = (int)($w['last_known_sector'] ?? $w['sector']);
            // Idle police nearest to the offender first.
            $dist = $this->graph->distances($anchor, (int)$this->config['npc']['max_path_depth']);
            usort($idle, static fn($a, $b) => ($dist[$a['sector']] ?? PHP_INT_MAX) <=> ($dist[$b['sector']] ?? PHP_INT_MAX));
            while ($need > 0 && $idle) {
                $unit = array_shift($idle);
                $this->assign($unit['ship_id'], $w);
                $assigned++;
                $need--;
            }
            // Human offenders may get extra temporary units spawned from the nearest Federation sector.
            if ($need > 0 && empty($w['is_npc']) && $this->temporaryPoliceCount() < 20) {
                $home = $this->nearestFederationSector($anchor);
                for (; $need > 0; $need--) {
                    $new = $this->npcs->spawn('police', ['sector' => $home, 'temporary' => true]);
                    $this->assign($new['ship_id'], $w);
                    $spawned++;
                    $assigned++;
                }
            }
        }
        $retired = $this->retireIdleTemporary();
        return ['assigned' => $assigned, 'spawned' => $spawned, 'recalled' => $recalled, 'wanted' => count($wanted), 'retired' => $retired];
    }

    private function assign(int $policeId, array $target): void
    {
        $this->npcs->replaceState($policeId, 'police', [
            'target' => (int)$target['ship_id'],
            'assigned_at' => date('c'),
            'last_contact_at' => date('c'),
            'mode' => 'pursue',
        ]);
    }

    private function temporaryPoliceCount(): int
    {
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             WHERE p.faction = 'police' AND (p.state ->> 'temporary') = 'true' AND s.ship_destroyed = FALSE"
        )['c'];
    }

    /** Temporary units that are idle and back in Federation space are stood down. */
    private function retireIdleTemporary(): int
    {
        $rows = $this->db->fetchAll(
            "SELECT p.ship_id, p.state, s.sector FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             WHERE p.faction = 'police' AND (p.state ->> 'temporary') = 'true' AND s.ship_destroyed = FALSE"
        );
        $n = 0;
        foreach ($rows as $r) {
            $state = json_decode((string)$r['state'], true) ?: [];
            if (($state['police']['target'] ?? null) !== null) {
                continue;
            }
            $flags = $this->db->fetchOne(
                'SELECT COALESCE(z.is_federation, FALSE) AS fed FROM universe u LEFT JOIN zones z ON z.zone_id = u.zone_id WHERE u.sector_id = :s',
                ['s' => (int)$r['sector']]
            );
            if ($flags && $flags['fed']) {
                $this->db->execute('UPDATE ships SET ship_destroyed = TRUE WHERE ship_id = :id', ['id' => (int)$r['ship_id']]);
                $this->db->execute("UPDATE npc_profiles SET state = state || '{\"retired\": true}'::jsonb, respawn_at = NULL WHERE ship_id = :id", ['id' => (int)$r['ship_id']]);
                $n++;
            }
        }
        return $n;
    }

    public function nearestFederationSector(int $from): int
    {
        $fed = $this->federationSectors();
        $found = $this->graph->nearest($from, static fn(int $s) => isset($fed[$s]), (int)$this->config['npc']['max_path_depth']);
        if ($found !== null) {
            return $found;
        }
        return $fed ? (int)array_key_first($fed) : 1;
    }

    /** @return array<int,true> */
    public function federationSectors(): array
    {
        if ($this->fedCache === null || time() - $this->fedCacheAt > 600) {
            $cache = [];
            foreach ($this->db->fetchAll(
                'SELECT u.sector_id FROM universe u JOIN zones z ON z.zone_id = u.zone_id WHERE z.is_federation'
            ) as $r) {
                $cache[(int)$r['sector_id']] = true;
            }
            $this->fedCache = $cache;
            $this->fedCacheAt = time();
        }
        return $this->fedCache;
    }
}
