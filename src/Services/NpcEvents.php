<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/** Queue of things an LLM-controlled NPC should hear about on its next wake. */
class NpcEvents
{
    public function __construct(private Database $db) {}

    /** Queue an event if the ship is an NPC; silently ignores human ships. */
    public function queue(int $shipId, string $kind, array $payload): void
    {
        $this->db->execute(
            'INSERT INTO npc_events (ship_id, kind, payload)
             SELECT :ship, :kind, CAST(:payload AS JSONB)
             WHERE EXISTS (SELECT 1 FROM npc_profiles WHERE ship_id = :ship2)',
            ['ship' => $shipId, 'kind' => $kind, 'payload' => json_encode($payload), 'ship2' => $shipId]
        );
    }

    /** Pending events, oldest first. */
    public function pending(int $shipId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT id, kind, payload, created_at, EXTRACT(EPOCH FROM (now() - created_at)) AS age_seconds
             FROM npc_events WHERE ship_id = :ship AND consumed_at IS NULL
             ORDER BY id LIMIT ' . (int)$limit,
            ['ship' => $shipId]
        );
    }

    public function markConsumed(int $shipId, int $upToId): void
    {
        $this->db->execute(
            'UPDATE npc_events SET consumed_at = now() WHERE ship_id = :ship AND consumed_at IS NULL AND id <= :id',
            ['ship' => $shipId, 'id' => $upToId]
        );
    }
}
