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
    <?php if ($planet['owner'] == 0 || $planet['owner'] == $ship['ship_id']): ?>
    <form action="/land/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn">Land on Planet</button>
    </form>
    <?php elseif ($planet['owner'] != $ship['ship_id']): ?>
    <a href="/combat" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">Attack Planet</a>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
