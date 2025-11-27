<?php
$title = 'Login - BlackNova Traders';
$showHeader = false;
ob_start();
?>

<div style="text-align: center; margin-bottom: 40px;">
    <h1 style="font-size: 48px; color: #3498db; text-shadow: 0 0 20px rgba(52, 152, 219, 0.7);">
        BlackNova Traders
    </h1>
    <p style="color: #7f8c8d; font-size: 18px;">Space Trading & Combat Game</p>
</div>

<div style="max-width: 500px; margin: 0 auto;">
    <div style="background: rgba(15, 76, 117, 0.3); padding: 30px; border-radius: 10px; border: 1px solid rgba(52, 152, 219, 0.3); margin-bottom: 20px;">
        <h2 style="color: #3498db; margin-bottom: 20px;">Login</h2>
        <form action="/login" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($this->session->getCsrfToken()) ?>">

            <div style="margin-bottom: 15px;">
                <label>Email:</label>
                <input type="email" name="email" required>
            </div>

            <div style="margin-bottom: 20px;">
                <label>Password:</label>
                <input type="password" name="password" required>
            </div>

            <input type="submit" value="Login">
        </form>
    </div>

    <div style="background: rgba(15, 76, 117, 0.3); padding: 30px; border-radius: 10px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h2 style="color: #3498db; margin-bottom: 20px;">New Player Registration</h2>
        <form action="/register" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($this->session->getCsrfToken()) ?>">

            <div style="margin-bottom: 15px;">
                <label>Email:</label>
                <input type="email" name="email" required>
            </div>

            <div style="margin-bottom: 15px;">
                <label>Password (min 8 characters):</label>
                <input type="password" name="password" required minlength="8">
            </div>

            <div style="margin-bottom: 15px;">
                <label>Character Name:</label>
                <input type="text" name="character_name" required minlength="3" maxlength="50">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 10px; color: #3498db; font-weight: bold;">Choose Your Ship Type:</label>
                <?php
                $shipTypes = [
                    'scout' => ['name' => 'Scout', 'icon' => '🚀', 'desc' => 'Fast & efficient - 50% cheaper turns, 70% cargo'],
                    'merchant' => ['name' => 'Merchant', 'icon' => '🚢', 'desc' => 'Huge cargo - 200% cargo capacity, weak in combat'],
                    'warship' => ['name' => 'Warship', 'icon' => '⚔️', 'desc' => 'Combat focused - 150% damage, expensive turns'],
                    'balanced' => ['name' => 'Balanced', 'icon' => '🛸', 'desc' => 'Well-rounded - Average in all aspects']
                ];
                foreach ($shipTypes as $type => $info): ?>
                    <label style="display: block; padding: 12px; margin-bottom: 8px; background: rgba(52, 152, 219, 0.1); border: 2px solid rgba(52, 152, 219, 0.3); border-radius: 5px; cursor: pointer; transition: all 0.3s;">
                        <input type="radio" name="ship_type" value="<?= $type ?>" <?= $type === 'balanced' ? 'checked' : '' ?> style="margin-right: 10px;">
                        <span style="font-size: 18px;"><?= $info['icon'] ?></span>
                        <strong><?= $info['name'] ?></strong>
                        <div style="font-size: 12px; color: #bbb; margin-left: 30px;"><?= $info['desc'] ?></div>
                    </label>
                <?php endforeach; ?>
            </div>

            <input type="submit" value="Register">
        </form>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        <a href="/ranking" class="btn">View Rankings</a>
        <a href="/help" class="btn">Help & FAQ</a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
