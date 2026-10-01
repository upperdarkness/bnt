<?php

declare(strict_types=1);

namespace BNT\NpcAgent;

/**
 * HTTP client for the public BNT API, authenticated as one NPC.
 * This is the worker's only path into game state: every rule check applies.
 */
class ApiClient
{
    public function __construct(private string $baseUrl, private int $timeout = 20) {}

    /**
     * @return array{status: int, body: array, retry_after: ?int}
     */
    public function request(string $method, string $path, string $token, ?array $json = null, array $query = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => true,
        ]);
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            return ['status' => 0, 'body' => ['success' => false, 'error' => ['message' => "Game API unreachable: $err", 'code' => 'API_UNREACHABLE']], 'retry_after' => null];
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $head = substr((string)$raw, 0, $headerSize);
        $body = json_decode(substr((string)$raw, $headerSize), true);
        $retry = preg_match('/^Retry-After:\s*(\d+)/mi', $head, $m) ? (int)$m[1] : null;
        return [
            'status' => $status,
            'body' => is_array($body) ? $body : ['success' => false, 'error' => ['message' => "Unexpected response (HTTP $status)", 'code' => 'BAD_RESPONSE']],
            'retry_after' => $retry,
        ];
    }

    /** Request with one polite retry on HTTP 429. */
    public function call(string $method, string $path, string $token, ?array $json = null, array $query = []): array
    {
        $r = $this->request($method, $path, $token, $json, $query);
        if ($r['status'] === 429 && ($r['retry_after'] ?? 99) <= 10) {
            sleep(max(1, (int)$r['retry_after']));
            $r = $this->request($method, $path, $token, $json, $query);
        }
        return $r;
    }
}
