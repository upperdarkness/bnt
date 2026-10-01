<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/** Anomaly and operational alerts, shown in the admin panel and emailed when configured. */
class NpcMonitor
{
    public function __construct(private Database $db, private NpcSettings $settings, private NpcControl $control, private array $config) {}

    /** Record an alert unless the same (kind, ship) alerted within the last hour. */
    public function alert(string $kind, ?int $shipId, array $detail = []): bool
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM npc_alerts WHERE kind = :k AND ship_id IS NOT DISTINCT FROM :s
             AND created_at > now() - interval '1 hour' LIMIT 1",
            ['k' => $kind, 's' => $shipId]
        );
        if ($existing) {
            return false;
        }
        $row = $this->db->fetchOne(
            'INSERT INTO npc_alerts (kind, ship_id, detail) VALUES (:k, :s, CAST(:d AS JSONB)) RETURNING id',
            ['k' => $kind, 's' => $shipId, 'd' => json_encode($detail === [] ? new \stdClass() : $detail)]
        );
        $to = (string)($this->config['npc']['alert_email'] ?? '');
        if ($to !== '' && $row) {
            $body = "BNT NPC alert: $kind\n" . ($shipId ? "Ship: $shipId\n" : '') . json_encode($detail, JSON_PRETTY_PRINT);
            if (@mail($to, "[BNT] NPC alert: $kind", $body)) {
                $this->db->execute('UPDATE npc_alerts SET emailed_at = now() WHERE id = :id', ['id' => (int)$row['id']]);
            }
        }
        return true;
    }

    /** An NPC hit a member of its own faction. */
    public function friendlyFire(array $attacker, array $victim): void
    {
        if (!empty($attacker['is_npc']) && !empty($victim['is_npc'])
            && ($attacker['faction'] ?? null) !== null && ($attacker['faction'] ?? null) === ($victim['faction'] ?? null)) {
            $this->alert('faction_friendly_fire', (int)$attacker['ship_id'], [
                'faction' => $attacker['faction'], 'victim' => (int)$victim['ship_id'],
            ]);
        }
    }

    /**
     * Periodic health checks (run from the npc_population task).
     * @return string[] kinds of alerts raised this run
     */
    public function check(): array
    {
        $raised = [];
        $llmNpcs = (int)$this->db->fetchOne("SELECT COUNT(*) AS c FROM npc_profiles WHERE controller = 'llm'")['c'];
        if ($this->settings->llmEnabled() && $llmNpcs > 0 && !$this->control->workerAlive()) {
            if ($this->alert('worker_heartbeat_stale', null, ['age_minutes' => $this->control->heartbeatAgeMinutes()])) {
                $raised[] = 'worker_heartbeat_stale';
            }
        }
        $stats = $this->db->fetchOne(
            "SELECT COUNT(*) FILTER (WHERE tool = '_error') AS errors,
                    COUNT(*) FILTER (WHERE tool IS NULL OR tool = '_error') AS calls
             FROM npc_action_log WHERE created_at > now() - interval '1 hour'"
        );
        $calls = (int)($stats['calls'] ?? 0);
        if ($calls >= 5 && ((int)$stats['errors'] / $calls) > 0.20) {
            if ($this->alert('openrouter_error_rate', null, ['errors' => (int)$stats['errors'], 'calls' => $calls])) {
                $raised[] = 'openrouter_error_rate';
            }
        }
        $spend = $this->control->spendToday()['global'];
        $cap = (float)$this->settings->get('global_daily_budget_usd', 10.0);
        if ($cap > 0 && $spend >= 0.8 * $cap) {
            if ($this->alert('global_spend_80pct', null, ['spent_usd' => round($spend, 4), 'cap_usd' => $cap])) {
                $raised[] = 'global_spend_80pct';
            }
        }
        return $raised;
    }

    public function open(int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM npc_alerts WHERE acknowledged_at IS NULL ORDER BY created_at DESC LIMIT ' . (int)$limit
        );
    }

    public function acknowledge(int $id): void
    {
        $this->db->execute('UPDATE npc_alerts SET acknowledged_at = now() WHERE id = :id', ['id' => $id]);
    }
}
