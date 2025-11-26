<?php
$title = 'Admin Login - BlackNova Traders';
$showHeader = false;
ob_start();
?>

<style>
    .admin-login-container {
        max-width: 400px;
        margin: 100px auto;
        background: rgba(15, 76, 117, 0.3);
        padding: 40px;
        border-radius: 10px;
        border: 1px solid rgba(52, 152, 219, 0.5);
    }

    .admin-login-header {
        text-align: center;
        margin-bottom: 30px;
    }

    .admin-login-header h2 {
        color: #e74c3c;
        text-shadow: 0 0 10px rgba(231, 76, 60, 0.5);
    }

    .admin-icon {
        font-size: 48px;
        margin-bottom: 10px;
    }
</style>

<div class="admin-login-container">
    <div class="admin-login-header">
        <div class="admin-icon">🔐</div>
        <h2>Admin Access</h2>
        <p style="color: #7f8c8d;">BlackNova Traders Administration</p>
    </div>

    <?php if ($message = $session->get('message')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php $session->remove('message'); ?>
    <?php endif; ?>

    <?php if ($error = $session->get('error')): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php $session->remove('error'); ?>
    <?php endif; ?>

    <form action="/admin/login" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">

        <div style="margin-bottom: 20px;">
            <label for="password" style="display: block; margin-bottom: 5px; color: #e74c3c;">
                Admin Password
            </label>
            <input type="password"
                   id="password"
                   name="password"
                   required
                   autofocus
                   placeholder="Enter admin password">
        </div>

        <button type="submit" class="btn" style="width: 100%; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
            Login to Admin Panel
        </button>
    </form>

    <div style="margin-top: 30px; text-align: center;">
        <a href="/" style="color: #3498db; text-decoration: none;">
            ← Back to Game
        </a>
    </div>

    <div class="alert alert-warning" style="margin-top: 30px;">
        <strong>⚠️ Security Notice:</strong> Admin access provides full control over the game.
        Change the default password in config.php immediately.
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
