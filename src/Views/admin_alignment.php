<?php
$title = $title ?? 'Alignment - Admin';
$showHeader = false;
$heading = 'Alignment: ' . $target['character_name'];
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$csrf = htmlspecialchars($session->getCsrfToken());
$id = (int)$target['ship_id'];
$a = (int)$target['alignment'];
?>
<p><a href="/admin/players" style="color:#3498db;">← Players</a>
   <?php if ($target['is_npc']): ?> &middot; <a href="/admin/npcs/<?= $id ?>" style="color:#3498db;">NPC page</a><?php endif; ?></p>

<div class="npc-grid">
    <div class="stat-card"><div class="stat-label">Alignment (exact)</div><div class="stat-value"><?= number_format($a) ?></div></div>
    <div class="stat-card"><div class="stat-label">Tier</div><div class="stat-value" style="color:<?= $rules->color($a) ?>;"><?= htmlspecialchars($rules->label($a)) ?></div></div>
    <div class="stat-card"><div class="stat-label">Wanted</div><div class="stat-value" style="color:<?= $wanted ? '#e74c3c' : '#2ecc71' ?>;"><?= $wanted ? 'YES' : 'No' ?></div>
        <?php if ($target['wanted_until']): ?><small>until <?= htmlspecialchars(date('M j H:i', strtotime((string)$target['wanted_until']))) ?></small><?php endif; ?></div>
    <div class="stat-card"><div class="stat-label">Open bounties</div><div class="stat-value"><?= number_format(array_sum(array_map(fn($b) => (int)$b['amount'], $bounties))) ?></div></div>
</div>

<h3>Adjust alignment</h3>
<form method="POST" action="/admin/players/<?= $id ?>/alignment" class="npc-form" style="margin-bottom: 25px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <label>Change (positive or negative)</label>
    <input type="number" name="delta" required>
    <label>Reason (mandatory, written to the alignment log)</label>
    <input type="text" name="reason" required maxlength="200">
    <p style="margin-top:12px;"><button class="btn" type="submit">Apply</button></p>
</form>

<h3>Clear Wanted</h3>
<form method="POST" action="/admin/players/<?= $id ?>/clear-wanted" class="npc-form" style="margin-bottom: 25px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <label>Reason (mandatory)</label>
    <input type="text" name="reason" required maxlength="200">
    <p style="margin-top:12px;"><button class="btn" type="submit">Clear Wanted status</button></p>
</form>

<h3>Alignment log (latest 100)</h3>
<table>
    <thead><tr><th>When</th><th>Change</th><th>New value</th><th>Reason</th><th>Related ship</th></tr></thead>
    <tbody>
    <?php foreach ($log as $row): ?>
        <tr>
            <td><?= htmlspecialchars(date('M j H:i:s', strtotime((string)$row['created_at']))) ?></td>
            <td style="color:<?= $row['delta'] >= 0 ? '#2ecc71' : '#e74c3c' ?>;"><?= $row['delta'] > 0 ? '+' : '' ?><?= (int)$row['delta'] ?></td>
            <td><?= number_format((int)$row['new_value']) ?></td>
            <td><?= htmlspecialchars((string)$row['reason']) ?></td>
            <td><?= $row['related_ship_id'] ? htmlspecialchars((string)($row['related_name'] ?? $row['related_ship_id'])) : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
