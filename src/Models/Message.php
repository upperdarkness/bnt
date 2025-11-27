<?php

declare(strict_types=1);

namespace BNT\Models;

use BNT\Core\Database;

class Message
{
    public function __construct(private Database $db) {}

    /**
     * Find message by ID
     */
    public function find(int $id): ?array
    {
        $result = $this->db->fetchOne(
            'SELECT m.*,
                    s1.character_name as sender_name,
                    s2.character_name as recipient_name
             FROM messages m
             JOIN ships s1 ON m.sender_id = s1.ship_id
             JOIN ships s2 ON m.recipient_id = s2.ship_id
             WHERE m.message_id = :id',
            ['id' => $id]
        );

        return $result ?: null;
    }

    /**
     * Send a message
     */
    public function send(int $senderId, int $recipientId, string $subject, string $body): int
    {
        $this->db->execute(
            'INSERT INTO messages (sender_id, recipient_id, subject, body, sent_date, is_read)
             VALUES (:sender, :recipient, :subject, :body, NOW(), FALSE)',
            [
                'sender' => $senderId,
                'recipient' => $recipientId,
                'subject' => $subject,
                'body' => $body
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get inbox messages for a player
     */
    public function getInbox(int $playerId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, s.character_name as sender_name
             FROM messages m
             JOIN ships s ON m.sender_id = s.ship_id
             WHERE m.recipient_id = :player AND m.deleted_by_recipient = FALSE
             ORDER BY m.sent_date DESC
             LIMIT :limit OFFSET :offset',
            ['player' => $playerId, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Get sent messages for a player
     */
    public function getSent(int $playerId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT m.*, s.character_name as recipient_name
             FROM messages m
             JOIN ships s ON m.recipient_id = s.ship_id
             WHERE m.sender_id = :player AND m.deleted_by_sender = FALSE
             ORDER BY m.sent_date DESC
             LIMIT :limit OFFSET :offset',
            ['player' => $playerId, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /**
     * Get unread message count
     */
    public function getUnreadCount(int $playerId): int
    {
        $result = $this->db->fetchOne(
            'SELECT COUNT(*) as count FROM messages
             WHERE recipient_id = :player
             AND is_read = FALSE
             AND deleted_by_recipient = FALSE',
            ['player' => $playerId]
        );

        return (int)($result['count'] ?? 0);
    }

    /**
     * Mark message as read
     */
    public function markAsRead(int $messageId, int $playerId): bool
    {
        return $this->db->execute(
            'UPDATE messages
             SET is_read = TRUE
             WHERE message_id = :id AND recipient_id = :player',
            ['id' => $messageId, 'player' => $playerId]
        );
    }

    /**
     * Mark all messages as read
     */
    public function markAllAsRead(int $playerId): bool
    {
        return $this->db->execute(
            'UPDATE messages
             SET is_read = TRUE
             WHERE recipient_id = :player AND is_read = FALSE',
            ['player' => $playerId]
        );
    }

    /**
     * Delete message (soft delete)
     */
    public function delete(int $messageId, int $playerId, bool $isSender = false): bool
    {
        if ($isSender) {
            $result = $this->db->execute(
                'UPDATE messages
                 SET deleted_by_sender = TRUE
                 WHERE message_id = :id AND sender_id = :player',
                ['id' => $messageId, 'player' => $playerId]
            );
        } else {
            $result = $this->db->execute(
                'UPDATE messages
                 SET deleted_by_recipient = TRUE
                 WHERE message_id = :id AND recipient_id = :player',
                ['id' => $messageId, 'player' => $playerId]
            );
        }

        // Permanently delete if both parties have deleted
        $this->db->execute(
            'DELETE FROM messages
             WHERE deleted_by_sender = TRUE AND deleted_by_recipient = TRUE'
        );

        return $result;
    }

    /**
     * Delete all messages for a player (inbox or sent)
     */
    public function deleteAll(int $playerId, bool $isSent = false): bool
    {
        if ($isSent) {
            return $this->db->execute(
                'UPDATE messages SET deleted_by_sender = TRUE WHERE sender_id = :player',
                ['player' => $playerId]
            );
        } else {
            return $this->db->execute(
                'UPDATE messages SET deleted_by_recipient = TRUE WHERE recipient_id = :player',
                ['player' => $playerId]
            );
        }
    }

    /**
     * Check if player can view message
     */
    public function canView(int $messageId, int $playerId): bool
    {
        $message = $this->db->fetchOne(
            'SELECT sender_id, recipient_id, deleted_by_sender, deleted_by_recipient
             FROM messages
             WHERE message_id = :id',
            ['id' => $messageId]
        );

        if (!$message) {
            return false;
        }

        // Check if player is sender or recipient and hasn't deleted it
        if ($message['sender_id'] == $playerId && !$message['deleted_by_sender']) {
            return true;
        }

        if ($message['recipient_id'] == $playerId && !$message['deleted_by_recipient']) {
            return true;
        }

        return false;
    }

    /**
     * Get inbox statistics
     */
    public function getStatistics(int $playerId): array
    {
        $inbox = $this->db->fetchOne(
            'SELECT COUNT(*) as total,
                    SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END) as unread
             FROM messages
             WHERE recipient_id = :player AND deleted_by_recipient = FALSE',
            ['player' => $playerId]
        );

        $sent = $this->db->fetchOne(
            'SELECT COUNT(*) as total FROM messages
             WHERE sender_id = :player AND deleted_by_sender = FALSE',
            ['player' => $playerId]
        );

        return [
            'inbox_total' => (int)($inbox['total'] ?? 0),
            'inbox_unread' => (int)($inbox['unread'] ?? 0),
            'sent_total' => (int)($sent['total'] ?? 0),
        ];
    }
}
