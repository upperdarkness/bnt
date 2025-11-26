<?php
$title = 'Combat - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Combat - Sector <?= (int)$ship['sector'] ?></h2>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Turns</div>
        <div class="stat-value"><?= number_format($ship['turns']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Fighters</div>
        <div class="stat-value"><?= number_format($ship['ship_fighters']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Torpedoes</div>
        <div class="stat-value"><?= number_format($ship['torps']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Armor</div>
        <div class="stat-value"><?= number_format($ship['armor_pts']) ?>%</div>
    </div>
</div>

<div class="alert alert-info">
    <strong>Combat System:</strong> Attack ships, planets, or deploy defensive mines and fighters.
    Combat consumes turns and resources. Be strategic!
</div>

<?php if (!empty($shipsInSector)): ?>
<div style="margin-top: 30px;">
    <h3>Ships in Sector</h3>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Score</th>
                <th>Team</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($shipsInSector as $otherShip): ?>
            <tr>
                <td><?= htmlspecialchars($otherShip['character_name']) ?></td>
                <td><?= number_format($otherShip['score']) ?></td>
                <td>
                    <?php if ($otherShip['team'] == 0): ?>
                        <span style="color: #7f8c8d;">None</span>
                    <?php elseif ($ship['team'] != 0 && $otherShip['team'] == $ship['team']): ?>
                        <span style="color: #2ecc71;">Team #<?= $otherShip['team'] ?> (Ally)</span>
                    <?php else: ?>
                        Team #<?= $otherShip['team'] ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($ship['team'] == 0 || $otherShip['team'] != $ship['team']): ?>
                    <form action="/combat/attack/ship/<?= (int)$otherShip['ship_id'] ?>" method="post" style="display: inline;"
                          onsubmit="return confirm('Are you sure you want to attack <?= htmlspecialchars($otherShip['character_name']) ?>?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                            Attack
                        </button>
                    </form>
                    <?php else: ?>
                    <span style="color: #7f8c8d;">Allied</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div style="margin-top: 30px;">
    <p style="color: #7f8c8d;">No other ships in this sector.</p>
</div>
<?php endif; ?>

<?php if (!empty($planets)): ?>
<div style="margin-top: 30px;">
    <h3>Planets in Sector</h3>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Owner</th>
                <th>Fighters</th>
                <th>Base</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($planets as $planet): ?>
            <tr>
                <td><?= htmlspecialchars($planet['planet_name']) ?></td>
                <td><?= $planet['owner'] ? htmlspecialchars($planet['owner_name']) : 'Unowned' ?></td>
                <td><?= number_format($planet['fighters']) ?></td>
                <td><?= $planet['base'] ? '<span style="color: #e74c3c;">Yes</span>' : 'No' ?></td>
                <td>
                    <?php if ($planet['owner'] != 0 && $planet['owner'] != $ship['ship_id']): ?>
                    <form action="/combat/attack/planet/<?= (int)$planet['planet_id'] ?>" method="post" style="display: inline;"
                          onsubmit="return confirm('Are you sure you want to attack this planet? Costs 5 turns.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                            Attack (5 turns)
                        </button>
                    </form>
                    <?php elseif ($planet['owner'] == $ship['ship_id']): ?>
                    <span style="color: #2ecc71;">Your planet</span>
                    <?php else: ?>
                    <span style="color: #7f8c8d;">Unowned</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <h3>Deploy Sector Defenses</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <!-- Deploy Fighters -->
        <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
            <h4>Deploy Fighters</h4>
            <p>Fighters will attack enemy ships entering this sector.</p>
            <form action="/combat/deploy" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="defense_type" value="F">
                <label>Quantity:</label>
                <input type="number" name="quantity" min="1" max="<?= (int)$ship['ship_fighters'] ?>" value="10">
                <br><br>
                <button type="submit" class="btn">Deploy Fighters</button>
            </form>
        </div>

        <!-- Deploy Mines -->
        <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
            <h4>Deploy Mines</h4>
            <p>Mines damage ships with hull size 8+. Uses torpedoes.</p>
            <form action="/combat/deploy" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="defense_type" value="M">
                <label>Quantity:</label>
                <input type="number" name="quantity" min="1" max="<?= (int)$ship['torps'] ?>" value="5">
                <br><br>
                <button type="submit" class="btn">Deploy Mines</button>
            </form>
        </div>
    </div>
</div>

<?php if (!empty($defenses)): ?>
<div style="margin-top: 30px;">
    <h3>Sector Defenses</h3>
    <table>
        <thead>
            <tr>
                <th>Owner</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Team</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($defenses as $defense): ?>
            <tr>
                <td><?= htmlspecialchars($defense['character_name']) ?></td>
                <td><?= $defense['defence_type'] === 'F' ? 'Fighters' : 'Mines' ?></td>
                <td><?= number_format($defense['quantity']) ?></td>
                <td>
                    <?php if ($defense['team'] == 0): ?>
                        None
                    <?php elseif ($ship['team'] != 0 && $defense['team'] == $ship['team']): ?>
                        <span style="color: #2ecc71;">Team #<?= $defense['team'] ?> (Ally)</span>
                    <?php else: ?>
                        Team #<?= $defense['team'] ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin-top: 30px; background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
    <h3>Combat Information</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
        <div>
            <h4>Your Offensive Power</h4>
            <ul style="margin-left: 20px;">
                <li>Beams Level: <?= (int)$ship['beams'] ?></li>
                <li>Torpedoes: <?= number_format($ship['torps']) ?></li>
                <li>Fighters: <?= number_format($ship['ship_fighters']) ?></li>
            </ul>
        </div>
        <div>
            <h4>Your Defensive Power</h4>
            <ul style="margin-left: 20px;">
                <li>Shields Level: <?= (int)$ship['shields'] ?></li>
                <li>Armor Level: <?= (int)$ship['armor'] ?></li>
                <li>Armor Points: <?= (int)$ship['armor_pts'] ?>%</li>
                <li>Cloak Level: <?= (int)$ship['cloak'] ?></li>
            </ul>
        </div>
        <div>
            <h4>Combat Tips</h4>
            <ul style="margin-left: 20px;">
                <li>Higher engines help you escape</li>
                <li>Higher cloak avoids combat</li>
                <li>Fighters are reusable</li>
                <li>Torpedoes are consumed</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
