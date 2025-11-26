<?php
$title = 'My Planets - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>My Planets</h2>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Planets</div>
        <div class="stat-value"><?= count($planets) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Colonists</div>
        <div class="stat-value"><?= number_format($totals['colonists']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Fighters</div>
        <div class="stat-value"><?= number_format($totals['fighters']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Planets with Bases</div>
        <div class="stat-value"><?= number_format($totals['bases']) ?></div>
    </div>
</div>

<div style="margin-top: 30px;">
    <h3>Resource Overview</h3>
    <table>
        <tr>
            <th>Resource</th>
            <th>Total</th>
        </tr>
        <tr>
            <td>Credits</td>
            <td><?= number_format($totals['credits']) ?></td>
        </tr>
        <tr>
            <td>Ore</td>
            <td><?= number_format($totals['ore']) ?></td>
        </tr>
        <tr>
            <td>Organics</td>
            <td><?= number_format($totals['organics']) ?></td>
        </tr>
        <tr>
            <td>Goods</td>
            <td><?= number_format($totals['goods']) ?></td>
        </tr>
        <tr>
            <td>Energy</td>
            <td><?= number_format($totals['energy']) ?></td>
        </tr>
    </table>
</div>

<?php if (empty($planets)): ?>
<div style="margin-top: 30px; text-align: center; padding: 40px;">
    <p style="color: #7f8c8d; font-size: 18px;">You don't own any planets yet.</p>
    <p style="color: #7f8c8d;">Explore the universe to find unclaimed planets or conquer existing ones!</p>
</div>
<?php else: ?>
<div style="margin-top: 30px;">
    <h3>Planet List</h3>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Sector</th>
                <th>Colonists</th>
                <th>Fighters</th>
                <th>Ore</th>
                <th>Organics</th>
                <th>Goods</th>
                <th>Base</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($planets as $planet): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($planet['planet_name']) ?></strong>
                    <?php if ($planet['sector_id'] == $ship['sector']): ?>
                        <span style="color: #2ecc71; font-size: 12px;">(Current Sector)</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/main?sector=<?= (int)$planet['sector_id'] ?>" style="color: #3498db;">
                        <?= (int)$planet['sector_id'] ?>
                    </a>
                </td>
                <td><?= number_format($planet['colonists']) ?></td>
                <td><?= number_format($planet['fighters']) ?></td>
                <td><?= number_format($planet['ore']) ?></td>
                <td><?= number_format($planet['organics']) ?></td>
                <td><?= number_format($planet['goods']) ?></td>
                <td style="text-align: center;">
                    <?php if ($planet['base']): ?>
                        <span style="color: #2ecc71; font-size: 18px;">✓</span>
                    <?php else: ?>
                        <span style="color: #7f8c8d; font-size: 18px;">✗</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/planet/<?= (int)$planet['planet_id'] ?>" class="btn" style="padding: 5px 10px; display: inline-block; margin: 2px;">
                        View
                    </a>
                    <a href="/planet/manage/<?= (int)$planet['planet_id'] ?>" class="btn" style="padding: 5px 10px; display: inline-block; margin: 2px; background: rgba(52, 152, 219, 0.3); border-color: #3498db;">
                        Manage
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top: 30px; background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
    <h3>Planet Management Tips</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <div>
            <h4>Colonization</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Transfer 100+ colonists to claim planets</li>
                <li>Colonists enable production</li>
                <li>More colonists = faster production</li>
                <li>Must be on planet surface to manage</li>
            </ul>
        </div>
        <div>
            <h4>Production</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Set production percentages (must = 100%)</li>
                <li>Ore, organics, goods for trading</li>
                <li>Energy powers your production</li>
                <li>Fighters and torpedoes for defense</li>
            </ul>
        </div>
        <div>
            <h4>Bases</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Bases claim sector ownership</li>
                <li>Cost: 10M credits + resources</li>
                <li>Requires 100+ colonists</li>
                <li>Increases production efficiency</li>
                <li>Protects your territory</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
    <a href="/scan" class="btn">Scan for Planets</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
