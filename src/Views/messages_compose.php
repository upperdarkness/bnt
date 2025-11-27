<?php
$title = 'Compose Message - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Compose Message</h2>

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

<?php if ($originalMessage): ?>
<div class="alert alert-info">
    <strong>Replying to:</strong> "<?= htmlspecialchars($originalMessage['subject']) ?>" from <?= htmlspecialchars($originalMessage['sender_name']) ?>
</div>
<?php endif; ?>

<div style="background: rgba(15, 76, 117, 0.2); padding: 30px; border-radius: 8px; max-width: 800px;">
    <form action="/messages/send" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">

        <div style="margin-bottom: 20px;">
            <label for="recipient" style="display: block; margin-bottom: 5px; color: #3498db;">
                To (Player Name): <span style="color: #e74c3c;">*</span>
            </label>
            <input type="text"
                   id="recipient"
                   name="recipient"
                   value="<?= htmlspecialchars($recipientName) ?>"
                   required
                   placeholder="Enter player name"
                   list="players">
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                Enter the exact character name of the recipient
            </small>
        </div>

        <div style="margin-bottom: 20px;">
            <label for="subject" style="display: block; margin-bottom: 5px; color: #3498db;">
                Subject: <span style="color: #e74c3c;">*</span>
            </label>
            <input type="text"
                   id="subject"
                   name="subject"
                   required
                   maxlength="100"
                   value="<?= $originalMessage ? 'Re: ' . htmlspecialchars($originalMessage['subject']) : '' ?>"
                   placeholder="Message subject (max 100 characters)">
        </div>

        <div style="margin-bottom: 20px;">
            <label for="body" style="display: block; margin-bottom: 5px; color: #3498db;">
                Message: <span style="color: #e74c3c;">*</span>
            </label>
            <textarea id="body"
                      name="body"
                      rows="12"
                      required
                      maxlength="5000"
                      placeholder="Write your message here (max 5000 characters)"><?php if ($originalMessage): ?>


--- Original Message ---
From: <?= htmlspecialchars($originalMessage['sender_name']) ?>

Date: <?= date('Y-m-d H:i', strtotime($originalMessage['sent_date'])) ?>

Subject: <?= htmlspecialchars($originalMessage['subject']) ?>


<?= htmlspecialchars($originalMessage['body']) ?>

<?php endif; ?></textarea>
            <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                Characters remaining: <span id="char-count">5000</span>
            </small>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn" style="flex: 1; background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                Send Message
            </button>
            <a href="/messages" class="btn" style="flex: 1; text-align: center;">
                Cancel
            </a>
        </div>
    </form>
</div>

<div style="margin-top: 30px; background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; max-width: 800px;">
    <h3>Messaging Tips</h3>
    <ul style="margin-left: 20px; color: #e0e0e0; line-height: 1.8;">
        <li>Messages are private between you and the recipient</li>
        <li>Use team chat for group coordination</li>
        <li>Be respectful - harassment will not be tolerated</li>
        <li>Subject line helps organize your messages</li>
        <li>Messages cannot be edited once sent</li>
        <li>Deleted messages cannot be recovered</li>
    </ul>
</div>

<div style="margin-top: 30px;">
    <a href="/messages" class="btn">Back to Inbox</a>
</div>

<script>
// Character counter
const textarea = document.getElementById('body');
const charCount = document.getElementById('char-count');

textarea.addEventListener('input', () => {
    const remaining = 5000 - textarea.value.length;
    charCount.textContent = remaining;
    charCount.style.color = remaining < 500 ? '#e74c3c' : '#7f8c8d';
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
