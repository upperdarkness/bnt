<?php
$title = 'Planet - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2><?= htmlspecialchars($planet['planet_name']) ?></h2>

<div class="alert alert-info">
    Sector <?= (int)$planet['sector_id'] ?> |
    Owner: <?= $planet['owner'] == 0 ? 'Unclaimed' : ($planet['owner'] == $ship['ship_id'] ? 'You' : 'Other Player') ?>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Colonists</div>
        <div class="stat-value"><?= number_format($planet['colonists']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Fighters</div>
        <div class="stat-value"><?= number_format($planet['fighters']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Credits</div>
        <div class="stat-value"><?= number_format($planet['credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Base</div>
        <div class="stat-value" style="font-size: 18px;"><?= $planet['base'] ? '✓ Yes' : '✗ No' ?></div>
    </div>
</div>

<h3>Resources</h3>
<table>
    <tr>
        <th>Resource</th>
        <th>Quantity</th>
    </tr>
    <tr>
        <td>Ore</td>
        <td><?= number_format($planet['ore']) ?></td>
    </tr>
    <tr>
        <td>Organics</td>
        <td><?= number_format($planet['organics']) ?></td>
    </tr>
    <tr>
        <td>Goods</td>
        <td><?= number_format($planet['goods']) ?></td>
    </tr>
    <tr>
        <td>Energy</td>
        <td><?= number_format($planet['energy']) ?></td>
    </tr>
</table>

<?php if ($planet['owner'] == $ship['ship_id']): ?>
<h3 style="margin-top: 30px;">Production Allocation</h3>
<table>
    <tr>
        <th>Type</th>
        <th>Allocation</th>
    </tr>
    <tr>
        <td>Ore</td>
        <td><?= number_format($planet['prod_ore'], 1) ?>%</td>
    </tr>
    <tr>
        <td>Organics</td>
        <td><?= number_format($planet['prod_organics'], 1) ?>%</td>
    </tr>
    <tr>
        <td>Goods</td>
        <td><?= number_format($planet['prod_goods'], 1) ?>%</td>
    </tr>
    <tr>
        <td>Energy</td>
        <td><?= number_format($planet['prod_energy'], 1) ?>%</td>
    </tr>
    <tr>
        <td>Fighters</td>
        <td><?= number_format($planet['prod_fighters'], 1) ?>%</td>
    </tr>
    <tr>
        <td>Torpedoes</td>
        <td><?= number_format($planet['prod_torp'], 1) ?>%</td>
    </tr>
</table>

<div style="margin-top: 20px;">
    <p style="color: #7f8c8d; font-size: 14px;">
        Total production: 100% | Managed planets produce resources each game tick
    </p>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>

    <?php if ($planet['owner'] == 0): ?>
    <!-- Unclaimed Planet -->
    <form action="/land/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn">Land on Planet</button>
    </form>
    <?php if ($ship['ship_colonists'] >= 100): ?>
    <form action="/planet/colonize/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;"
          onsubmit="return confirm('Colonize this planet? This will transfer 100 colonists to claim it.');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
            Colonize Planet (100 colonists)
        </button>
    </form>
    <?php else: ?>
    <span style="color: #7f8c8d; font-size: 14px;">(Need 100 colonists to colonize)</span>
    <?php endif; ?>

    <?php elseif ($planet['owner'] == $ship['ship_id']): ?>
    <!-- Your Planet -->
    <?php if (!$isOnPlanet): ?>
    <form action="/land/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn">Land on Planet</button>
    </form>
    <?php else: ?>
    <div style="background: rgba(46, 204, 113, 0.2); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
        <strong>✓ On Planet Surface</strong>
        <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center;">
            <span>Quick Transfer Colonists:</span>
            <?php if ($ship['ship_colonists'] > 0): ?>
            <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="direction" value="to_planet">
                <input type="hidden" name="resource_type" value="colonists">
                <input type="number" name="amount" min="1" max="<?= (int)$ship['ship_colonists'] ?>" value="100" style="width: 80px; display: inline-block;">
                <button type="submit" class="btn" style="padding: 5px 10px;">Drop Off</button>
            </form>
            <?php endif; ?>
            <?php if ($planet['colonists'] > 0): ?>
            <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="direction" value="to_ship">
                <input type="hidden" name="resource_type" value="colonists">
                <input type="number" name="amount" min="1" max="<?= (int)$planet['colonists'] ?>" value="100" style="width: 80px; display: inline-block;">
                <button type="submit" class="btn" style="padding: 5px 10px;">Pick Up</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <a href="/planet/manage/<?= (int)$planet['planet_id'] ?>" class="btn" style="background: rgba(52, 152, 219, 0.3); border-color: #3498db;">
        Manage Planet
    </a>

    <?php else: ?>
    <!-- Enemy Planet -->
    <a href="/combat" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">Attack Planet</a>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
