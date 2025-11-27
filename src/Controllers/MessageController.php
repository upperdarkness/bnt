<?php

declare(strict_types=1);

namespace BNT\Controllers;

use BNT\Core\Session;
use BNT\Models\Ship;
use BNT\Models\Message;

class MessageController
{
    public function __construct(
        private Ship $shipModel,
        private Message $messageModel,
        private Session $session,
        private array $config
    ) {}

    private function requireAuth(): ?array
    {
        if (!$this->session->isLoggedIn()) {
            header('Location: /');
            exit;
        }

        $shipId = $this->session->getUserId();
        $ship = $this->shipModel->find($shipId);

        if (!$ship) {
            $this->session->logout();
            header('Location: /');
            exit;
        }

        return $ship;
    }

    /**
     * Show inbox
     */
    public function inbox(): void
    {
        $ship = $this->requireAuth();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $messages = $this->messageModel->getInbox((int)$ship['ship_id'], $perPage, $offset);
        $stats = $this->messageModel->getStatistics((int)$ship['ship_id']);

        $data = compact('ship', 'messages', 'stats', 'page', 'session');

        ob_start();
        include __DIR__ . '/../Views/messages_inbox.php';
        echo ob_get_clean();
    }

    /**
     * Show sent messages
     */
    public function sent(): void
    {
        $ship = $this->requireAuth();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;

        $messages = $this->messageModel->getSent((int)$ship['ship_id'], $perPage, $offset);
        $stats = $this->messageModel->getStatistics((int)$ship['ship_id']);

        $data = compact('ship', 'messages', 'stats', 'page', 'session');

        ob_start();
        include __DIR__ . '/../Views/messages_sent.php';
        echo ob_get_clean();
    }

    /**
     * Show compose form
     */
    public function compose(): void
    {
        $ship = $this->requireAuth();

        $replyTo = isset($_GET['reply']) ? (int)$_GET['reply'] : null;
        $recipientName = $_GET['to'] ?? '';

        $originalMessage = null;
        if ($replyTo) {
            $originalMessage = $this->messageModel->find($replyTo);
            if ($originalMessage && $originalMessage['recipient_id'] == $ship['ship_id']) {
                $recipientName = $originalMessage['sender_name'];
            }
        }

        $stats = $this->messageModel->getStatistics((int)$ship['ship_id']);

        $data = compact('ship', 'stats', 'recipientName', 'originalMessage', 'session');

        ob_start();
        include __DIR__ . '/../Views/messages_compose.php';
        echo ob_get_clean();
    }

    /**
     * Send message
     */
    public function send(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /messages/compose');
            exit;
        }

        $recipientName = trim($_POST['recipient'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');

        // Validate
        if (empty($recipientName)) {
            $this->session->set('error', 'Recipient is required');
            header('Location: /messages/compose');
            exit;
        }

        if (empty($subject)) {
            $this->session->set('error', 'Subject is required');
            header('Location: /messages/compose');
            exit;
        }

        if (empty($body)) {
            $this->session->set('error', 'Message body is required');
            header('Location: /messages/compose');
            exit;
        }

        if (strlen($subject) > 100) {
            $this->session->set('error', 'Subject is too long (max 100 characters)');
            header('Location: /messages/compose');
            exit;
        }

        if (strlen($body) > 5000) {
            $this->session->set('error', 'Message is too long (max 5000 characters)');
            header('Location: /messages/compose');
            exit;
        }

        // Find recipient
        $recipient = $this->shipModel->findByName($recipientName);

        if (!$recipient) {
            $this->session->set('error', 'Player not found');
            header('Location: /messages/compose?to=' . urlencode($recipientName));
            exit;
        }

        // Can't send to yourself
        if ($recipient['ship_id'] == $ship['ship_id']) {
            $this->session->set('error', 'You cannot send a message to yourself');
            header('Location: /messages/compose');
            exit;
        }

        // Send message
        $this->messageModel->send(
            (int)$ship['ship_id'],
            (int)$recipient['ship_id'],
            $subject,
            $body
        );

        $this->session->set('message', "Message sent to {$recipient['character_name']}");
        header('Location: /messages/sent');
        exit;
    }

    /**
     * View message
     */
    public function view(int $messageId): void
    {
        $ship = $this->requireAuth();

        // Check if player can view this message
        if (!$this->messageModel->canView($messageId, (int)$ship['ship_id'])) {
            $this->session->set('error', 'Message not found');
            header('Location: /messages');
            exit;
        }

        $message = $this->messageModel->find($messageId);

        if (!$message) {
            $this->session->set('error', 'Message not found');
            header('Location: /messages');
            exit;
        }

        // Mark as read if recipient is viewing
        if ($message['recipient_id'] == $ship['ship_id'] && !$message['is_read']) {
            $this->messageModel->markAsRead($messageId, (int)$ship['ship_id']);
        }

        $stats = $this->messageModel->getStatistics((int)$ship['ship_id']);
        $isRecipient = $message['recipient_id'] == $ship['ship_id'];

        $data = compact('ship', 'message', 'stats', 'isRecipient', 'session');

        ob_start();
        include __DIR__ . '/../Views/messages_view.php';
        echo ob_get_clean();
    }

    /**
     * Delete message
     */
    public function delete(int $messageId): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /messages');
            exit;
        }

        if (!$this->messageModel->canView($messageId, (int)$ship['ship_id'])) {
            $this->session->set('error', 'Message not found');
            header('Location: /messages');
            exit;
        }

        $message = $this->messageModel->find($messageId);
        $isSender = $message && $message['sender_id'] == $ship['ship_id'];

        $this->messageModel->delete($messageId, (int)$ship['ship_id'], $isSender);

        $this->session->set('message', 'Message deleted');

        $returnTo = $_POST['return_to'] ?? 'inbox';
        header('Location: /messages/' . $returnTo);
        exit;
    }

    /**
     * Mark all as read
     */
    public function markAllRead(): void
    {
        $ship = $this->requireAuth();

        // Verify CSRF
        $token = $_POST['csrf_token'] ?? '';
        if (!$this->session->validateCsrfToken($token)) {
            $this->session->set('error', 'Invalid request');
            header('Location: /messages');
            exit;
        }

        $this->messageModel->markAllAsRead((int)$ship['ship_id']);

        $this->session->set('message', 'All messages marked as read');
        header('Location: /messages');
        exit;
    }
}
