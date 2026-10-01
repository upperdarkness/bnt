<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

use PDO;

/**
 * The worker's control-plane storage: wake scheduling, budgets, heartbeat and
 * the audit log. Game actions never go through here; they use the public API.
 */
class Store
{
    private ?array $overrides = null;
    private int $overridesAt = 0;

    public function __construct(private PDO $pdo, private array $npcConfig) {}

    public static function connect(array $dbConfig): PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['database']);
        return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** npc.* setting with admin overrides (kill switch, budgets, model) applied; refreshed every few seconds. */
    public function setting(string $key, mixed $default = null): mixed
    {
        if ($this->overrides === null || time() - $this->overridesAt >= 3) {
            $this->overrides = [];
            foreach ($this->pdo->query('SELECT key, value FROM npc_settings')->fetchAll() as $row) {
                $this->overrides[$row['key']] = $row['value'];
            }
            $this->overridesAt = time();
        }
        $base = $this->npcConfig[$key] ?? $default;
        if (!array_key_exists($key, $this->overrides)) {
            return $base;
        }
        $raw = $this->overrides[$key];
        return match (true) {
            is_bool($base) => in_array(strtolower((string)$raw), ['1', 't', 'true', 'yes', 'on'], true),
            is_int($base) => (int)$raw,
            is_float($base) => (float)$raw,
            default => $raw,
        };
    }

    public function heartbeat(): void
    {
        $this->pdo->exec('INSERT INTO npc_worker_status (id, heartbeat_at) VALUES (1, now())
                          ON CONFLICT (id) DO UPDATE SET heartbeat_at = now()');
    }

    /** @return array<int,array> LLM-controlled NPCs that are alive */
    public function llmNpcs(): array
    {
        $rows = $this->pdo->query(
            "SELECT p.ship_id, p.faction, p.archetype, p.persona, p.model, p.notebook, p.state, p.last_wake_at,
                    s.character_name, s.turns,
                    EXTRACT(EPOCH FROM (now() - p.last_wake_at)) AS since_wake
             FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             WHERE p.controller = 'llm' AND s.ship_destroyed = FALSE AND (p.state ->> 'retired') IS NULL
             ORDER BY p.last_wake_at NULLS FIRST, p.ship_id"
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['state'] = json_decode((string)$r['state'], true) ?: [];
            $r['persona'] = json_decode((string)$r['persona'], true) ?: [];
        }
        return $rows;
    }

    /** @return array{global: float, per_npc: array<int,float>} */
    public function spendToday(): array
    {
        $per = [];
        $global = 0.0;
        foreach ($this->pdo->query(
            "SELECT ship_id, COALESCE(SUM(cost_usd), 0) AS spent FROM npc_action_log
             WHERE created_at >= date_trunc('day', now()) GROUP BY ship_id"
        )->fetchAll() as $row) {
            $per[(int)$row['ship_id']] = (float)$row['spent'];
            $global += (float)$row['spent'];
        }
        return ['global' => $global, 'per_npc' => $per];
    }

    /** @return array{admin: bool, other: bool} */
    public function pendingEventKinds(int $shipId): array
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FILTER (WHERE kind = 'admin_wake') AS admin, COUNT(*) FILTER (WHERE kind <> 'admin_wake') AS other
             FROM npc_events WHERE ship_id = :id AND consumed_at IS NULL"
        );
        $st->execute(['id' => $shipId]);
        $row = $st->fetch();
        return ['admin' => (int)$row['admin'] > 0, 'other' => (int)$row['other'] > 0];
    }

    public function completeWake(int $shipId, int $lastEventId): void
    {
        $this->pdo->prepare('UPDATE npc_profiles SET last_wake_at = now() WHERE ship_id = :id')->execute(['id' => $shipId]);
        if ($lastEventId > 0) {
            $this->pdo->prepare('UPDATE npc_events SET consumed_at = now() WHERE ship_id = :id AND consumed_at IS NULL AND id <= :e')
                ->execute(['id' => $shipId, 'e' => $lastEventId]);
        }
        // An admin wake with no other events is consumed here too.
        $this->pdo->prepare("UPDATE npc_events SET consumed_at = now() WHERE ship_id = :id AND consumed_at IS NULL AND kind = 'admin_wake'")
            ->execute(['id' => $shipId]);
    }

    public function recordFailure(int $shipId): void
    {
        $this->patchLlmState($shipId, "jsonb_build_object('failures', COALESCE((state -> 'llm' ->> 'failures')::int, 0) + 1, 'last_failure_at', to_jsonb(now()::text))");
    }

    public function resetFailures(int $shipId): void
    {
        $this->patchLlmState($shipId, "jsonb_build_object('failures', 0)");
    }

    private function patchLlmState(int $shipId, string $patchExpr): void
    {
        $this->pdo->prepare(
            "UPDATE npc_profiles SET state = jsonb_set(state, '{llm}', COALESCE(state -> 'llm', '{}'::jsonb) || ($patchExpr), TRUE)
             WHERE ship_id = :id"
        )->execute(['id' => $shipId]);
    }

    /** Raise an admin alert (deduplicated per kind and ship for an hour). */
    public function alert(string $kind, ?int $shipId, array $detail = []): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO npc_alerts (kind, ship_id, detail)
             SELECT :k, :s, CAST(:d AS JSONB)
             WHERE NOT EXISTS (SELECT 1 FROM npc_alerts WHERE kind = :k2 AND ship_id IS NOT DISTINCT FROM :s2
                               AND created_at > now() - interval '1 hour')"
        );
        $st->execute(['k' => $kind, 's' => $shipId, 'd' => json_encode($detail === [] ? new \stdClass() : $detail), 'k2' => $kind, 's2' => $shipId]);
    }

    /** Append one audit row. */
    public function log(int $shipId, string $wakeId, int $step, ?string $tool, mixed $arguments, mixed $result,
        ?string $model = null, ?int $in = null, ?int $out = null, ?float $cost = null): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO npc_action_log (ship_id, wake_id, step, model, tool, arguments, result, input_tokens, output_tokens, cost_usd)
             VALUES (:ship, CAST(:wake AS UUID), :step, :model, :tool, CAST(:args AS JSONB), CAST(:result AS JSONB), :in, :out, :cost)'
        );
        $st->execute([
            'ship' => $shipId, 'wake' => $wakeId, 'step' => $step, 'model' => $model, 'tool' => $tool,
            'args' => $arguments === null ? null : json_encode($arguments, JSON_UNESCAPED_UNICODE),
            'result' => $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
            'in' => $in, 'out' => $out, 'cost' => $cost,
        ]);
    }
}
