<?php
$title = 'Choose a new password - BlackNova Traders';
$showHeader = false;
ob_start();
?>

<div style="text-align: center; margin-bottom: 40px;">
    <h1 style="font-size: 48px; color: #3498db; text-shadow: 0 0 20px rgba(52, 152, 219, 0.7);">BlackNova Traders</h1>
</div>

<div style="max-width: 500px; margin: 0 auto;">
    <div style="background: rgba(15, 76, 117, 0.3); padding: 30px; border-radius: 10px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h2 style="color: #3498db; margin-bottom: 20px;">Choose a new password</h2>
        <?php if ($valid): ?>
            <form action="/reset-password" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div style="margin-bottom: 15px;">
                    <label>New password (min 8 characters):</label>
                    <input type="password" name="password" required minlength="8" maxlength="72" autofocus autocomplete="new-password">
                </div>
                <div style="margin-bottom: 20px;">
                    <label>Repeat the new password:</label>
                    <input type="password" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password">
                </div>
                <input type="submit" value="Change password">
            </form>
        <?php else: ?>
            <p style="color: #e74c3c; margin-bottom: 20px;">This reset link is invalid, already used, or has expired.</p>
            <a class="btn" href="/forgot-password">Request a new link</a>
        <?php endif; ?>
        <p style="margin-top: 20px;"><a href="/">Back to login</a></p>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
