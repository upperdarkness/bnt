<?php
$title = 'Admin Dashboard - BlackNova Traders';
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

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
</style>

<div class="admin-header">
    <h2 style="color: #e74c3c;">🛡️ Admin Dashboard</h2>
    <a href="/admin/logout" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
        Logout
    </a>
</div>

<div class="admin-nav">
    <a href="/admin"><strong>Dashboard</strong></a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
    <a href="/">← Game</a>
</div>

<h3>System Overview</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Players</div>
        <div class="stat-value"><?= number_format($stats['total_players']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active (7 days)</div>
        <div class="stat-value" style="color: #2ecc71;"><?= number_format($stats['active_players']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Teams</div>
        <div class="stat-value"><?= number_format($stats['total_teams']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Sectors</div>
        <div class="stat-value"><?= number_format($stats['total_sectors']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Planets</div>
        <div class="stat-value"><?= number_format($stats['total_planets']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Claimed Planets</div>
        <div class="stat-value" style="color: #3498db;"><?= number_format($stats['claimed_planets']) ?></div>
    </div>
</div>

<h3 style="margin-top: 30px;">Recent Player Activity</h3>
<table>
    <thead>
        <tr>
            <th>Player</th>
            <th>Email</th>
            <th>Last Login</th>
            <th>Score</th>
            <th>Credits</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recentPlayers as $player): ?>
        <tr>
            <td><?= htmlspecialchars($player['character_name']) ?></td>
            <td><?= htmlspecialchars($player['email']) ?></td>
            <td>
                <?php if ($player['last_login']): ?>
                    <?= date('Y-m-d H:i', strtotime($player['last_login'])) ?>
                <?php else: ?>
                    <span style="color: #7f8c8d;">Never</span>
                <?php endif; ?>
            </td>
            <td><?= number_format($player['score']) ?></td>
            <td><?= number_format($player['credits']) ?></td>
            <td>
                <a href="/admin/players/<?= (int)$player['ship_id'] ?>/edit" class="btn" style="padding: 5px 10px;">
                    Edit
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
        <h4 style="color: #3498db;">Quick Actions</h4>
        <div style="margin-top: 15px;">
            <a href="/admin/players" class="btn" style="display: block; margin-bottom: 10px;">
                Manage Players
            </a>
            <a href="/admin/teams" class="btn" style="display: block; margin-bottom: 10px;">
                Manage Teams
            </a>
            <a href="/admin/universe" class="btn" style="display: block; margin-bottom: 10px;">
                Universe Tools
            </a>
        </div>
    </div>

    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
        <h4 style="color: #3498db;">Game Information</h4>
        <table style="margin-top: 15px;">
            <tr>
                <td>Game Name:</td>
                <td><strong><?= htmlspecialchars($config['game']['name']) ?></strong></td>
            </tr>
            <tr>
                <td>Version:</td>
                <td><?= htmlspecialchars($config['game']['version']) ?></td>
            </tr>
            <tr>
                <td>Max Turns:</td>
                <td><?= number_format($config['game']['max_turns']) ?></td>
            </tr>
            <tr>
                <td>Universe Size:</td>
                <td><?= number_format($config['game']['sector_max']) ?> sectors</td>
            </tr>
        </table>
    </div>

    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
        <h4 style="color: #f39c12;">Admin Reminders</h4>
        <ul style="margin-left: 20px; color: #e0e0e0; line-height: 1.8; margin-top: 15px;">
            <li>Change default admin password</li>
            <li>Monitor player activity regularly</li>
            <li>Check logs for abuse</li>
            <li>Backup database periodically</li>
            <li>Review team balance</li>
        </ul>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
