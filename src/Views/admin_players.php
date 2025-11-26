<?php
$title = 'Manage Players - Admin - BlackNova Traders';
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

<h2 style="color: #e74c3c;">🛡️ Player Management</h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players"><strong>Players</strong></a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
</div>

<div style="margin-bottom: 20px;">
    <form action="/admin/players" method="get" style="display: flex; gap: 10px;">
        <input type="text"
               name="search"
               value="<?= htmlspecialchars($search) ?>"
               placeholder="Search by name or email..."
               style="flex: 1;">
        <button type="submit" class="btn">Search</button>
        <?php if ($search): ?>
        <a href="/admin/players" class="btn">Clear</a>
        <?php endif; ?>
    </form>
</div>

<p style="color: #7f8c8d; margin-bottom: 20px;">
    Showing <?= count($players) ?> player(s)
    <?php if ($search): ?>
        matching "<?= htmlspecialchars($search) ?>"
    <?php endif; ?>
</p>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Score</th>
            <th>Credits</th>
            <th>Turns</th>
            <th>Team</th>
            <th>Last Login</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($players as $player): ?>
        <tr>
            <td><?= (int)$player['ship_id'] ?></td>
            <td><?= htmlspecialchars($player['character_name']) ?></td>
            <td><?= htmlspecialchars($player['email']) ?></td>
            <td><?= number_format($player['score']) ?></td>
            <td><?= number_format($player['credits']) ?></td>
            <td><?= number_format($player['turns']) ?></td>
            <td>
                <?php if ($player['team'] != 0): ?>
                    <span style="color: #3498db;"><?= (int)$player['team'] ?></span>
                <?php else: ?>
                    <span style="color: #7f8c8d;">-</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($player['last_login']): ?>
                    <?= date('Y-m-d H:i', strtotime($player['last_login'])) ?>
                <?php else: ?>
                    <span style="color: #7f8c8d;">Never</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($player['ship_destroyed']): ?>
                    <span style="color: #e74c3c;">Destroyed</span>
                <?php else: ?>
                    <span style="color: #2ecc71;">Active</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="/admin/players/<?= (int)$player['ship_id'] ?>/edit" class="btn" style="padding: 5px 10px;">
                    Edit
                </a>
                <form action="/admin/players/<?= (int)$player['ship_id'] ?>/delete" method="post" style="display: inline;"
                      onsubmit="return confirm('Delete player <?= htmlspecialchars($player['character_name']) ?>? This cannot be undone!');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <button type="submit" class="btn" style="padding: 5px 10px; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                        Delete
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($players)): ?>
<div style="text-align: center; padding: 40px;">
    <p style="color: #7f8c8d; font-size: 18px;">No players found.</p>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/admin" class="btn">Back to Dashboard</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
