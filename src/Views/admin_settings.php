<?php
$title = 'Settings - Admin - BlackNova Traders';
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

<h2 style="color: #e74c3c;">🛡️ Game Settings</h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings"><strong>Settings</strong></a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
</div>

<div class="alert alert-info">
    Game settings are configured in <code>config/config.php</code>. Edit that file to change game parameters.
</div>

<h3>Current Game Configuration</h3>
<table>
    <tr>
        <th>Setting</th>
        <th>Value</th>
    </tr>
    <tr>
        <td>Game Name</td>
        <td><?= htmlspecialchars($config['game']['name']) ?></td>
    </tr>
    <tr>
        <td>Version</td>
        <td><?= htmlspecialchars($config['game']['version']) ?></td>
    </tr>
    <tr>
        <td>Max Turns</td>
        <td><?= number_format($config['game']['max_turns']) ?></td>
    </tr>
    <tr>
        <td>Start Turns</td>
        <td><?= number_format($config['game']['start_turns']) ?></td>
    </tr>
    <tr>
        <td>Start Credits</td>
        <td><?= number_format($config['game']['start_credits']) ?></td>
    </tr>
    <tr>
        <td>Universe Size (Max Sectors)</td>
        <td><?= number_format($config['game']['sector_max']) ?></td>
    </tr>
    <tr>
        <td>Session Lifetime</td>
        <td><?= $config['security']['session_lifetime'] ?> seconds (<?= round($config['security']['session_lifetime'] / 3600, 1) ?> hours)</td>
    </tr>
</table>

<h3 style="margin-top: 30px;">Scheduler Configuration</h3>
<table>
    <tr>
        <th>Task</th>
        <th>Interval (minutes)</th>
    </tr>
    <tr>
        <td>Turn Generation</td>
        <td><?= $config['scheduler']['turns'] ?></td>
    </tr>
    <tr>
        <td>Port Production</td>
        <td><?= $config['scheduler']['ports'] ?></td>
    </tr>
    <tr>
        <td>Planet Production</td>
        <td><?= $config['scheduler']['planets'] ?></td>
    </tr>
    <tr>
        <td>IGB Interest</td>
        <td><?= $config['scheduler']['igb'] ?></td>
    </tr>
    <tr>
        <td>Rankings Update</td>
        <td><?= $config['scheduler']['ranking'] ?></td>
    </tr>
</table>

<div style="margin-top: 30px;">
    <a href="/admin" class="btn">Back to Dashboard</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
