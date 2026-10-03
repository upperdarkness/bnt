<?php

declare(strict_types=1);

namespace BNT\Core;

use BNT\Services\AlignmentService;
use BNT\Services\NpcControl;
use BNT\Services\NpcMonitor;
use BNT\Services\NpcScriptedBrain;
use BNT\Services\NpcService;
use BNT\Services\NpcSettings;
use BNT\Services\PoliceService;
use BNT\Services\SectorGraph;

/**
 * Scheduler tasks for alignment and NPCs. These run on player page loads, so
 * the scripted tick is hard-capped (npc.scripted_tick_max_npcs / _max_ms) and
 * no LLM call ever happens here.
 */
class NpcSchedulerTasks
{
    private ?array $busyCache = null;
    private int $busyCacheAt = 0;

    public function __construct(
        private Database $db,
        private array $config,
        private NpcSettings $settings,
        private NpcService $npcs,
        private NpcScriptedBrain $brain,
        private NpcControl $control,
        private NpcMonitor $monitor,
        private PoliceService $police,
        private AlignmentService $alignment,
        private SectorGraph $graph
    ) {}

    /** Spawn and respawn to target counts; keep NPC accounts "active" for turn generation. */
    public function population(): string
    {
        if (!$this->settings->npcEnabled()) {
            return 'NPCs disabled';
        }
        $this->db->execute('UPDATE ships SET last_login = NOW() WHERE is_npc = TRUE AND ship_destroyed = FALSE');
        $this->graph->refresh();

        $cooldown = (int)$this->config['npc']['respawn_cooldown_hours'];
        $this->db->execute(
            "UPDATE npc_profiles SET respawn_at = now() + make_interval(hours => :h)
             WHERE respawn_at IS NULL AND (state ->> 'retired') IS NULL
               AND ship_id IN (SELECT ship_id FROM ships WHERE ship_destroyed = TRUE)",
            ['h' => $cooldown]
        );
        $respawned = 0;
        foreach ($this->db->fetchAll(
            "SELECT ship_id FROM npc_profiles WHERE respawn_at IS NOT NULL AND respawn_at <= now() AND (state ->> 'retired') IS NULL"
        ) as $row) {
            if ($this->npcs->respawn((int)$row['ship_id'])) {
                $respawned++;
            }
        }

        $this->applyCreditCap();

        $sectors = (int)$this->db->fetchOne('SELECT COUNT(*) AS c FROM universe')['c'];
        $counts = [];
        foreach ($this->db->fetchAll(
            "SELECT faction, COUNT(*) AS c FROM npc_profiles WHERE (state ->> 'retired') IS NULL AND (state ->> 'temporary') IS NULL GROUP BY faction"
        ) as $row) {
            $counts[$row['faction']] = (int)$row['c'];
        }
        $spawned = 0;
        if ($sectors > 0) {
            foreach (array_keys($this->npcs->factions()) as $faction) {
                $missing = $this->npcs->targetCount($faction, $sectors) - ($counts[$faction] ?? 0);
                for ($i = 0; $i < $missing; $i++) {
                    $this->npcs->spawn($faction);
                    $spawned++;
                }
            }
        }
        $alerts = $this->monitor->check();
        return "Spawned $spawned, respawned $respawned" . ($alerts ? ', alerts: ' . implode(',', $alerts) : '');
    }

    /** Federation tax: NPC credits above loadout x npc.credit_cap_multiplier are removed (wealth sink). */
    private function applyCreditCap(): void
    {
        $mult = (float)($this->config['npc']['credit_cap_multiplier'] ?? 0);
        if ($mult <= 0) {
            return;
        }
        foreach ($this->config['npc']['factions'] as $faction => $tpl) {
            $cap = (int)round(((int)($tpl['loadout']['credits'] ?? 0)) * $mult);
            if ($cap > 0) {
                $this->db->execute(
                    'UPDATE ships SET credits = :cap WHERE is_npc = TRUE AND credits > :cap2
                     AND ship_id IN (SELECT ship_id FROM npc_profiles WHERE faction = :f)',
                    ['cap' => $cap, 'cap2' => $cap, 'f' => $faction]
                );
            }
        }
    }

    /** Run scripted NPCs: at most N NPCs and M milliseconds per tick. */
    public function scriptedTick(): string
    {
        if (!$this->settings->npcEnabled()) {
            return 'NPCs disabled';
        }
        $maxNpcs = (int)$this->config['npc']['scripted_tick_max_npcs'];
        $maxMs = (int)$this->config['npc']['scripted_tick_max_ms'];
        $start = microtime(true);
        $margin = (int)($this->config['npc']['scripted_tick_margin_ms'] ?? 10) / 1000;
        $deadline = $start + $maxMs / 1000 - $margin;   // an action already under way may use the margin

        $spend = $this->control->spendToday();
        $candidates = $this->db->fetchAll(
            "SELECT p.*, s.turns FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             WHERE s.ship_destroyed = FALSE AND s.turns >= 1 AND (p.state ->> 'retired') IS NULL AND p.faction <> 'press'
             ORDER BY (p.state -> 'script' ->> 'last_tick') ASC NULLS FIRST, p.ship_id
             LIMIT " . ($maxNpcs * 4)
        );
        $ctx = ['deadline' => $deadline, 'busy_sectors' => $this->busySectors()];
        $pdo = $this->db->getConnection();
        $ran = 0;
        $errors = 0;
        foreach ($candidates as $profile) {
            if ($ran >= $maxNpcs || microtime(true) > $deadline) {
                break;
            }
            $profile['state'] = json_decode((string)$profile['state'], true) ?: [];
            if ($this->control->fallbackReason($profile, $spend) === null) {
                continue; // an active LLM NPC; the worker drives it
            }
            $nested = $pdo->inTransaction();
            try {
                if ($nested) {
                    $pdo->exec('SAVEPOINT npc_tick');
                }
                $this->brain->tick($profile, $ctx);
                if ($nested) {
                    $pdo->exec('RELEASE SAVEPOINT npc_tick');
                }
            } catch (\Throwable $e) {
                $errors++;
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT npc_tick');
                }
                error_log('NPC tick failed for ship ' . $profile['ship_id'] . ': ' . $e->getMessage());
            }
            $ran++;
        }
        $ms = round((microtime(true) - $start) * 1000, 1);
        return "Ran $ran NPCs in {$ms}ms" . ($errors ? ", $errors errors" : '');
    }

    public function policeDispatch(): string
    {
        if (!$this->settings->npcEnabled() || !$this->alignment->enabled()) {
            return 'disabled';
        }
        $r = $this->police->dispatch();
        return sprintf('Wanted %d, assigned %d, spawned %d, recalled %d, retired %d',
            $r['wanted'], $r['assigned'], $r['spawned'], $r['recalled'], $r['retired']);
    }

    public function alignmentDrift(): string
    {
        $out = [];
        if ($this->alignment->enabled()) {
            $r = $this->alignment->dailyDrift();
            $out[] = "drifted {$r['drifted']}, wanted expired {$r['expired']}, pirates renewed {$r['renewed']}";
        }
        $days = (int)$this->config['npc']['log_retention_days'];
        $deleted = $this->db->query(
            'DELETE FROM npc_action_log WHERE created_at < now() - make_interval(days => :d)', ['d' => $days]
        )->rowCount();
        $this->db->execute("DELETE FROM npc_events WHERE consumed_at IS NOT NULL AND consumed_at < now() - interval '7 days'");
        $this->db->execute("DELETE FROM api_rate_buckets WHERE updated_at < now() - interval '1 day'");
        $out[] = "pruned $deleted action-log rows";
        return implode('; ', $out);
    }

    /** Busiest non-FedSpace sectors over the last 24 hours (candidate trade lanes). */
    private function busySectors(): array
    {
        if ($this->busyCache === null || time() - $this->busyCacheAt > 300) {
            $this->busyCache = array_map(static fn($r) => (int)$r['sector_id'], $this->db->fetchAll(
                "SELECT m.sector_id FROM movement_log m JOIN ships s ON s.ship_id = m.ship_id
                 WHERE m.time > now() - interval '24 hours' AND s.is_npc = FALSE
                 GROUP BY m.sector_id ORDER BY COUNT(*) DESC LIMIT 10"
            ));
            $this->busyCacheAt = time();
        }
        return $this->busyCache;
    }
}
