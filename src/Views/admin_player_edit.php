<?php
$title = 'Edit Player - Admin - BlackNova Traders';
$showHeader = false;
ob_start();
?>

<style>
    .admin-nav {
        background: rgba(231, 76, 60, 0.2);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid rgba(231, 76, 60, 0.5);
    }

    .admin-nav a {
        color: #e74c3c;
        margin-right: 15px;
        text-decoration: none;
        padding: 8px 15px;
        border-radius: 5px;
        transition: background 0.3s;
    }

    .admin-nav a:hover {
        background: rgba(231, 76, 60, 0.3);
    }
</style>

<h2 style="color: #e74c3c;">🛡️ Edit Player: <?= htmlspecialchars($player['character_name']) ?></h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players"><strong>Players</strong></a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings">Settings</a>
</div>

<div style="background: rgba(15, 76, 117, 0.2); padding: 30px; border-radius: 8px; max-width: 800px;">
    <form action="/admin/players/<?= (int)$player['ship_id'] ?>/update" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <h3>Player Information</h3>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Character Name:</label>
                    <input type="text"
                           name="character_name"
                           value="<?= htmlspecialchars($player['character_name']) ?>"
                           required>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Email:</label>
                    <input type="email"
                           name="email"
                           value="<?= htmlspecialchars($player['email']) ?>"
                           required>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Score:</label>
                    <input type="number"
                           name="score"
                           value="<?= (int)$player['score'] ?>">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Team ID:</label>
                    <input type="number"
                           name="team"
                           value="<?= (int)$player['team'] ?>"
                           disabled
                           style="background: rgba(127, 140, 141, 0.2);">
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        Use team management to change teams
                    </small>
                </div>
            </div>

            <div>
                <h3>Resources</h3>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Credits:</label>
                    <input type="number"
                           name="credits"
                           value="<?= (int)$player['credits'] ?>"
                           min="0">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Turns:</label>
                    <input type="number"
                           name="turns"
                           value="<?= (int)$player['turns'] ?>"
                           min="0"
                           max="<?= $config['game']['max_turns'] ?>">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Fighters:</label>
                    <input type="number"
                           name="ship_fighters"
                           value="<?= (int)$player['ship_fighters'] ?>"
                           min="0">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Energy:</label>
                    <input type="number"
                           name="ship_energy"
                           value="<?= (int)$player['ship_energy'] ?>"
                           min="0">
                </div>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(52, 152, 219, 0.2);">
            <h3>Additional Information</h3>
            <table>
                <tr>
                    <td><strong>Ship ID:</strong></td>
                    <td><?= (int)$player['ship_id'] ?></td>
                </tr>
                <tr>
                    <td><strong>Current Sector:</strong></td>
                    <td><?= (int)$player['sector'] ?></td>
                </tr>
                <tr>
                    <td><strong>Last Login:</strong></td>
                    <td>
                        <?php if ($player['last_login']): ?>
                            <?= date('Y-m-d H:i:s', strtotime($player['last_login'])) ?>
                        <?php else: ?>
                            Never
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Destroyed:</strong></td>
                    <td>
                        <?php if ($player['ship_destroyed']): ?>
                            <span style="color: #e74c3c;">Yes</span>
                        <?php else: ?>
                            <span style="color: #2ecc71;">No</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn" style="flex: 1; background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                Update Player
            </button>
            <a href="/admin/players" class="btn" style="flex: 1; text-align: center;">
                Cancel
            </a>
        </div>
    </form>
</div>

<div style="margin-top: 30px;">
    <a href="/admin/players" class="btn">Back to Players</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
