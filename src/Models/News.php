<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class News
{
    public function __construct(private Database $db) {}

    public function recent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        return $this->db->fetchAll("SELECT headline, newstext, date, news_type FROM news ORDER BY date DESC, news_id DESC LIMIT $limit");
    }

    public function publishCombatEvents(): string
    {
        $pdo = $this->db->getConnection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            // A persistent marker avoids replay after news retention removes old items.
            // No time window: events during quiet periods are still published.
            $events = $this->db->fetchAll(
                "SELECT * FROM attack_logs WHERE news_published = FALSE
                 AND result = 'destroyed' AND attack_type IN ('ship', 'planet')
                 ORDER BY log_id LIMIT 500 FOR UPDATE SKIP LOCKED"
            );
            $published = 0;
            foreach ($events as $event) {
                $captured = $event['attack_type'] === 'planet';
                $headline = $captured ? 'Planet captured' : 'Ship destroyed';
                $target = $event['defender_name'] ?: ($captured ? 'an unnamed planet' : 'an unnamed trader');
                $text = $captured
                    ? $event['attacker_name'] . ' captured ' . $target . '.'
                    : $event['attacker_name'] . ' destroyed the ship of ' . $target . '.';
                // Publish names and outcomes, never private combat/resource details or locations.
                $insert = $this->db->query(
                    'INSERT INTO news (headline, newstext, user_id, date, news_type, source_attack_log_id)
                     VALUES (:headline, :text, NULL, :date, :type, :source)
                     ON CONFLICT (source_attack_log_id) DO NOTHING',
                    ['headline' => $headline, 'text' => $text, 'date' => $event['timestamp'],
                     'type' => $captured ? 'planet_capture' : 'ship_destroyed', 'source' => (int)$event['log_id']]
                );
                $published += $insert->rowCount();
                $this->db->execute('UPDATE attack_logs SET news_published = TRUE WHERE log_id = :id', ['id' => (int)$event['log_id']]);
            }
            $this->db->execute('DELETE FROM news WHERE news_id IN (
                SELECT news_id FROM news ORDER BY date DESC, news_id DESC OFFSET 100
            )');
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return "Published $published news items";
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
