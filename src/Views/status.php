<?php
$title = 'Ship Status - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2><?= htmlspecialchars($ship['character_name']) ?> - Ship Status</h2>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Score</div>
        <div class="stat-value"><?= number_format($score) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Credits</div>
        <div class="stat-value"><?= number_format($ship['credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Turns</div>
        <div class="stat-value"><?= number_format($ship['turns']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Sector</div>
        <div class="stat-value"><?= number_format($ship['sector']) ?></div>
    </div>
</div>

<h3>Ship Specifications</h3>
<table>
    <tr>
        <th>Component</th>
        <th>Level</th>
        <th>Capacity</th>
    </tr>
    <tr>
        <td>Hull</td>
        <td><?= (int)$ship['hull'] ?></td>
        <td><?= number_format($maxHolds) ?> holds</td>
    </tr>
    <tr>
        <td>Engines</td>
        <td><?= (int)$ship['engines'] ?></td>
        <td>-</td>
    </tr>
    <tr>
        <td>Power</td>
        <td><?= (int)$ship['power'] ?></td>
        <td><?= number_format($maxEnergy) ?> units</td>
    </tr>
    <tr>
        <td>Computer</td>
        <td><?= (int)$ship['computer'] ?></td>
        <td><?= number_format($maxFighters) ?> fighters</td>
    </tr>
    <tr>
        <td>Sensors</td>
        <td><?= (int)$ship['sensors'] ?></td>
        <td>-</td>
    </tr>
    <tr>
        <td>Beams</td>
        <td><?= (int)$ship['beams'] ?></td>
        <td>-</td>
    </tr>
    <tr>
        <td>Torpedo Launchers</td>
        <td><?= (int)$ship['torp_launchers'] ?></td>
        <td><?= number_format($maxTorps) ?> torpedoes</td>
    </tr>
    <tr>
        <td>Shields</td>
        <td><?= (int)$ship['shields'] ?></td>
        <td>-</td>
    </tr>
    <tr>
        <td>Armor</td>
        <td><?= (int)$ship['armor'] ?></td>
        <td><?= number_format($ship['armor_pts']) ?> pts</td>
    </tr>
    <tr>
        <td>Cloak</td>
        <td><?= (int)$ship['cloak'] ?></td>
        <td>-</td>
    </tr>
</table>

<h3>Cargo</h3>
<table>
    <tr>
        <th>Item</th>
        <th>Amount</th>
    </tr>
    <tr>
        <td>Ore</td>
        <td><?= number_format($ship['ship_ore']) ?></td>
    </tr>
    <tr>
        <td>Organics</td>
        <td><?= number_format($ship['ship_organics']) ?></td>
    </tr>
    <tr>
        <td>Goods</td>
        <td><?= number_format($ship['ship_goods']) ?></td>
    </tr>
    <tr>
        <td>Energy</td>
        <td><?= number_format($ship['ship_energy']) ?></td>
    </tr>
    <tr>
        <td>Colonists</td>
        <td><?= number_format($ship['ship_colonists']) ?></td>
    </tr>
    <tr>
        <td>Fighters</td>
        <td><?= number_format($ship['ship_fighters']) ?></td>
    </tr>
    <tr>
        <td>Torpedoes</td>
        <td><?= number_format($ship['torps']) ?></td>
    </tr>
</table>

<h3>Special Devices</h3>
<table>
    <tr>
        <th>Device</th>
        <th>Quantity</th>
    </tr>
    <tr>
        <td>Genesis Torpedoes</td>
        <td><?= (int)$ship['dev_genesis'] ?></td>
    </tr>
    <tr>
        <td>Beacons</td>
        <td><?= (int)$ship['dev_beacon'] ?></td>
    </tr>
    <tr>
        <td>Emergency Warp</td>
        <td><?= (int)$ship['dev_emerwarp'] ?></td>
    </tr>
    <tr>
        <td>Warp Editors</td>
        <td><?= (int)$ship['dev_warpedit'] ?></td>
    </tr>
    <tr>
        <td>Mine Deflectors</td>
        <td><?= (int)$ship['dev_minedeflector'] ?></td>
    </tr>
    <tr>
        <td>Escape Pod</td>
        <td><?= $ship['dev_escapepod'] ? 'Yes' : 'No' ?></td>
    </tr>
    <tr>
        <td>Fuel Scoop</td>
        <td><?= $ship['dev_fuelscoop'] ? 'Yes' : 'No' ?></td>
    </tr>
    <tr>
        <td>LSSD</td>
        <td><?= $ship['dev_lssd'] ? 'Yes' : 'No' ?></td>
    </tr>
</table>

<?php if (!empty($planets)): ?>
<h3>Your Planets (<?= count($planets) ?>)</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Sector</th>
            <th>Colonists</th>
            <th>Base</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($planets as $planet): ?>
        <tr>
            <td><?= htmlspecialchars($planet['planet_name']) ?></td>
            <td><a href="/main?sector=<?= (int)$planet['sector_id'] ?>"><?= (int)$planet['sector_id'] ?></a></td>
            <td><?= number_format($planet['colonists']) ?></td>
            <td><?= $planet['base'] ? 'Yes' : 'No' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
