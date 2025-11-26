<?php
$title = 'Universe Management - Admin - BlackNova Traders';
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

<h2 style="color: #e74c3c;">🛡️ Universe Management</h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe"><strong>Universe</strong></a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
</div>

<div class="alert alert-info">
    <strong>Universe Status:</strong> <?= number_format($sectorCount) ?> sectors exist in the game universe.
</div>

<div style="background: rgba(15, 76, 117, 0.2); padding: 30px; border-radius: 8px; margin-bottom: 30px;">
    <h3 style="color: #e74c3c;">⚠️ Danger Zone: Regenerate Universe</h3>
    <p style="margin: 20px 0; color: #e0e0e0;">
        Regenerating the universe will:
    </p>
    <ul style="margin-left: 20px; color: #e0e0e0; line-height: 1.8;">
        <li>Delete all existing sectors and links</li>
        <li>Delete all planets</li>
        <li>Generate a completely new universe</li>
        <li>Players will remain but be placed in sector 1</li>
        <li><strong>This action cannot be undone!</strong></li>
    </ul>

    <div class="alert alert-warning" style="margin: 20px 0;">
        <strong>Warning:</strong> This will disrupt all active games. Only use this when starting a new game round
        or if the universe is corrupted.
    </div>

    <form action="/admin/universe/regenerate" method="post"
          onsubmit="return confirm('Are you ABSOLUTELY SURE you want to regenerate the entire universe? This will delete all sectors and planets! Type CONFIRM in the box below.') && prompt('Type CONFIRM to proceed:') === 'CONFIRM';">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.5); border-color: #e74c3c;">
            Regenerate Universe
        </button>
    </form>
</div>

<h3>Sample Sectors (First 20)</h3>
<table>
    <thead>
        <tr>
            <th>Sector ID</th>
            <th>Name</th>
            <th>Port</th>
            <th>Beacon</th>
            <th>Planets</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($sectors as $sector): ?>
        <tr>
            <td><?= (int)$sector['sector_id'] ?></td>
            <td><?= htmlspecialchars($sector['sector_name']) ?></td>
            <td>
                <?php if ($sector['port_type']): ?>
                    <span style="color: #2ecc71;">Port <?= htmlspecialchars($sector['port_type']) ?></span>
                <?php else: ?>
                    <span style="color: #7f8c8d;">-</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($sector['beacon'])): ?>
                    <?= htmlspecialchars(substr($sector['beacon'], 0, 30)) ?>
                <?php else: ?>
                    <span style="color: #7f8c8d;">-</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $planetCount = $sector['planet_id'] ? 1 : 0;
                echo $planetCount > 0 ? "<span style='color: #3498db;'>$planetCount</span>" : '-';
                ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top: 30px;">
    <a href="/admin" class="btn">Back to Dashboard</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
