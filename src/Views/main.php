<?php
$title = 'Main - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Sector <?= (int)$ship['sector'] ?> - <?= htmlspecialchars($sector['sector_name'] ?? 'Unknown') ?></h2>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Turns</div>
        <div class="stat-value"><?= number_format($ship['turns']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Credits</div>
        <div class="stat-value"><?= number_format($ship['credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Score</div>
        <div class="stat-value"><?= number_format($ship['score']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Holds</div>
        <div class="stat-value"><?= number_format($usedHolds) ?> / <?= number_format($maxHolds) ?></div>
    </div>
</div>

<?php if ($sector['beacon']): ?>
<div class="alert alert-info">
    <strong>Beacon:</strong> <?= htmlspecialchars($sector['beacon']) ?>
</div>
<?php endif; ?>

<h3>Navigation Links</h3>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin: 20px 0;">
    <?php foreach ($links as $link): ?>
    <form action="/move/<?= (int)$link['sector_id'] ?>" method="post" style="display: inline;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="width: 100%;">
            Sector <?= (int)$link['sector_id'] ?>
            <?php if ($link['port_type'] !== 'none'): ?>
            <br><small>[<?= htmlspecialchars($link['port_type']) ?>]</small>
            <?php endif; ?>
        </button>
    </form>
    <?php endforeach; ?>
</div>

<?php if (!empty($planets)): ?>
<h3>Planets in This Sector</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Owner</th>
            <th>Base</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($planets as $planet): ?>
        <tr>
            <td><?= htmlspecialchars($planet['planet_name']) ?></td>
            <td><?= $planet['owner'] ? htmlspecialchars($planet['owner_name']) : 'Unowned' ?></td>
            <td><?= $planet['base'] ? 'Yes' : 'No' ?></td>
            <td>
                <a href="/planet/<?= (int)$planet['planet_id'] ?>" class="btn">View</a>
                <?php if ($planet['owner'] == 0 || $planet['owner'] == $ship['ship_id']): ?>
                <form action="/land/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <button type="submit" class="btn">Land</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($sector['port_type'] !== 'none'): ?>
<div style="margin-top: 30px;">
    <h3>Port - <?= htmlspecialchars(ucfirst($sector['port_type'])) ?> Trade</h3>
    <a href="/port" class="btn">Enter Port</a>
</div>
<?php endif; ?>

<?php if (!empty($shipsInSector)): ?>
<h3>Ships in This Sector</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Score</th>
            <th>Team</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shipsInSector as $otherShip): ?>
        <tr>
            <td><?= htmlspecialchars($otherShip['character_name']) ?></td>
            <td><?= number_format($otherShip['score']) ?></td>
            <td><?= $otherShip['team'] ? 'Team #' . $otherShip['team'] : 'None' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div style="margin-top: 30px;">
    <h3>Ship Status</h3>
    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
        <div>Ore: <?= number_format($ship['ship_ore']) ?></div>
        <div>Organics: <?= number_format($ship['ship_organics']) ?></div>
        <div>Goods: <?= number_format($ship['ship_goods']) ?></div>
        <div>Energy: <?= number_format($ship['ship_energy']) ?></div>
        <div>Colonists: <?= number_format($ship['ship_colonists']) ?></div>
        <div>Fighters: <?= number_format($ship['ship_fighters']) ?></div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
