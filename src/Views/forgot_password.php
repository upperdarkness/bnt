<?php
$title = 'Forgot password - BlackNova Traders';
$showHeader = false;
ob_start();
?>

<div style="text-align: center; margin-bottom: 40px;">
    <h1 style="font-size: 48px; color: #3498db; text-shadow: 0 0 20px rgba(52, 152, 219, 0.7);">BlackNova Traders</h1>
</div>

<div style="max-width: 500px; margin: 0 auto;">
    <div style="background: rgba(15, 76, 117, 0.3); padding: 30px; border-radius: 10px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h2 style="color: #3498db; margin-bottom: 20px;">Forgot your password?</h2>
        <p style="color: #bdc3c7; margin-bottom: 20px;">
            Enter the email address you registered with. If it belongs to an account, we will send a link that lets you
            choose a new password. The link works once and expires after <?= (int)$resets->ttlMinutes() ?> minutes.
        </p>
        <?php if (!$resets->mailEnabled()): ?>
            <p style="color: #f39c12; margin-bottom: 20px;">
                This server is not set up to send email yet. Ask the game administrator to send you a reset link.
            </p>
        <?php endif; ?>
        <form action="/forgot-password" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <div style="margin-bottom: 20px;">
                <label>Email:</label>
                <input type="email" name="email" required autofocus>
            </div>
            <input type="submit" value="Send reset link">
        </form>
        <p style="margin-top: 20px;"><a href="/">Back to login</a></p>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
