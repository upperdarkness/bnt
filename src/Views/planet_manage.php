<?php
$title = 'Manage Planet - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Manage <?= htmlspecialchars($planet['planet_name']) ?></h2>

<div class="alert alert-info">
    Sector <?= (int)$planet['sector_id'] ?> |
    <?= $onSurface ? '<span style="color: #2ecc71;">✓ On Surface</span>' : '<span style="color: #e74c3c;">✗ In Orbit</span>' ?>
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

<?php if (!$onSurface): ?>
<div class="alert alert-warning">
    <strong>Warning:</strong> You must land on the planet to manage resources and production.
    <form action="/land/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline; margin-left: 15px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="padding: 5px 15px;">Land on Planet</button>
    </form>
</div>
<?php else: ?>

<!-- Resource Transfer Section -->
<h3>Resource Transfer</h3>
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
    <!-- Ship to Planet -->
    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
        <h4>Transfer to Planet</h4>
        <table style="width: 100%;">
            <tr>
                <th>Resource</th>
                <th>Ship Has</th>
                <th>Amount</th>
                <th>Action</th>
            </tr>
            <tr>
                <td>Colonists</td>
                <td><?= number_format($ship['ship_colonists']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="colonists">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['ship_colonists'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Fighters</td>
                <td><?= number_format($ship['ship_fighters']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="fighters">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['ship_fighters'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Ore</td>
                <td><?= number_format($ship['ship_ore']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="ore">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['ship_ore'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Organics</td>
                <td><?= number_format($ship['ship_organics']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="organics">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['ship_organics'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Goods</td>
                <td><?= number_format($ship['ship_goods']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="goods">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['ship_goods'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Energy</td>
                <td><?= number_format($ship['ship_energy']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="energy">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['ship_energy'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Credits</td>
                <td><?= number_format($ship['credits']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_planet">
                        <input type="hidden" name="resource_type" value="credits">
                        <input type="number" name="amount" min="0" max="<?= (int)$ship['credits'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
        </table>
    </div>

    <!-- Planet to Ship -->
    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
        <h4>Transfer to Ship</h4>
        <table style="width: 100%;">
            <tr>
                <th>Resource</th>
                <th>Planet Has</th>
                <th>Amount</th>
                <th>Action</th>
            </tr>
            <tr>
                <td>Colonists</td>
                <td><?= number_format($planet['colonists']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="colonists">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['colonists'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Fighters</td>
                <td><?= number_format($planet['fighters']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="fighters">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['fighters'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Ore</td>
                <td><?= number_format($planet['ore']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="ore">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['ore'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Organics</td>
                <td><?= number_format($planet['organics']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="organics">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['organics'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Goods</td>
                <td><?= number_format($planet['goods']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="goods">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['goods'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Energy</td>
                <td><?= number_format($planet['energy']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="energy">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['energy'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
            <tr>
                <td>Credits</td>
                <td><?= number_format($planet['credits']) ?></td>
                <td>
                    <form action="/planet/transfer/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <input type="hidden" name="direction" value="to_ship">
                        <input type="hidden" name="resource_type" value="credits">
                        <input type="number" name="amount" min="0" max="<?= (int)$planet['credits'] ?>" value="0" style="width: 100px;">
                        <button type="submit" class="btn" style="padding: 5px 10px; margin-left: 5px;">Transfer</button>
                    </form>
                </td>
                <td></td>
            </tr>
        </table>
    </div>
</div>

<!-- Production Allocation -->
<h3>Production Allocation</h3>
<div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; margin-bottom: 30px;">
    <form action="/planet/production/<?= (int)$planet['planet_id'] ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">

        <div class="alert alert-info" style="margin-bottom: 20px;">
            Production percentages must total exactly 100%. Current total:
            <span id="total-production"><?= number_format($planet['prod_ore'] + $planet['prod_organics'] + $planet['prod_goods'] + $planet['prod_energy'] + $planet['prod_fighters'] + $planet['prod_torp'], 1) ?></span>%
        </div>

        <table>
            <tr>
                <th>Type</th>
                <th>Current</th>
                <th>New Allocation (%)</th>
            </tr>
            <tr>
                <td>Ore</td>
                <td><?= number_format($planet['prod_ore'], 1) ?>%</td>
                <td><input type="number" name="prod_ore" min="0" max="100" step="0.1" value="<?= number_format($planet['prod_ore'], 1) ?>" style="width: 100px;" class="prod-input"></td>
            </tr>
            <tr>
                <td>Organics</td>
                <td><?= number_format($planet['prod_organics'], 1) ?>%</td>
                <td><input type="number" name="prod_organics" min="0" max="100" step="0.1" value="<?= number_format($planet['prod_organics'], 1) ?>" style="width: 100px;" class="prod-input"></td>
            </tr>
            <tr>
                <td>Goods</td>
                <td><?= number_format($planet['prod_goods'], 1) ?>%</td>
                <td><input type="number" name="prod_goods" min="0" max="100" step="0.1" value="<?= number_format($planet['prod_goods'], 1) ?>" style="width: 100px;" class="prod-input"></td>
            </tr>
            <tr>
                <td>Energy</td>
                <td><?= number_format($planet['prod_energy'], 1) ?>%</td>
                <td><input type="number" name="prod_energy" min="0" max="100" step="0.1" value="<?= number_format($planet['prod_energy'], 1) ?>" style="width: 100px;" class="prod-input"></td>
            </tr>
            <tr>
                <td>Fighters</td>
                <td><?= number_format($planet['prod_fighters'], 1) ?>%</td>
                <td><input type="number" name="prod_fighters" min="0" max="100" step="0.1" value="<?= number_format($planet['prod_fighters'], 1) ?>" style="width: 100px;" class="prod-input"></td>
            </tr>
            <tr>
                <td>Torpedoes</td>
                <td><?= number_format($planet['prod_torp'], 1) ?>%</td>
                <td><input type="number" name="prod_torp" min="0" max="100" step="0.1" value="<?= number_format($planet['prod_torp'], 1) ?>" style="width: 100px;" class="prod-input"></td>
            </tr>
        </table>

        <div style="margin-top: 15px;">
            <button type="submit" class="btn">Update Production</button>
        </div>
    </form>
</div>

<!-- Base Construction -->
<?php if (!$planet['base']): ?>
<h3>Build Planetary Base</h3>
<div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; margin-bottom: 30px;">
    <p style="margin-bottom: 15px;">
        A planetary base increases production and allows ownership of the sector.
        Building a base requires significant resources and planet must have at least 100 colonists.
    </p>

    <table style="margin-bottom: 15px;">
        <tr>
            <th>Requirement</th>
            <th>Needed</th>
            <th>Available</th>
            <th>Status</th>
        </tr>
        <tr>
            <td>Colonists</td>
            <td>100</td>
            <td><?= number_format($planet['colonists']) ?></td>
            <td style="color: <?= $planet['colonists'] >= 100 ? '#2ecc71' : '#e74c3c' ?>;">
                <?= $planet['colonists'] >= 100 ? '✓' : '✗' ?>
            </td>
        </tr>
        <tr>
            <td>Credits</td>
            <td><?= number_format($config['game']['base_credits']) ?></td>
            <td><?= number_format($planet['credits']) ?></td>
            <td style="color: <?= $planet['credits'] >= $config['game']['base_credits'] ? '#2ecc71' : '#e74c3c' ?>;">
                <?= $planet['credits'] >= $config['game']['base_credits'] ? '✓' : '✗' ?>
            </td>
        </tr>
        <tr>
            <td>Ore</td>
            <td><?= number_format($config['game']['base_ore']) ?></td>
            <td><?= number_format($planet['ore']) ?></td>
            <td style="color: <?= $planet['ore'] >= $config['game']['base_ore'] ? '#2ecc71' : '#e74c3c' ?>;">
                <?= $planet['ore'] >= $config['game']['base_ore'] ? '✓' : '✗' ?>
            </td>
        </tr>
        <tr>
            <td>Organics</td>
            <td><?= number_format($config['game']['base_organics']) ?></td>
            <td><?= number_format($planet['organics']) ?></td>
            <td style="color: <?= $planet['organics'] >= $config['game']['base_organics'] ? '#2ecc71' : '#e74c3c' ?>;">
                <?= $planet['organics'] >= $config['game']['base_organics'] ? '✓' : '✗' ?>
            </td>
        </tr>
        <tr>
            <td>Goods</td>
            <td><?= number_format($config['game']['base_goods']) ?></td>
            <td><?= number_format($planet['goods']) ?></td>
            <td style="color: <?= $planet['goods'] >= $config['game']['base_goods'] ? '#2ecc71' : '#e74c3c' ?>;">
                <?= $planet['goods'] >= $config['game']['base_goods'] ? '✓' : '✗' ?>
            </td>
        </tr>
    </table>

    <?php
    $canBuild = $planet['colonists'] >= 100 &&
                $planet['credits'] >= $config['game']['base_credits'] &&
                $planet['ore'] >= $config['game']['base_ore'] &&
                $planet['organics'] >= $config['game']['base_organics'] &&
                $planet['goods'] >= $config['game']['base_goods'];
    ?>

    <?php if ($canBuild): ?>
    <form action="/planet/base/<?= (int)$planet['planet_id'] ?>" method="post"
          onsubmit="return confirm('Build planetary base for <?= number_format($config['game']['base_credits']) ?> credits?');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn">Build Base</button>
    </form>
    <?php else: ?>
    <p style="color: #e74c3c;">Insufficient resources to build base.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="alert alert-success">
    <strong>✓ Planetary Base Active</strong> - This planet has a base and claims ownership of Sector <?= (int)$planet['sector_id'] ?>.
</div>
<?php endif; ?>

<?php endif; // end onSurface check ?>

<div style="margin-top: 30px;">
    <a href="/planets" class="btn">View All Planets</a>
    <a href="/main" class="btn">Back to Main</a>
</div>

<script>
// Calculate total production percentage
document.querySelectorAll('.prod-input').forEach(input => {
    input.addEventListener('input', () => {
        let total = 0;
        document.querySelectorAll('.prod-input').forEach(i => {
            total += parseFloat(i.value) || 0;
        });
        const totalSpan = document.getElementById('total-production');
        totalSpan.textContent = total.toFixed(1);
        totalSpan.style.color = Math.abs(total - 100) < 0.1 ? '#2ecc71' : '#e74c3c';
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
