<?php
$title = 'Sent Messages - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Messages - Sent</h2>

<div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap;">
    <a href="/messages" class="btn">
        📥 Inbox (<?= number_format($stats['inbox_unread']) ?> unread)
    </a>
    <a href="/messages/sent" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
        📤 Sent (<?= number_format($stats['sent_total']) ?>)
    </a>
    <a href="/messages/compose" class="btn" style="background: rgba(52, 152, 219, 0.3); border-color: #3498db;">
        ✉️ Compose
    </a>
</div>

<?php if (empty($messages)): ?>
<div style="text-align: center; padding: 40px;">
    <p style="color: #7f8c8d; font-size: 18px;">You haven't sent any messages yet.</p>
</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th style="width: 150px;">To</th>
            <th>Subject</th>
            <th style="width: 180px;">Date Sent</th>
            <th style="width: 100px;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($messages as $msg): ?>
        <tr>
            <td>
                <a href="/messages/compose?to=<?= urlencode($msg['recipient_name']) ?>" style="color: #3498db;">
                    <?= htmlspecialchars($msg['recipient_name']) ?>
                </a>
            </td>
            <td>
                <a href="/messages/view/<?= (int)$msg['message_id'] ?>" style="color: #3498db;">
                    <?= htmlspecialchars($msg['subject']) ?>
                </a>
            </td>
            <td>
                <?= date('Y-m-d H:i', strtotime($msg['sent_date'])) ?>
            </td>
            <td>
                <a href="/messages/view/<?= (int)$msg['message_id'] ?>" class="btn" style="padding: 5px 10px;">
                    View
                </a>
                <form action="/messages/<?= (int)$msg['message_id'] ?>/delete" method="post" style="display: inline;"
                      onsubmit="return confirm('Delete this message?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <input type="hidden" name="return_to" value="sent">
                    <button type="submit" class="btn" style="padding: 5px 10px; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                        Delete
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (count($messages) >= 25): ?>
<div style="margin-top: 20px; text-align: center;">
    <?php if ($page > 1): ?>
        <a href="/messages/sent?page=<?= $page - 1 ?>" class="btn">← Previous</a>
    <?php endif; ?>
    <span style="margin: 0 15px; color: #7f8c8d;">Page <?= $page ?></span>
    <a href="/messages/sent?page=<?= $page + 1 ?>" class="btn">Next →</a>
</div>
<?php endif; ?>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
