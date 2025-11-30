<?php
$title = 'Port - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Port - Sector <?= (int)$ship['sector'] ?></h2>

<div class="alert alert-info">
    Port Type: <strong><?= htmlspecialchars(ucfirst($portType)) ?></strong>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Credits</div>
        <div class="stat-value"><?= number_format($ship['credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Cargo Space</div>
        <div class="stat-value"><?= number_format($usedHolds) ?> / <?= number_format($maxHolds) ?></div>
        <div class="stat-label" style="margin-top: 5px; font-size: 11px; color: #bbb;">
            Ore: <?= number_format($ship['ship_ore']) ?> | 
            Org: <?= number_format($ship['ship_organics']) ?> | 
            Goods: <?= number_format($ship['ship_goods']) ?> | 
            Energy: <?= number_format($ship['ship_energy']) ?>
            <?php if ($ship['ship_colonists'] > 0): ?>
            | Colonists: <?= number_format($ship['ship_colonists']) ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<h3>Trading</h3>

<table>
    <thead>
        <tr>
            <th>Commodity</th>
            <th>Port Stock</th>
            <th>Your Stock</th>
            <th>Buy Price</th>
            <th>Sell Price</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach (['ore', 'organics', 'goods', 'energy'] as $commodity): ?>
        <tr>
            <td style="text-transform: capitalize;"><?= htmlspecialchars($commodity) ?></td>
            <td><?= number_format($prices[$commodity]['stock']) ?></td>
            <td><?= number_format($ship["ship_$commodity"]) ?></td>
            <td>
                <?php if ($prices[$commodity]['canSell']): ?>
                    <?= number_format($prices[$commodity]['buy']) ?> cr
                <?php else: ?>
                    <span style="color: #888;">N/A</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($prices[$commodity]['canBuy']): ?>
                    <?= number_format($prices[$commodity]['sell']) ?> cr
                <?php else: ?>
                    <span style="color: #888;">N/A</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($prices[$commodity]['canSell']): ?>
                <form action="/port/trade" method="post" style="display: inline-block; margin-right: 10px;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="buy">
                    <input type="hidden" name="commodity" value="<?= htmlspecialchars($commodity) ?>">
                    <input type="number" name="amount" min="1" max="10000" value="100" style="width: 80px; display: inline-block;">
                    <button type="submit" class="btn">Buy</button>
                </form>
                <?php endif; ?>

                <?php if ($prices[$commodity]['canBuy']): ?>
                <form action="/port/trade" method="post" style="display: inline-block;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <input type="hidden" name="action" value="sell">
                    <input type="hidden" name="commodity" value="<?= htmlspecialchars($commodity) ?>">
                    <input type="number" name="amount" min="1" max="<?= (int)$ship["ship_$commodity"] ?>" value="<?= (int)$ship["ship_$commodity"] ?>" style="width: 80px; display: inline-block;">
                    <button type="submit" class="btn">Sell</button>
                </form>
                <?php endif; ?>

                <?php if (!$prices[$commodity]['canBuy'] && !$prices[$commodity]['canSell']): ?>
                    <span style="color: #888; font-size: 12px;">Not available</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top: 30px;">
    <h3>Quick Trade</h3>
    <p>Enter the amount and click Buy or Sell to trade.</p>

    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; margin-top: 15px;">
        <h4>Trade Formula</h4>
        <p>Prices fluctuate based on port inventory. Buy low, sell high!</p>
        <ul>
            <li>Port sells expensive when stock is low</li>
            <li>Port buys cheap when stock is high</li>
            <li>Find profitable trade routes between ports</li>
        </ul>
    </div>
</div>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Exit Port</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
