<?php

declare(strict_types=1);

namespace BNT\Services;

use BNT\Core\Database;

/**
 * Ship-to-ship messages for the REST API. Writes the existing `messages` table.
 * NPC senders get extra limits: 280 chars, 5 per hour, and only to ships in
 * sight or that messaged the NPC in the last 24 hours.
 */
class MessagingService
{
    public function __construct(
        private Database $db,
        private TextFilter $filter,
        private NpcEvents $events,
        private array $config
    ) {}

    /** @return array{success: bool, error?: string, code?: string, message_id?: int} */
    public function send(array $sender, int $recipientId, string $body, ?string $subject = null): array
    {
        $isNpc = !empty($sender['is_npc']);
        $body = trim($body);
        $max = $isNpc ? (int)$this->config['npc']['message_max_chars'] : 5000;
        if ($body === '') {
            return $this->fail('Message body is required', 'EMPTY_MESSAGE');
        }
        if (mb_strlen($body) > $max) {
            return $this->fail("Message is too long (max $max characters)", 'MESSAGE_TOO_LONG');
        }
        $subject = trim((string)($subject ?? ''));
        if ($subject === '') {
            $subject = $isNpc ? 'Transmission' : '(no subject)';
        }
        if (mb_strlen($subject) > 100) {
            return $this->fail('Subject is too long (max 100 characters)', 'SUBJECT_TOO_LONG');
        }
        if ($recipientId === (int)$sender['ship_id']) {
            return $this->fail('You cannot send a message to yourself', 'INVALID_RECIPIENT');
        }
        $recipient = $this->db->fetchOne('SELECT ship_id, sector, ship_destroyed FROM ships WHERE ship_id = :id', ['id' => $recipientId]);
        if (!$recipient || $recipient['ship_destroyed']) {
            return $this->fail('Recipient not found', 'RECIPIENT_NOT_FOUND');
        }
        if ($this->filter->hasProfanity($body) || $this->filter->hasProfanity($subject)) {
            return $this->fail('Message rejected by the profanity filter', 'PROFANITY');
        }

        $limit = $isNpc ? (int)$this->config['npc']['message_limit_per_hour'] : (int)$this->config['api']['player_message_limit_per_hour'];
        $sent = (int)$this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM messages WHERE from_id = :id AND sent_at > now() - interval '1 hour'",
            ['id' => (int)$sender['ship_id']]
        )['c'];
        if ($sent >= $limit) {
            return $this->fail("Message limit reached ($limit per hour)", 'MESSAGE_RATE_LIMITED');
        }

        if ($isNpc) {
            if ($this->filter->leaksPrompt($body)) {
                return $this->fail('Message rejected', 'PROMPT_LEAK');
            }
            $inSight = (int)$recipient['sector'] === (int)$sender['sector'];
            $contacted = $this->db->fetchOne(
                "SELECT 1 AS x FROM messages WHERE from_id = :to AND to_id = :me AND sent_at > now() - interval '24 hours' LIMIT 1",
                ['to' => $recipientId, 'me' => (int)$sender['ship_id']]
            );
            if (!$inSight && !$contacted) {
                return $this->fail('You can only message ships in sight or that messaged you in the last 24 hours', 'NOT_IN_SIGHT');
            }
        }

        $row = $this->db->fetchOne(
            'INSERT INTO messages (from_id, to_id, subject, message, sent_at, read)
             VALUES (:from, :to, :subject, :body, NOW(), FALSE) RETURNING message_id',
            ['from' => (int)$sender['ship_id'], 'to' => $recipientId, 'subject' => $subject, 'body' => $body]
        );
        $this->events->queue($recipientId, 'message', [
            'from' => (int)$sender['ship_id'], 'from_name' => $sender['character_name'], 'text' => mb_substr($body, 0, 280),
        ]);
        return ['success' => true, 'message_id' => (int)$row['message_id']];
    }

    public function inbox(int $shipId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT m.message_id, m.from_id, s.character_name AS from_name, m.subject, m.message, m.sent_at, m.read
             FROM messages m JOIN ships s ON s.ship_id = m.from_id
             WHERE m.to_id = :id ORDER BY m.sent_at DESC, m.message_id DESC LIMIT ' . max(1, min(100, $limit)),
            ['id' => $shipId]
        );
    }

    public function markRead(int $shipId): void
    {
        $this->db->execute('UPDATE messages SET read = TRUE WHERE to_id = :id AND read = FALSE', ['id' => $shipId]);
    }

    private function fail(string $error, string $code): array
    {
        return ['success' => false, 'error' => $error, 'code' => $code];
    }
}
