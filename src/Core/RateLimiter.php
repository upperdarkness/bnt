<?php

declare(strict_types=1);

namespace BNT\Core;

/**
 * Token-bucket rate limiter stored in api_rate_buckets (one row per API token).
 * The refill-and-take step is a single atomic upsert, so concurrent requests
 * cannot overspend a bucket.
 */
class RateLimiter
{
    public function __construct(private Database $db) {}

    /**
     * Take one token. $perMinute is both the bucket size and the refill rate per minute.
     *
     * @return array{allowed: bool, retry_after: int, remaining: int, limit: int}
     */
    public function consume(string $key, int $perMinute): array
    {
        $perMinute = max(1, $perMinute);
        $ratePerSecond = $perMinute / 60.0;
        $row = $this->db->fetchOne(
            'INSERT INTO api_rate_buckets (bucket_key, tokens, updated_at)
             VALUES (:k, CAST(:cap AS DOUBLE PRECISION) - 1, clock_timestamp())
             ON CONFLICT (bucket_key) DO UPDATE SET
                tokens = LEAST(CAST(:cap2 AS DOUBLE PRECISION),
                               api_rate_buckets.tokens + EXTRACT(EPOCH FROM (clock_timestamp() - api_rate_buckets.updated_at)) * CAST(:rate AS DOUBLE PRECISION)) - 1,
                updated_at = clock_timestamp()
             WHERE LEAST(CAST(:cap3 AS DOUBLE PRECISION),
                         api_rate_buckets.tokens + EXTRACT(EPOCH FROM (clock_timestamp() - api_rate_buckets.updated_at)) * CAST(:rate2 AS DOUBLE PRECISION)) >= 1
             RETURNING tokens',
            ['k' => $key, 'cap' => $perMinute, 'cap2' => $perMinute, 'cap3' => $perMinute,
             'rate' => $ratePerSecond, 'rate2' => $ratePerSecond]
        );
        if ($row) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining' => (int)floor((float)$row['tokens']), 'limit' => $perMinute];
        }
        $bucket = $this->db->fetchOne(
            'SELECT tokens + EXTRACT(EPOCH FROM (clock_timestamp() - updated_at)) * CAST(:rate AS DOUBLE PRECISION) AS tokens
             FROM api_rate_buckets WHERE bucket_key = :k',
            ['k' => $key, 'rate' => $ratePerSecond]
        );
        $have = (float)($bucket['tokens'] ?? 0.0);
        $retry = (int)max(1, ceil((1.0 - $have) / $ratePerSecond));
        return ['allowed' => false, 'retry_after' => $retry, 'remaining' => 0, 'limit' => $perMinute];
    }
}
