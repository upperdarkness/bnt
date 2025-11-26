<?php
$title = 'Manage Teams - Admin - BlackNova Traders';
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

<h2 style="color: #e74c3c;">🛡️ Team Management</h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams"><strong>Teams</strong></a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
</div>

<p style="color: #7f8c8d; margin-bottom: 20px;">
    Total Teams: <?= count($teams) ?>
</p>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Team Name</th>
            <th>Founder</th>
            <th>Members</th>
            <th>Created</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($teams as $team): ?>
        <tr>
            <td><?= (int)$team['team_id'] ?></td>
            <td><strong><?= htmlspecialchars($team['team_name']) ?></strong></td>
            <td><?= htmlspecialchars($team['founder_name']) ?></td>
            <td><?= (int)$team['member_count'] ?></td>
            <td><?= date('Y-m-d', strtotime($team['created_date'])) ?></td>
            <td>
                <?php if (!empty($team['description'])): ?>
                    <?= htmlspecialchars(substr($team['description'], 0, 50)) ?>
                    <?= strlen($team['description']) > 50 ? '...' : '' ?>
                <?php else: ?>
                    <span style="color: #7f8c8d;">-</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="/teams/<?= (int)$team['team_id'] ?>" class="btn" style="padding: 5px 10px;">
                    View
                </a>
                <form action="/admin/teams/<?= (int)$team['team_id'] ?>/delete" method="post" style="display: inline;"
                      onsubmit="return confirm('Delete team <?= htmlspecialchars($team['team_name']) ?>? All members will be removed!');">
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

<?php if (empty($teams)): ?>
<div style="text-align: center; padding: 40px;">
    <p style="color: #7f8c8d; font-size: 18px;">No teams exist yet.</p>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/admin" class="btn">Back to Dashboard</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
