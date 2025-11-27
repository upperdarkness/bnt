<?php
$title = 'Character Skills';
$showHeader = true;
ob_start();
?>

<h2>Character Skills</h2>

<div style="background: #2a2a2a; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
    <h3 style="margin-top: 0;">Available Skill Points: <span style="color: #4CAF50;"><?= $skills['points'] ?></span></h3>
    <p style="margin-bottom: 0; color: #bbb;">
        Earn skill points through gameplay and allocate them to improve your character.
        Higher skill levels cost more points to upgrade.
    </p>
</div>

<?php foreach ($skillDetails as $type => $skill): ?>
<div style="background: #1a1a1a; padding: 20px; margin-bottom: 20px; border-radius: 5px; border-left: 4px solid #4CAF50;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0; color: #4CAF50;"><?= htmlspecialchars($skill['name']) ?></h3>
        <div style="text-align: right;">
            <div style="font-size: 24px; font-weight: bold; color: #4CAF50;">
                Level <?= $skill['level'] ?>
            </div>
            <div style="font-size: 14px; color: #bbb;">
                <?php if ($skill['level'] < 100): ?>
                    Max Level: 100
                <?php else: ?>
                    MAX LEVEL REACHED
                <?php endif; ?>
            </div>
        </div>
    </div>

    <p style="color: #bbb; margin-bottom: 15px;">
        <?= htmlspecialchars($skill['description']) ?>
    </p>

    <div style="background: #2a2a2a; padding: 10px; border-radius: 3px; margin-bottom: 15px;">
        <strong>Current Bonus:</strong>
        <span style="color: #4CAF50; font-size: 18px;"><?= htmlspecialchars($skill['bonus_text']) ?></span>
    </div>

    <?php if ($skill['level'] < 100): ?>
    <div style="background: #2a2a2a; padding: 15px; border-radius: 3px;">
        <form method="post" action="/skills/allocate" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="csrf_token" value="<?= $session->getCsrfToken() ?>">
            <input type="hidden" name="skill" value="<?= $type ?>">

            <label style="margin: 0;">
                Allocate Points:
                <input type="number" name="points" value="1" min="1" max="<?= min($skills['points'], 100 - $skill['level']) ?>"
                       style="width: 80px; margin-left: 5px; padding: 5px;">
            </label>

            <div style="color: #bbb; font-size: 14px;">
                Next level costs: <strong style="color: #4CAF50;"><?= $skill['next_cost'] ?> points</strong>
            </div>

            <?php if ($skills['points'] >= $skill['next_cost']): ?>
            <button type="submit"
                    style="background: #4CAF50; color: white; border: none; padding: 8px 20px; border-radius: 3px; cursor: pointer; font-weight: bold;">
                Upgrade
            </button>
            <?php else: ?>
            <button type="button" disabled
                    style="background: #555; color: #999; border: none; padding: 8px 20px; border-radius: 3px; cursor: not-allowed;">
                Not Enough Points
            </button>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <!-- Progress bar -->
    <div style="margin-top: 15px;">
        <div style="background: #2a2a2a; height: 20px; border-radius: 10px; overflow: hidden;">
            <div style="background: linear-gradient(90deg, #4CAF50, #45a049); height: 100%; width: <?= $skill['level'] ?>%; transition: width 0.3s;"></div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div style="background: #1a1a1a; padding: 20px; border-radius: 5px; border-left: 4px solid #2196F3;">
    <h3 style="margin-top: 0; color: #2196F3;">How to Earn Skill Points</h3>
    <ul style="color: #bbb; line-height: 1.8;">
        <li><strong>Trading:</strong> Earn 1 point per 50,000 credits traded</li>
        <li><strong>Combat Victory:</strong> Earn 2-5 points for destroying enemy ships</li>
        <li><strong>Planet Capture:</strong> Earn 3 points for capturing planets</li>
        <li><strong>Upgrades:</strong> Earn 1 point per 5 component upgrades</li>
        <li><strong>Exploration:</strong> Earn 1 point per 100 sectors visited</li>
    </ul>
</div>

<div style="margin-top: 20px;">
    <a href="/main" style="color: #4CAF50; text-decoration: none;">« Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
