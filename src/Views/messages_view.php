<?php
$title = htmlspecialchars($message['subject']) . ' - Messages - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>View Message</h2>

<div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
    <a href="/messages" class="btn">
        📥 Inbox (<?= number_format($stats['inbox_unread']) ?> unread)
    </a>
    <a href="/messages/sent" class="btn">
        📤 Sent (<?= number_format($stats['sent_total']) ?>)
    </a>
    <a href="/messages/compose" class="btn" style="background: rgba(52, 152, 219, 0.3); border-color: #3498db;">
        ✉️ Compose
    </a>
</div>

<div style="background: rgba(15, 76, 117, 0.2); padding: 30px; border-radius: 8px;">
    <!-- Message Header -->
    <div style="border-bottom: 1px solid rgba(52, 152, 219, 0.3); padding-bottom: 20px; margin-bottom: 20px;">
        <table style="width: 100%; border: none; margin: 0;">
            <tr>
                <td style="width: 100px; border: none; color: #7f8c8d; padding: 5px 10px 5px 0;">
                    <strong>From:</strong>
                </td>
                <td style="border: none; padding: 5px 0;">
                    <a href="/messages/compose?to=<?= urlencode($message['sender_name']) ?>" style="color: #3498db;">
                        <?= htmlspecialchars($message['sender_name']) ?>
                    </a>
                </td>
            </tr>
            <tr>
                <td style="border: none; color: #7f8c8d; padding: 5px 10px 5px 0;">
                    <strong>To:</strong>
                </td>
                <td style="border: none; padding: 5px 0;">
                    <?= htmlspecialchars($message['recipient_name']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; color: #7f8c8d; padding: 5px 10px 5px 0;">
                    <strong>Date:</strong>
                </td>
                <td style="border: none; padding: 5px 0;">
                    <?= date('l, F j, Y \a\t g:i A', strtotime($message['sent_date'])) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; color: #7f8c8d; padding: 5px 10px 5px 0;">
                    <strong>Subject:</strong>
                </td>
                <td style="border: none; padding: 5px 0;">
                    <span style="font-size: 18px; color: #e0e0e0;">
                        <?= htmlspecialchars($message['subject']) ?>
                    </span>
                </td>
            </tr>
        </table>
    </div>

    <!-- Message Body -->
    <div style="background: rgba(22, 33, 62, 0.8); padding: 20px; border-radius: 5px; min-height: 200px;">
        <pre style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; white-space: pre-wrap; word-wrap: break-word; color: #e0e0e0; line-height: 1.6; margin: 0;"><?= htmlspecialchars($message['body']) ?></pre>
    </div>

    <!-- Actions -->
    <div style="display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap;">
        <?php if ($isRecipient): ?>
        <a href="/messages/compose?reply=<?= (int)$message['message_id'] ?>" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
            Reply
        </a>
        <?php endif; ?>

        <a href="/messages/compose?to=<?= urlencode($isRecipient ? $message['sender_name'] : $message['recipient_name']) ?>" class="btn">
            New Message
        </a>

        <form action="/messages/<?= (int)$message['message_id'] ?>/delete" method="post" style="display: inline; margin: 0;"
              onsubmit="return confirm('Delete this message?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <input type="hidden" name="return_to" value="<?= $isRecipient ? 'inbox' : 'sent' ?>">
            <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                Delete
            </button>
        </form>

        <?php if ($isRecipient): ?>
        <a href="/messages" class="btn">
            Back to Inbox
        </a>
        <?php else: ?>
        <a href="/messages/sent" class="btn">
            Back to Sent
        </a>
        <?php endif; ?>
    </div>
</div>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
