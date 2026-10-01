<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/**
 * The LLM NPC worker loop. It talks to the game only through the public API
 * (as each NPC) and to OpenRouter for decisions; slow or failed model calls can
 * never affect players because the game server never calls an LLM.
 */
class Worker
{
    private bool $stop = false;
    private int $lastHeartbeat = 0;
    private array $tokens = [];
    private int $tokensMtime = 0;

    /** @param callable(string):void $log */
    public function __construct(
        private Store $store,
        private ApiClient $api,
        private OpenRouterClient $llm,
        private Tools $tools,
        private Prompts $prompts,
        private array $config,
        private string $tokensFile,
        private $log
    ) {}

    public function stop(): void
    {
        $this->stop = true;
    }

    /** Run until stopped. @param int|null $maxLoops stop after N loop passes (tests, cron mode) */
    public function run(?int $maxLoops = null, int $pollSeconds = 5): void
    {
        $loops = 0;
        while (!$this->stop) {
            $woke = $this->loopOnce();
            $loops++;
            if ($maxLoops !== null && $loops >= $maxLoops) {
                break;
            }
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            // Stagger: never two wakes in the same second; idle polling otherwise.
            sleep($woke ? 1 : $pollSeconds);
        }
    }

    /** One pass: heartbeat, then wake at most one NPC. @return bool whether an NPC was woken */
    public function loopOnce(): bool
    {
        $this->beat();
        if (!$this->store->setting('llm_enabled', false)) {
            return false; // kill switch: no wakes; NPCs run on scripted behaviour
        }
        $spend = $this->store->spendToday();
        $globalCap = (float)$this->store->setting('global_daily_budget_usd', 10.0);
        if ($spend['global'] >= $globalCap) {
            return false;
        }
        foreach ($this->store->llmNpcs() as $npc) {
            if ($this->stop || !$this->store->setting('llm_enabled', false)) {
                return false;
            }
            $reason = $this->wakeReason($npc, $spend);
            if ($reason === null) {
                continue;
            }
            $this->wake($npc, $reason);
            return true;
        }
        return false;
    }

    /** Why this NPC should wake now, or null. */
    public function wakeReason(array $npc, array $spend): ?string
    {
        $shipId = (int)$npc['ship_id'];
        $state = $npc['state'];
        $threshold = (int)$this->store->setting('llm_failure_threshold', 3);
        if ((int)($state['llm']['failures'] ?? 0) >= $threshold) {
            // Half-open: probe again after a cool-down so a recovered provider is picked up.
            $last = isset($state['llm']['last_failure_at']) ? strtotime((string)$state['llm']['last_failure_at']) : 0;
            if (time() - $last < 1800) {
                return null;
            }
        }
        if (($spend['per_npc'][$shipId] ?? 0.0) >= (float)$this->store->setting('daily_budget_usd', 1.0)) {
            return null;
        }
        if ((int)$npc['turns'] < 1) {
            return null;
        }
        if ($this->token($shipId) === null) {
            return null;
        }
        $events = $this->store->pendingEventKinds($shipId);
        if ($events['admin']) {
            return 'admin';
        }
        $since = $npc['since_wake'] === null ? PHP_INT_MAX : (float)$npc['since_wake'];
        $debounce = (int)$this->store->setting('event_debounce_min', 5) * 60;
        if ($events['other'] && $since >= $debounce) {
            return 'event';
        }
        $interval = (int)$this->store->setting('wake_interval_min', 10) * 60;
        if ($since >= $interval && (int)$npc['turns'] >= (int)$this->store->setting('min_turns_to_wake', 10)) {
            return 'interval';
        }
        return null;
    }

    /** Execute one wake for one NPC. */
    public function wake(array $npc, string $reason): void
    {
        $shipId = (int)$npc['ship_id'];
        $wakeId = self::uuid();
        $token = $this->token($shipId);
        $this->say("wake ship=$shipId reason=$reason wake=$wakeId");

        $obs = $this->api->call('GET', 'agent/observation', $token);
        if (empty($obs['body']['success'])) {
            $this->store->log($shipId, $wakeId, 0, '_error', null, ['error' => 'observation failed', 'status' => $obs['status'],
                'detail' => $obs['body']['error']['message'] ?? null]);
            if (in_array($obs['status'], [401, 403], true)) {
                $this->store->alert('npc_token_rejected', $shipId, ['status' => $obs['status']]);   // expired, rotated or wrong source IP
            }
            $this->store->completeWake($shipId, 0);   // back off until the next interval instead of hammering the API
            return;
        }
        $observation = (string)$obs['body']['data']['observation'];
        $lastEvent = (int)($obs['body']['data']['last_event_id'] ?? 0);
        $this->store->log($shipId, $wakeId, 0, '_observation', null, ['text' => $observation]);

        $faction = $this->config['factions'][$npc['faction']] ?? ['label' => $npc['faction'], 'archetype' => ''];
        $messages = [
            ['role' => 'system', 'content' => $this->prompts->system($npc, (string)$faction['label'], (string)$faction['archetype'])],
            ['role' => 'user', 'content' => $observation],
        ];
        $model = $npc['model'] ?: (string)$this->store->setting('default_model', '');
        $fallbacks = $npc['persona']['fallback_models'] ?? ($this->config['default_fallback_models'] ?? []);
        if ($model === '') {
            $this->store->log($shipId, $wakeId, 0, '_error', null, ['error' => 'no model configured (npc.default_model)']);
            $this->store->alert('npc_no_model', $shipId, ['hint' => 'set npc.default_model or the NPC\'s model']);
            $this->store->completeWake($shipId, 0);
            return;
        }

        $maxSteps = (int)$this->store->setting('max_steps', 8);
        $wakeCap = (float)$this->store->setting('wake_budget_usd', 0.25);
        $npcCap = (float)$this->store->setting('daily_budget_usd', 1.0);
        $globalCap = (float)$this->store->setting('global_daily_budget_usd', 10.0);
        $startSpend = $this->store->spendToday();
        $wakeCost = 0.0;
        $ended = false;
        $failed = false;
        $toolDefs = $this->tools->definitions();

        for ($step = 1; $step <= $maxSteps && !$ended && !$this->stop; $step++) {
            if (!$this->store->setting('llm_enabled', false)) {
                break;
            }
            if ($wakeCost >= $wakeCap
                || ($startSpend['per_npc'][$shipId] ?? 0.0) + $wakeCost >= $npcCap
                || $startSpend['global'] + $wakeCost >= $globalCap) {
                $this->store->log($shipId, $wakeId, $step, '_budget', null, ['wake_cost' => $wakeCost]);
                break;
            }
            $this->beat();
            $r = $this->llm->chat($model, $fallbacks, $messages, $toolDefs);
            if (!$r['ok']) {
                $failed = true;
                $this->store->log($shipId, $wakeId, $step, '_error', null, ['error' => $r['error']]);
                $this->say("openrouter error ship=$shipId: " . $r['error']);
                break;
            }
            $wakeCost += $r['cost_usd'];
            $assistant = $r['message'];
            $this->store->log($shipId, $wakeId, $step, null, null,
                ['content' => isset($assistant['content']) ? mb_substr((string)$assistant['content'], 0, 1000) : null],
                $r['model'], $r['input_tokens'], $r['output_tokens'], $r['cost_usd']);

            $calls = $assistant['tool_calls'] ?? [];
            if (!$calls) {
                break; // the model is done
            }
            $messages[] = ['role' => 'assistant', 'content' => $assistant['content'] ?? null, 'tool_calls' => $calls];
            foreach ($calls as $call) {
                $name = (string)($call['function']['name'] ?? '');
                $rawArgs = (string)($call['function']['arguments'] ?? '{}');
                $args = $rawArgs === '' ? [] : json_decode($rawArgs, true);
                if ($args === null && trim($rawArgs) !== 'null') {
                    $exec = ['result' => ['ok' => false, 'error' => 'Arguments were not valid JSON'], 'terminal' => false, 'valid' => false, 'status' => null];
                } else {
                    $exec = $this->tools->execute($name, $args ?? [], $token);
                }
                $this->store->log($shipId, $wakeId, $step, $name !== '' ? $name : '_unknown', is_array($args) ? $args : ['raw' => mb_substr($rawArgs, 0, 500)], $exec['result']);
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string)($call['id'] ?? ''), 'content' => Tools::encodeForModel($exec['result'])];
                if ($exec['terminal']) {
                    $ended = true;
                }
            }
        }

        if ($failed) {
            $this->store->recordFailure($shipId);
        } else {
            $this->store->resetFailures($shipId);
        }
        $this->store->completeWake($shipId, $lastEvent);
    }

    // ---------------------------------------------------------------- helpers

    private function beat(bool $force = false): void
    {
        if ($force || time() - $this->lastHeartbeat >= 60) {
            $this->store->heartbeat();
            $this->lastHeartbeat = time();
        }
    }

    private function token(int $shipId): ?string
    {
        $mtime = is_file($this->tokensFile) ? (int)filemtime($this->tokensFile) : 0;
        if ($mtime !== $this->tokensMtime) {
            $this->tokens = $mtime ? (json_decode((string)file_get_contents($this->tokensFile), true) ?: []) : [];
            $this->tokensMtime = $mtime;
        }
        $t = $this->tokens[(string)$shipId] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }

    private function say(string $line): void
    {
        ($this->log)(date('c') . ' ' . $line);
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
