<?php
$title = 'Ship Status - BlackNova Traders';
$showHeader = true;
ob_start();

// Get ship type information
$shipTypeInfo = \BNT\Models\ShipType::getInfo($ship['ship_type'] ?? 'balanced');
?>

<h2><?= htmlspecialchars($ship['character_name']) ?> - Ship Status</h2>

<?php if (!empty($protectionProgress) && ($protectionProgress['protected'] || $protectionProgress['state'] === 'grace')): ?>
<div class="alert alert-info" style="margin-bottom: 20px;">
    <strong>🛡️ <?= htmlspecialchars($protectionProgress['text']) ?></strong>
    <?php if ($protectionProgress['state'] === 'protected'): ?>
        <div style="margin-top:6px; font-size: 13px;">
            Protection ends at the first of: <?= (int)$protectionProgress['active_days_needed'] ?> active days, a score of
            <?= number_format($protectionProgress['score_needed']) ?>, attacking anyone, deploying sector defences, or opting out.
            Closest: <strong><?= $protectionProgress['closest'] === 'days' ? 'active days' : 'score' ?></strong>.
        </div>
        <form method="POST" action="/protection/opt-out" style="margin-top:10px;" onsubmit="return confirm('Give up newbie protection permanently?');">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <button class="btn" type="submit">Give up protection</button>
        </form>
    <?php elseif ($protectionProgress['respawn_shield_until']): ?>
        <div style="margin-top:6px; font-size: 13px;">Respawn shield until <?= htmlspecialchars(date('M j, g:i A', strtotime($protectionProgress['respawn_shield_until']))) ?>.</div>
    <?php endif; ?>
</div>
<?php elseif (!empty($protectionProgress['respawn_shield_until'])): ?>
<div class="alert alert-info" style="margin-bottom: 20px;">🛡️ <?= htmlspecialchars($protectionProgress['text']) ?></div>
<?php endif; ?>

<div style="background: rgba(15, 76, 117, 0.3); padding: 15px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3); margin-bottom: 20px; text-align: center;">
    <img class="ship-portrait" src="<?= \BNT\Core\GameArtwork::ship($ship['ship_type'] ?? 'balanced') ?>" alt="<?= htmlspecialchars($shipTypeInfo['name']) ?> spacecraft" width="1280" height="1280">
    <div style="color: <?= $shipTypeInfo['color'] ?>; font-size: 20px; font-weight: bold; margin-bottom: 5px;"><?= htmlspecialchars($shipTypeInfo['name']) ?></div>
    <div style="color: #bbb; font-size: 14px;"><?= htmlspecialchars($shipTypeInfo['description']) ?></div>
    <div style="margin-top: 10px; font-size: 12px; color: #888;">
        <?php if ($shipTypeInfo['cargo_multiplier'] != 1.0): ?>
            Cargo: <?= (int)($shipTypeInfo['cargo_multiplier'] * 100) ?>% |
        <?php endif; ?>
        <?php if ($shipTypeInfo['turn_cost_multiplier'] != 1.0): ?>
            Turn Cost: <?= (int)($shipTypeInfo['turn_cost_multiplier'] * 100) ?>% |
        <?php endif; ?>
        <?php if ($shipTypeInfo['combat_multiplier'] != 1.0): ?>
            Combat: <?= (int)($shipTypeInfo['combat_multiplier'] * 100) ?>% |
        <?php endif; ?>
        <?php if ($shipTypeInfo['defense_multiplier'] != 1.0): ?>
            Defense: <?= (int)($shipTypeInfo['defense_multiplier'] * 100) ?>% |
        <?php endif; ?>
        <?php if ($shipTypeInfo['speed_bonus'] != 1.0): ?>
            Speed: <?= (int)($shipTypeInfo['speed_bonus'] * 100) ?>%
        <?php endif; ?>
    </div>
</div>

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

<?php if (!empty($session)): ?>
<div style="margin-top: 30px;">
    <h3>Courier interviews</h3>
    <form method="POST" action="/settings/interviews" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <?php $optedOut = !empty($ship['interview_opt_out']); ?>
        <span><?= $optedOut ? 'You have opted out of interview requests.' : 'The Galactic Courier may ask you for a quote about big stories.' ?></span>
        <input type="hidden" name="opt_out" value="<?= $optedOut ? '0' : '1' ?>">
        <button class="btn" type="submit"><?= $optedOut ? 'Allow interview requests' : 'Opt out of interview requests' ?></button>
    </form>
    <small style="color:#7f8c8d;">You can't opt out of being named in factual news.</small>
</div>
<?php endif; ?>

<?php if (!empty($rumourLog)): ?>
<div style="margin-top: 30px;">
    <h3>Rumour log</h3>
    <table>
        <thead><tr><th>Bought</th><th>Tier</th><th>Rumour</th><th>Outcome</th></tr></thead>
        <tbody>
        <?php foreach ($rumourLog as $r): ?>
            <tr>
                <td><?= htmlspecialchars(date('M j g:i A', strtotime((string)$r['purchased_at']))) ?></td>
                <td><?= htmlspecialchars($r['tier']) ?></td>
                <td><?= htmlspecialchars($r['text']) ?></td>
                <td><?= $r['expired'] ? htmlspecialchars((string)$r['explanation']) : '<span style="color:#7f8c8d;">Not yet expired (until ' . htmlspecialchars(date('M j g:i A', strtotime((string)$r['expires_at']))) . ')</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
