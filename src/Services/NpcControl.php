<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Decides who is driving an NPC right now. An LLM NPC drops to its scripted
 * archetype when the worker is disabled, its budget is spent, the OpenRouter
 * call has failed repeatedly, or the worker has not checked in recently.
 */
class NpcControl
{
    public function __construct(private Database $db, private NpcSettings $settings, private array $config) {}

    public function heartbeatAgeMinutes(): ?float
    {
        $row = $this->db->fetchOne('SELECT EXTRACT(EPOCH FROM (now() - heartbeat_at)) / 60 AS age FROM npc_worker_status WHERE id = 1');
        return $row ? (float)$row['age'] : null;
    }

    public function workerAlive(): bool
    {
        $age = $this->heartbeatAgeMinutes();
        return $age !== null && $age < (float)$this->settings->get('worker_checkin_timeout_min', 15);
    }

    /** @return array{global: float, per_npc: array<int,float>} USD spent since local midnight */
    public function spendToday(): array
    {
        $perNpc = [];
        $global = 0.0;
        foreach ($this->db->fetchAll(
            "SELECT ship_id, COALESCE(SUM(cost_usd), 0) AS spent FROM npc_action_log
             WHERE created_at >= date_trunc('day', now()) GROUP BY ship_id"
        ) as $row) {
            $perNpc[(int)$row['ship_id']] = (float)$row['spent'];
            $global += (float)$row['spent'];
        }
        return ['global' => $global, 'per_npc' => $perNpc];
    }

    /**
     * Null when the NPC may be driven by the LLM, otherwise why it is on its scripted behaviour.
     * $profile needs ship_id, controller, state; $spend comes from spendToday().
     */
    public function fallbackReason(array $profile, array $spend): ?string
    {
        if (($profile['controller'] ?? 'scripted') !== 'llm') {
            return 'scripted_controller';
        }
        if (!$this->settings->llmEnabled()) {
            return 'llm_disabled';
        }
        if (!$this->workerAlive()) {
            return 'worker_silent';
        }
        $state = is_array($profile['state']) ? $profile['state'] : (json_decode((string)$profile['state'], true) ?: []);
        if ((int)($state['llm']['failures'] ?? 0) >= (int)$this->settings->get('llm_failure_threshold', 3)) {
            return 'openrouter_failures';
        }
        if (($spend['global'] ?? 0.0) >= (float)$this->settings->get('global_daily_budget_usd', 10.0)) {
            return 'global_budget_spent';
        }
        if (($spend['per_npc'][(int)$profile['ship_id']] ?? 0.0) >= (float)$this->settings->get('daily_budget_usd', 1.0)) {
            return 'npc_budget_spent';
        }
        return null;
    }
}
