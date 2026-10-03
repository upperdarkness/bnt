<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/**
 * The shared LLM content pipeline for journalist stories and rumour flavour lines.
 *
 *   game picks the subject and facts -> LLM writes prose around {{slots}} -> the game server validates
 *   (and substitutes the real facts) -> publish, or one retry, or the template fallback.
 *
 * The worker never sees a raw player string (names and quotes arrive inside <<< >>>) and never writes a fact:
 * validation and slot substitution happen in the game server, which is the only thing that can publish.
 * Runs under the same budgets and audit log as LLM NPCs (rows are logged under the press NPC).
 */
class ContentWorker
{
    private array $lastRun = [];

    /** @param callable(string):void $log */
    public function __construct(
        private Store $store,
        private ApiClient $api,
        private OpenRouterClient $llm,
        private array $config,
        private string $tokensFile,
        private string $promptDir,
        private $log
    ) {}

    /** Run whatever is due. @return array{stories: int, fallbacks: int, lines: int} */
    public function tick(): array
    {
        $out = ['stories' => 0, 'fallbacks' => 0, 'lines' => 0];
        if (!$this->store->setting('llm_enabled', false)) {
            return $out;
        }
        $pressId = $this->store->pressShipId();
        $token = $pressId ? $this->token($pressId) : null;
        if ($pressId === null || $token === null) {
            return $out;
        }
        if ($this->store->feature('news.journalist_enabled', (bool)($this->config['news']['journalist_enabled'] ?? false)) && $this->due('news', 300)) {
            $r = $this->journalist($pressId, $token);
            $out['stories'] += $r['stories'];
            $out['fallbacks'] += $r['fallbacks'];
        }
        if ($this->store->feature('rumours.enabled', (bool)($this->config['rumours']['enabled'] ?? false)) && $this->due('rumours', 600)) {
            $out['lines'] += $this->rumourLines($pressId, $token);
        }
        return $out;
    }

    private function due(string $job, int $everySeconds): bool
    {
        if (time() - ($this->lastRun[$job] ?? 0) < $everySeconds) {
            return false;
        }
        $this->lastRun[$job] = time();
        return true;
    }

    /** Is there budget left for this press NPC and globally? */
    private function budgetOk(int $pressId): bool
    {
        $spend = $this->store->spendToday();
        return ($spend['per_npc'][$pressId] ?? 0.0) < (float)$this->store->setting('daily_budget_usd', 1.0)
            && $spend['global'] < (float)$this->store->setting('global_daily_budget_usd', 10.0);
    }

    private function model(string $section): string
    {
        $m = (string)($this->config[$section]['model'] ?? '');
        return $m !== '' ? $m : (string)$this->store->setting('default_model', '');
    }

    // ------------------------------------------------------------------ journalist

    /** @return array{stories: int, fallbacks: int} */
    private function journalist(int $pressId, string $token): array
    {
        $out = ['stories' => 0, 'fallbacks' => 0];
        $r = $this->api->call('GET', 'agent/news/candidates', $token);
        if (empty($r['body']['success'])) {
            return $out;
        }
        $model = $this->model('news');
        if ($model === '') {
            return $out;
        }
        $system = $this->prompt('journalist.md');
        $version = (string)($r['body']['data']['style_guide_version'] ?? 'v1');
        foreach (array_slice($r['body']['data']['candidates'] ?? [], 0, 2) as $cand) {
            if (!$this->budgetOk($pressId)) {
                break;
            }
            $wake = Worker::uuid();
            $this->store->log($pressId, $wake, 0, '_fact_sheet', ['prompt_version' => $version], $cand);
            $feedback = null;
            $accepted = false;
            for ($attempt = 1; $attempt <= 2 && !$accepted; $attempt++) {
                $user = json_encode(['fact_sheet' => $cand, 'note' => $feedback ? "Your previous attempt was rejected: $feedback. Fix exactly that." : null], JSON_UNESCAPED_UNICODE);
                $reply = $this->llm->chat($model, [], [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => (string)$user]]);
                if (!$reply['ok']) {
                    $this->store->log($pressId, $wake, $attempt, '_error', null, ['error' => $reply['error']]);
                    break;
                }
                $this->store->log($pressId, $wake, $attempt, null, null, ['raw_output' => mb_substr((string)($reply['message']['content'] ?? ''), 0, 4000)],
                    $reply['model'], $reply['input_tokens'], $reply['output_tokens'], $reply['cost_usd']);
                $parsed = $this->json((string)($reply['message']['content'] ?? ''));
                if (!$parsed || !isset($parsed['headline'], $parsed['body'])) {
                    $feedback = 'the output was not JSON with headline and body';
                    $this->store->log($pressId, $wake, $attempt, 'news_story', ['candidate_id' => $cand['id'], 'prompt_version' => $version], ['validator' => ['accepted' => false, 'errors' => [$feedback]]]);
                    continue;
                }
                $sub = $this->api->call('POST', 'agent/news/stories', $token, [
                    'candidate_id' => $cand['id'], 'headline' => (string)$parsed['headline'], 'body' => (string)$parsed['body'],
                ]);
                $result = ['accepted' => !empty($sub['body']['success']), 'errors' => $sub['body']['error']['details']['errors'] ?? [], 'response' => $sub['body']['data'] ?? null];
                $this->store->log($pressId, $wake, $attempt, 'news_story',
                    ['candidate_id' => $cand['id'], 'prompt_version' => $version, 'headline' => (string)$parsed['headline'], 'body' => (string)$parsed['body']],
                    ['validator' => $result]);
                if ($result['accepted']) {
                    $accepted = true;
                    $out['stories']++;
                } else {
                    $feedback = implode('; ', array_slice($result['errors'], 0, 4)) ?: (string)($sub['body']['error']['message'] ?? 'rejected');
                    if (($sub['body']['error']['code'] ?? '') === 'QUOTA' || ($sub['body']['error']['code'] ?? '') === 'NOT_FOUND') {
                        break;
                    }
                }
            }
            if (!$accepted) {
                // One retry failed too: the existing template news item stands, so players still see something accurate.
                $fb = $this->api->call('POST', 'agent/news/stories', $token, ['candidate_id' => $cand['id'], 'fallback' => true]);
                $this->store->log($pressId, $wake, 9, 'news_fallback', ['candidate_id' => $cand['id']], ['ok' => !empty($fb['body']['success'])]);
                $out['fallbacks']++;
            }
            ($this->log)(date('c') . " journalist candidate={$cand['id']} accepted=" . ($accepted ? 'yes' : 'fallback'));
        }
        return $out;
    }

    // ------------------------------------------------------------------ rumour lines

    private function rumourLines(int $pressId, string $token): int
    {
        $r = $this->api->call('GET', 'agent/rumours/pool-status', $token);
        if (empty($r['body']['success'])) {
            return 0;
        }
        $need = array_filter($r['body']['data']['pool'] ?? [], static fn($p) => (int)$p['needed'] > 0);
        $model = $this->model('rumours');
        if (!$need || $model === '' || !$this->budgetOk($pressId)) {
            return 0;
        }
        $batch = (int)($r['body']['data']['batch_size'] ?? 20);
        $request = [];
        foreach ($need as $type => $info) {
            $request[] = ['type' => $type, 'lines_wanted' => min((int)$info['needed'], max(1, intdiv($batch, max(1, count($need))))), 'slots_to_use' => $info['slots']];
        }
        $wake = Worker::uuid();
        $reply = $this->llm->chat($model, [], [
            ['role' => 'system', 'content' => $this->prompt('rumours.md')],
            ['role' => 'user', 'content' => json_encode(['request' => $request, 'max_words' => $r['body']['data']['max_words'] ?? 40])],
        ]);
        if (!$reply['ok']) {
            $this->store->log($pressId, $wake, 1, '_error', null, ['error' => $reply['error']]);
            return 0;
        }
        $this->store->log($pressId, $wake, 1, null, ['request' => $request, 'prompt_version' => (string)($this->config['news']['prompt_version'] ?? 'v1')],
            ['raw_output' => mb_substr((string)($reply['message']['content'] ?? ''), 0, 6000)], $reply['model'], $reply['input_tokens'], $reply['output_tokens'], $reply['cost_usd']);
        $parsed = $this->json((string)($reply['message']['content'] ?? ''));
        if (!$parsed || !is_array($parsed['lines'] ?? null)) {
            return 0;
        }
        $sub = $this->api->call('POST', 'agent/rumours/lines', $token, ['lines' => array_slice($parsed['lines'], 0, $batch * 2)]);
        $this->store->log($pressId, $wake, 2, 'rumour_lines', ['count' => count($parsed['lines'])], ['validator' => $sub['body']['data'] ?? $sub['body']]);
        return (int)($sub['body']['data']['accepted'] ?? 0);
    }

    // ------------------------------------------------------------------ helpers

    private function prompt(string $file): string
    {
        return trim((string)file_get_contents(rtrim($this->promptDir, '/') . '/' . $file));
    }

    /** Extract the first JSON object from model output (models sometimes wrap it in fences). */
    private function json(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $d = json_decode($m[0], true);
            return is_array($d) ? $d : null;
        }
        return null;
    }

    private function token(int $shipId): ?string
    {
        if (!is_file($this->tokensFile)) {
            return null;
        }
        $t = (json_decode((string)file_get_contents($this->tokensFile), true) ?: [])[(string)$shipId] ?? null;
        return is_string($t) && $t !== '' ? $t : null;
    }
}
