<?php
$title = 'Sector Defenses - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Your Sector Defenses</h2>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Fighters Deployed</div>
        <div class="stat-value"><?= number_format($totalFighters) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Mines Deployed</div>
        <div class="stat-value"><?= number_format($totalMines) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sectors Defended</div>
        <div class="stat-value"><?= count($defenses) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Current Sector</div>
        <div class="stat-value"><?= (int)$ship['sector'] ?></div>
    </div>
</div>

<div class="alert alert-info">
    <strong>Defense Management:</strong> Deploy fighters and mines to protect sectors.
    You can only retrieve defenses when you're in the same sector.
</div>

<?php if (empty($defenses)): ?>
<div style="margin-top: 30px; text-align: center; padding: 40px;">
    <p style="color: #7f8c8d; font-size: 18px;">You have no sector defenses deployed.</p>
    <p style="color: #7f8c8d;">Visit the <a href="/combat" style="color: #3498db;">Combat</a> page to deploy fighters and mines.</p>
</div>
<?php else: ?>
<div style="margin-top: 30px;">
    <h3>Deployed Defenses</h3>
    <table>
        <thead>
            <tr>
                <th>Sector</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($defenses as $defense): ?>
            <tr>
                <td>
                    <a href="/main?sector=<?= (int)$defense['sector_id'] ?>" style="color: #3498db;">
                        Sector <?= (int)$defense['sector_id'] ?>
                    </a>
                    <?php if ($defense['sector_id'] == $ship['sector']): ?>
                        <span style="color: #2ecc71; font-size: 12px;">(Current)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($defense['defence_type'] === 'F'): ?>
                        <span style="color: #3498db;">🛡️ Fighters</span>
                    <?php else: ?>
                        <span style="color: #e74c3c;">💣 Mines</span>
                    <?php endif; ?>
                </td>
                <td><?= number_format($defense['quantity']) ?></td>
                <td>
                    <?php if ($defense['sector_id'] == $ship['sector']): ?>
                    <form action="/defenses/retrieve" method="post" style="display: inline;"
                          onsubmit="return confirm('Retrieve <?= (int)$defense['quantity'] ?> <?= htmlspecialchars($defense['type_name']) ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="defence_id" value="<?= (int)$defense['defence_id'] ?>">
                        <button type="submit" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                            Retrieve
                        </button>
                    </form>
                    <?php else: ?>
                    <span style="color: #7f8c8d;">Travel to sector to retrieve</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top: 30px; background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
    <h3>Defense Information</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <div>
            <h4>Fighters</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Attack enemy ships entering the sector</li>
                <li>Will not attack team members</li>
                <li>Damage: 2 per fighter</li>
                <li>Can be deployed and retrieved</li>
            </ul>
        </div>
        <div>
            <h4>Mines</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Damage ships with hull size 8 or larger</li>
                <li>Chance to hit: 20% per mine (max 80%)</li>
                <li>Damage: 500 per mine hit</li>
                <li>Uses torpedoes to deploy</li>
                <li>Can be retrieved as torpedoes</li>
            </ul>
        </div>
        <div>
            <h4>Strategy Tips</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Protect valuable sectors with both</li>
                <li>Fighters are reusable, mines explode</li>
                <li>Deploy near your planets</li>
                <li>Team defenses protect all allies</li>
                <li>Monitor your defense network</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 30px;">
    <a href="/combat" class="btn">Deploy More Defenses</a>
    <a href="/main" class="btn">Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
