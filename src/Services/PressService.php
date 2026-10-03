<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * The press NPC (default "Talia Venn, Galactic Courier"): docked permanently at the Sector 1 starbase,
 * so it can never be attacked, with no combat or trade behaviour. It bylines journalist stories and
 * sends system notices (protection warnings, interview questions) as in-game messages.
 */
class PressService
{
    private ?int $cached = null;

    public function __construct(private Database $db, private NpcService $npcs, private array $config) {}

    public function reporterName(): string
    {
        return (string)($this->config['news']['reporter_name'] ?? 'Talia Venn');
    }

    public function reporterTitle(): string
    {
        return (string)($this->config['news']['reporter_title'] ?? 'Galactic Courier');
    }

    /** Ship id of the reporter, creating it at Sector 1 on first use. Null if the universe has no Sector 1. */
    public function reporterId(bool $create = true): ?int
    {
        if ($this->cached !== null) {
            $row = $this->db->fetchOne('SELECT ship_id FROM ships WHERE ship_id = :id AND ship_destroyed = FALSE', ['id' => $this->cached]);
            if ($row) {
                return $this->cached;
            }
            $this->cached = null;
        }
        $row = $this->db->fetchOne(
            "SELECT p.ship_id FROM npc_profiles p JOIN ships s ON s.ship_id = p.ship_id
             WHERE p.faction = 'press' AND s.ship_destroyed = FALSE ORDER BY p.ship_id LIMIT 1"
        );
        if ($row) {
            return $this->cached = (int)$row['ship_id'];
        }
        if (!$create || !$this->db->fetchOne('SELECT 1 AS x FROM universe WHERE sector_id = 1')) {
            return null;
        }
        $npc = $this->npcs->spawn('press', ['name' => $this->reporterName(), 'sector' => 1]);
        return $this->cached = (int)$npc['ship_id'];
    }

    /** A system notice from the Courier. Silently skipped when there is no reporter. */
    public function notify(int $toShipId, string $subject, string $body): void
    {
        $from = $this->reporterId();
        if ($from === null || $from === $toShipId) {
            return;
        }
        $this->db->execute(
            'INSERT INTO messages (from_id, to_id, subject, message, sent_at, read) VALUES (:f, :t, :s, :m, NOW(), FALSE)',
            ['f' => $from, 't' => $toShipId, 's' => mb_substr($subject, 0, 100), 'm' => $body]
        );
    }
}
