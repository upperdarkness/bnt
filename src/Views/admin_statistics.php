<?php
$title = 'Statistics - Admin - BlackNova Traders';
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

<h2 style="color: #e74c3c;">🛡️ Game Statistics</h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe">Universe</a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics"><strong>Statistics</strong></a>
</div>

<h3>Player Statistics</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Players</div>
        <div class="stat-value"><?= number_format($stats['players']['total']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Today</div>
        <div class="stat-value" style="color: #2ecc71;"><?= number_format($stats['players']['active_today']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active This Week</div>
        <div class="stat-value" style="color: #3498db;"><?= number_format($stats['players']['active_week']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Destroyed Ships</div>
        <div class="stat-value" style="color: #e74c3c;"><?= number_format($stats['players']['destroyed']) ?></div>
    </div>
</div>

<h3 style="margin-top: 30px;">Economy Statistics</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Credits in Game</div>
        <div class="stat-value"><?= number_format($stats['economy']['total_credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Average Credits per Player</div>
        <div class="stat-value"><?= number_format($stats['economy']['avg_credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Richest Player</div>
        <div class="stat-value" style="color: #f39c12;"><?= number_format($stats['economy']['richest']) ?></div>
    </div>
</div>

<h3 style="margin-top: 30px;">Military Statistics</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Fighters (Ships)</div>
        <div class="stat-value"><?= number_format($stats['military']['total_fighters']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Deployed Defenses</div>
        <div class="stat-value"><?= number_format($stats['military']['total_defenses']) ?></div>
    </div>
</div>

<h3 style="margin-top: 30px;">Territory Statistics</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Planets</div>
        <div class="stat-value"><?= number_format($stats['planets']['total']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Claimed Planets</div>
        <div class="stat-value" style="color: #3498db;"><?= number_format($stats['planets']['claimed']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Planets with Bases</div>
        <div class="stat-value" style="color: #2ecc71;"><?= number_format($stats['planets']['with_bases']) ?></div>
    </div>
</div>

<h3 style="margin-top: 30px;">Team Statistics</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Teams</div>
        <div class="stat-value"><?= number_format($stats['teams']['total']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Average Team Size</div>
        <div class="stat-value"><?= number_format($stats['teams']['avg_members'], 1) ?></div>
    </div>
</div>

<h3 style="margin-top: 30px;">Top 10 Players by Score</h3>
<table>
    <thead>
        <tr>
            <th>Rank</th>
            <th>Player</th>
            <th>Score</th>
            <th>Credits</th>
            <th>Fighters</th>
        </tr>
    </thead>
    <tbody>
        <?php $rank = 1; ?>
        <?php foreach ($topPlayers as $player): ?>
        <tr>
            <td>
                <?php if ($rank == 1): ?>
                    <span style="color: #f39c12;">🥇 <?= $rank ?></span>
                <?php elseif ($rank == 2): ?>
                    <span style="color: #95a5a6;">🥈 <?= $rank ?></span>
                <?php elseif ($rank == 3): ?>
                    <span style="color: #cd7f32;">🥉 <?= $rank ?></span>
                <?php else: ?>
                    <?= $rank ?>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($player['character_name']) ?></td>
            <td><strong><?= number_format($player['score']) ?></strong></td>
            <td><?= number_format($player['credits']) ?></td>
            <td><?= number_format($player['ship_fighters']) ?></td>
        </tr>
        <?php $rank++; ?>
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
