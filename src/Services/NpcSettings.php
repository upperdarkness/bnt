<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * npc.* configuration with runtime overrides stored in npc_settings
 * (kill switch, default model, budgets). Config file values are the defaults.
 */
class NpcSettings
{
    /** Keys an admin may override at runtime. */
    public const OVERRIDABLE = ['llm_enabled', 'default_model', 'daily_budget_usd', 'global_daily_budget_usd',
        'wake_interval_min', 'min_turns_to_wake', 'max_steps', 'event_debounce_min', 'enabled'];

    private ?array $overrides = null;

    public function __construct(private Database $db, private array $config) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $base = $this->config['npc'][$key] ?? $default;
        if (!in_array($key, self::OVERRIDABLE, true)) {
            return $base;
        }
        $overrides = $this->overrides();
        if (!array_key_exists($key, $overrides)) {
            return $base;
        }
        $raw = $overrides[$key];
        return match (true) {
            is_bool($base) => in_array(strtolower((string)$raw), ['1', 't', 'true', 'yes', 'on'], true),
            is_int($base) => (int)$raw,
            is_float($base) => (float)$raw,
            default => $raw,
        };
    }

    public function set(string $key, mixed $value): void
    {
        if (!in_array($key, self::OVERRIDABLE, true)) {
            throw new \InvalidArgumentException("npc.$key cannot be changed at runtime");
        }
        $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        // Straight PDO: Database::query() would turn the strings 'true'/'false' into booleans.
        $this->db->getConnection()->prepare(
            'INSERT INTO npc_settings (key, value, updated_at) VALUES (:k, :v, now())
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, updated_at = now()'
        )->execute(['k' => $key, 'v' => $stored]);
        $this->overrides = null;
    }

    public function llmEnabled(): bool
    {
        return (bool)$this->get('llm_enabled', false);
    }

    public function npcEnabled(): bool
    {
        return (bool)$this->get('enabled', true);
    }

    private function overrides(): array
    {
        if ($this->overrides === null) {
            $this->overrides = [];
            foreach ($this->db->fetchAll('SELECT key, value FROM npc_settings') as $row) {
                $this->overrides[$row['key']] = $row['value'];
            }
        }
        return $this->overrides;
    }
}
