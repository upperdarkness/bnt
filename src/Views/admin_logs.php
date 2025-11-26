<?php
$title = 'Logs - Admin - BlackNova Traders';
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

<h2 style="color: #e74c3c;">🛡️ System Logs</h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs"><strong>Logs</strong></a>
    <a href="/admin/statistics">Statistics</a>
</div>

<h3>Recent Combat Logs (Last 50)</h3>
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Player</th>
            <th>Type</th>
            <th>Data</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($combatLogs as $log): ?>
        <tr>
            <td><?= (int)$log['log_id'] ?></td>
            <td><?= htmlspecialchars($log['character_name']) ?></td>
            <td>
                <?php
                $types = [
                    3 => 'Attack',
                    7 => 'Defended',
                    13 => 'Planet Attack'
                ];
                echo $types[$log['log_type']] ?? 'Unknown';
                ?>
            </td>
            <td>
                <details>
                    <summary style="cursor: pointer; color: #3498db;">View Details</summary>
                    <pre style="background: rgba(0,0,0,0.3); padding: 10px; margin-top: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px;"><?= htmlspecialchars(json_encode(json_decode($log['log_data']), JSON_PRETTY_PRINT)) ?></pre>
                </details>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if (empty($combatLogs)): ?>
<div style="text-align: center; padding: 40px;">
    <p style="color: #7f8c8d; font-size: 18px;">No combat logs found.</p>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/admin" class="btn">Back to Dashboard</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
