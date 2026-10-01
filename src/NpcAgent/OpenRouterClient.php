<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/**
 * OpenRouter chat completions (OpenAI-compatible) with tool calling.
 * The key comes from the environment and is never logged.
 */
class OpenRouterClient
{
    public function __construct(
        private string $url,
        private string $apiKey,
        private array $pricePerMillion = ['input' => 3.0, 'output' => 15.0],
        private int $timeout = 60
    ) {}

    /**
     * @param string[] $fallbackModels tried by OpenRouter in order if the primary is unavailable
     * @return array{ok: bool, message?: array, model?: string, input_tokens?: int, output_tokens?: int, cost_usd?: float, error?: string}
     */
    public function chat(string $model, array $fallbackModels, array $messages, array $tools): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'usage' => ['include' => true],
            'temperature' => 0.7,
        ];
        if ($fallbackModels) {
            $payload['models'] = array_values(array_unique(array_merge([$model], $fallbackModels)));
            $payload['route'] = 'fallback';
        }
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'X-Title: BlackNova Traders NPC worker',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            return ['ok' => false, 'error' => "network: $err"];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $data = json_decode((string)$raw, true);
        if ($status !== 200 || !is_array($data) || empty($data['choices'][0]['message'])) {
            $msg = is_array($data) ? ($data['error']['message'] ?? 'unexpected response') : 'invalid JSON';
            return ['ok' => false, 'error' => "HTTP $status: " . mb_substr((string)$msg, 0, 300)];
        }
        $usage = $data['usage'] ?? [];
        $in = (int)($usage['prompt_tokens'] ?? 0);
        $out = (int)($usage['completion_tokens'] ?? 0);
        // Prefer the cost OpenRouter reports; otherwise estimate conservatively from configured prices.
        $cost = isset($usage['cost']) ? (float)$usage['cost']
            : ($in * $this->pricePerMillion['input'] + $out * $this->pricePerMillion['output']) / 1_000_000;
        return [
            'ok' => true,
            'message' => $data['choices'][0]['message'],
            'model' => (string)($data['model'] ?? $model),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cost_usd' => round($cost, 6),
        ];
    }
}
