<?php
$title = $title ?? 'Rumours - Admin';
$showHeader = false;
$heading = 'Rumours';
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$csrf = htmlspecialchars($session->getCsrfToken());
$labels = ['rumours.enabled' => 'Rumours', 'rumours.require_line_approval' => 'Manual approval of new LLM lines'];
$bought = array_sum(array_map('intval', $split)) ?: 0;
?>
<p style="margin-bottom: 15px;">
<?php foreach ($switches as $switchKey => $switchOn): $switchLabel = $labels[$switchKey]; include __DIR__ . '/admin_switch.php'; endforeach; ?>
</p>

<h3>Truth split of rumours actually sold</h3>
<p style="color:#95a5a6;">Configured <?= htmlspecialchars(json_encode($config['rumours']['truth_split'])) ?> (true / stale / false), adjusted by place and tier.
    Sold so far:
    <?php foreach (['true', 'stale', 'false'] as $st): ?>
        <?= $st ?> <?= (int)($split[$st] ?? 0) ?><?= $bought ? ' (' . round(100 * ($split[$st] ?? 0) / $bought) . '%)' : '' ?>&nbsp;
    <?php endforeach; ?>
</p>

<h3>Flavour-line pool</h3>
<table style="margin-bottom: 20px;">
    <thead><tr><th>Type</th><th>Approved</th><th>Pending</th><th>Still needed</th></tr></thead>
    <tbody>
    <?php foreach ($pool as $type => $p): ?>
        <tr><td><?= htmlspecialchars($type) ?></td><td><?= (int)$p['approved'] ?></td><td><?= (int)$p['pending'] ?></td><td><?= (int)$p['needed'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<table>
    <thead><tr><th>Type</th><th>Line</th><th>Uses</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['type']) ?></td>
            <td><?= htmlspecialchars($l['template']) ?></td>
            <td><?= (int)$l['uses'] ?></td>
            <td><?= $l['approved'] ? 'approved' : '<strong>pending</strong>' ?></td>
            <td style="white-space:nowrap;">
                <?php if (!$l['approved']): ?>
                <form method="POST" action="/admin/rumours/lines/<?= (int)$l['id'] ?>/approve" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><button class="btn" type="submit">Approve</button>
                </form>
                <?php endif; ?>
                <form method="POST" action="/admin/rumours/lines/<?= (int)$l['id'] ?>/retire" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><button class="btn" type="submit">Retire</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3 style="margin-top:25px;">Seed inspector</h3>
<p style="color:#95a5a6;">What buyers are told next to what was actually true, and who bought each.</p>
<table>
    <thead><tr><th>Type</th><th>State</th><th>Shown</th><th>Actually true</th><th>Expires</th><th>Buyers</th></tr></thead>
    <tbody>
    <?php foreach ($seeds as $s): ?>
        <tr style="<?= $s['expired'] ? 'opacity:.55;' : '' ?>">
            <td><?= htmlspecialchars($s['type']) ?></td>
            <td style="color:<?= ['true' => '#2ecc71', 'stale' => '#f39c12', 'false' => '#e74c3c'][$s['truth_state']] ?>;"><?= htmlspecialchars($s['truth_state']) ?></td>
            <td><code><?= htmlspecialchars((string)$s['shown_facts']) ?></code></td>
            <td><code><?= htmlspecialchars(mb_substr((string)$s['true_facts'], 0, 200)) ?></code></td>
            <td><?= htmlspecialchars(date('M j H:i', strtotime((string)$s['expires_at']))) ?></td>
            <td><?= (int)$s['buyers'] ?><?php foreach ($buyers[$s['id']] as $b): ?><br><small><?= htmlspecialchars($b['character_name']) ?> (<?= htmlspecialchars($b['tier']) ?>, sector <?= (int)$b['port_sector'] ?>)</small><?php endforeach; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
