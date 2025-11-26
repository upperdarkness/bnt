<?php
$title = 'Scan - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Long Range Scan - Sector <?= (int)$ship['sector'] ?></h2>

<div class="alert alert-info">
    Scanning nearby sectors and analyzing threats...
</div>

<h3>Current Sector: <?= (int)$ship['sector'] ?></h3>

<?php if ($sector['beacon']): ?>
<div style="background: rgba(52, 152, 219, 0.2); padding: 15px; border-radius: 8px; margin: 15px 0;">
    <strong>Beacon Message:</strong><br>
    <?= htmlspecialchars($sector['beacon']) ?>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Port Type</div>
        <div class="stat-value" style="font-size: 16px;"><?= htmlspecialchars(ucfirst($sector['port_type'])) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Zone</div>
        <div class="stat-value" style="font-size: 16px;">Zone <?= (int)$sector['zone_id'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Ships Present</div>
        <div class="stat-value"><?= count($shipsInSector) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Planets</div>
        <div class="stat-value"><?= count($planets) ?></div>
    </div>
</div>

<?php if (!empty($shipsInSector)): ?>
<h3 style="margin-top: 30px;">Ships Detected</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Score</th>
            <th>Team</th>
            <th>Threat Level</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shipsInSector as $otherShip): ?>
        <tr>
            <td><?= htmlspecialchars($otherShip['character_name']) ?></td>
            <td><?= number_format($otherShip['score']) ?></td>
            <td>
                <?php if ($otherShip['team'] == 0): ?>
                    <span style="color: #7f8c8d;">Unaffiliated</span>
                <?php elseif ($ship['team'] != 0 && $otherShip['team'] == $ship['team']): ?>
                    <span style="color: #2ecc71;">Team #<?= $otherShip['team'] ?> (Allied)</span>
                <?php else: ?>
                    <span style="color: #e67e22;">Team #<?= $otherShip['team'] ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $threat = 'Unknown';
                $color = '#7f8c8d';
                if ($otherShip['score'] < $ship['score'] * 0.5) {
                    $threat = 'Low';
                    $color = '#2ecc71';
                } elseif ($otherShip['score'] < $ship['score'] * 1.5) {
                    $threat = 'Medium';
                    $color = '#f39c12';
                } else {
                    $threat = 'High';
                    $color = '#e74c3c';
                }
                ?>
                <span style="color: <?= $color ?>;"><?= $threat ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (!empty($planets)): ?>
<h3 style="margin-top: 30px;">Planetary Bodies</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Owner</th>
            <th>Colonists</th>
            <th>Defense Rating</th>
            <th>Base</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($planets as $planet): ?>
        <tr>
            <td><?= htmlspecialchars($planet['planet_name']) ?></td>
            <td>
                <?php if ($planet['owner'] == 0): ?>
                    <span style="color: #7f8c8d;">Unclaimed</span>
                <?php elseif ($planet['owner'] == $ship['ship_id']): ?>
                    <span style="color: #2ecc71;">You</span>
                <?php else: ?>
                    <?= htmlspecialchars($planet['owner_name']) ?>
                <?php endif; ?>
            </td>
            <td><?= number_format($planet['colonists']) ?></td>
            <td>
                <?php
                $defense = $planet['fighters'] + ($planet['base'] ? 500 : 0);
                $defenseRating = 'Minimal';
                $color = '#2ecc71';
                if ($defense > 10000) {
                    $defenseRating = 'Extreme';
                    $color = '#e74c3c';
                } elseif ($defense > 5000) {
                    $defenseRating = 'Heavy';
                    $color = '#f39c12';
                } elseif ($defense > 1000) {
                    $defenseRating = 'Moderate';
                    $color = '#3498db';
                }
                ?>
                <span style="color: <?= $color ?>;"><?= $defenseRating ?></span>
            </td>
            <td><?= $planet['base'] ? '<span style="color: #e74c3c;">Yes</span>' : 'No' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if (!empty($defenses)): ?>
<h3 style="margin-top: 30px;">Sector Defenses</h3>
<table>
    <thead>
        <tr>
            <th>Type</th>
            <th>Quantity</th>
            <th>Owner</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($defenses as $defense): ?>
        <tr>
            <td><?= $defense['defence_type'] === 'F' ? '🛡️ Fighters' : '💣 Mines' ?></td>
            <td><?= number_format($defense['quantity']) ?></td>
            <td><?= htmlspecialchars($defense['character_name']) ?></td>
            <td>
                <?php if ($ship['team'] != 0 && $defense['team'] == $ship['team']): ?>
                    <span style="color: #2ecc71;">Friendly</span>
                <?php else: ?>
                    <span style="color: #e74c3c;">Hostile</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3 style="margin-top: 30px;">Connected Sectors</h3>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
    <?php foreach ($links as $link): ?>
    <div style="background: rgba(15, 76, 117, 0.3); padding: 15px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h4 style="color: #3498db; margin-bottom: 10px;">Sector <?= (int)$link['sector_id'] ?></h4>
        <div style="font-size: 12px; color: #7f8c8d;">
            Port: <?= htmlspecialchars(ucfirst($link['port_type'])) ?>
        </div>
        <?php if ($link['beacon']): ?>
        <div style="font-size: 11px; color: #3498db; margin-top: 5px;">
            📡 Beacon Active
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
    <a href="/combat" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">Combat</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
