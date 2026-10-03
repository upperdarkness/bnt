<?php
$title = $title ?? 'Protection - Admin';
$showHeader = false;
$heading = 'Newbie protection';
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$csrf = htmlspecialchars($session->getCsrfToken());
$labels = ['protection.enabled' => 'Newbie protection'];
?>
<p style="margin-bottom: 15px;">
<?php foreach ($switches as $switchKey => $switchOn): $switchLabel = $labels[$switchKey]; include __DIR__ . '/admin_switch.php'; endforeach; ?>
    <span style="color:#95a5a6;">Current score threshold to leave protection: <strong><?= number_format($threshold) ?></strong></span>
</p>

<h3>Protected and shielded players (<?= count($rows) ?>)</h3>
<table>
    <thead><tr><th>Player</th><th>State</th><th>Progress</th><th>Created</th><th>Signup IP</th><th>Actions (reason required)</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $p = $rules->progress($r, $threshold); ?>
        <tr>
            <td><a style="color:#3498db;" href="/admin/players/<?= (int)$r['ship_id'] ?>/alignment"><?= htmlspecialchars($r['character_name']) ?></a></td>
            <td><?= htmlspecialchars($r['protection_state']) ?></td>
            <td><?= htmlspecialchars($p['text']) ?></td>
            <td><?= htmlspecialchars(date('M j', strtotime((string)$r['created_at']))) ?></td>
            <td><?= htmlspecialchars((string)$r['signup_ip']) ?></td>
            <td>
                <form method="POST" action="/admin/protection/<?= (int)$r['ship_id'] ?>/end" style="display:flex; gap:6px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="text" name="reason" placeholder="Reason" required>
                    <button class="btn" type="submit">End</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3 style="margin-top:25px;">Grant protection</h3>
<form method="POST" onsubmit="this.action='/admin/protection/' + this.ship.value + '/grant';" class="npc-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <label>Ship id</label><input type="number" name="ship" required>
    <label>Reason (mandatory, logged)</label><input type="text" name="reason" required>
    <p style="margin-top:10px;"><button class="btn" type="submit">Grant</button></p>
</form>

<h3 style="margin-top:25px;">Multi-account signals</h3>
<p style="color:#95a5a6;">Accounts sharing a signup IP or device. These are flags only; nothing happens automatically.</p>
<?php if (!$flags): ?>
    <p>None.</p>
<?php endif; ?>
<?php foreach ($flags as $f): ?>
<div style="margin-bottom: 12px;">
    <strong><?= htmlspecialchars($f['kind']) ?>: <?= htmlspecialchars($f['value']) ?></strong>
    <ul style="margin-left: 20px;">
    <?php foreach ($f['ships'] as $s): ?>
        <li><a style="color:#3498db;" href="/admin/players/<?= (int)$s['ship_id'] ?>/alignment"><?= htmlspecialchars($s['character_name']) ?></a>
            &middot; <?= htmlspecialchars($s['protection_state']) ?> &middot; score <?= number_format((int)$s['score']) ?> &middot; <?= htmlspecialchars(date('M j', strtotime((string)$s['created_at']))) ?></li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endforeach; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
