<?php
$title = $title ?? 'News queue - Admin';
$showHeader = false;
$heading = 'News queue';
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$csrf = htmlspecialchars($session->getCsrfToken());
$labels = ['news.journalist_enabled' => 'Journalist', 'news.review_mode' => 'Review mode (approve before publishing)'];
?>
<p style="margin-bottom: 15px;">
<?php foreach ($switches as $switchKey => $switchOn): $switchLabel = $labels[$switchKey]; include __DIR__ . '/admin_switch.php'; endforeach; ?>
    <span style="color:#95a5a6;">Stories today: <?= (int)$storiesToday ?> of <?= (int)($config['news']['max_stories_per_day'] ?? 6) ?></span>
</p>

<h3>Pending review (<?= count($pending) ?>)</h3>
<?php if (!$pending): ?>
    <p style="color:#95a5a6;">Nothing waiting.</p>
<?php endif; ?>
<?php foreach ($pending as $p): $sheet = json_decode((string)($p['fact_sheet'] ?? '{}'), true) ?: []; $val = json_decode((string)($p['validator'] ?? '{}'), true) ?: []; ?>
<div style="border: 1px solid #3282b8; border-radius: 8px; padding: 14px; margin-bottom: 18px;">
    <form method="POST" action="/admin/news/<?= (int)$p['news_id'] ?>/edit" class="npc-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <label>Headline (<?= (int)$p['candidate_id'] ? 'candidate #' . (int)$p['candidate_id'] . ', score ' . htmlspecialchars((string)$p['score']) : 'digest' ?>)</label>
        <input type="text" name="headline" value="<?= htmlspecialchars($p['headline']) ?>" maxlength="200">
        <label>Story</label>
        <textarea name="body" rows="5"><?= htmlspecialchars($p['newstext']) ?></textarea>
        <p style="margin-top:8px;">
            <button class="btn" type="submit">Approve with these edits</button>
        </p>
    </form>
    <form method="POST" action="/admin/news/<?= (int)$p['news_id'] ?>/approve" style="display:inline;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button class="btn" type="submit">Approve as written</button>
    </form>
    <form method="POST" action="/admin/news/<?= (int)$p['news_id'] ?>/reject" style="display:inline; margin-left:10px;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="text" name="reason" placeholder="Reason (logged)" required>
        <button class="btn" type="submit" style="background: rgba(231,76,60,.3);">Reject</button>
    </form>
    <details style="margin-top:10px;"><summary>Fact sheet and validator result</summary>
        <pre class="json"><?= htmlspecialchars(json_encode($sheet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <pre class="json"><?= htmlspecialchars(json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>
</div>
<?php endforeach; ?>

<h3>Recent Courier items</h3>
<table>
    <thead><tr><th>When</th><th>Headline</th><th>Type</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= htmlspecialchars(date('M j H:i', strtotime((string)$r['date']))) ?></td>
            <td><?= htmlspecialchars($r['headline']) ?></td>
            <td><?= htmlspecialchars($r['news_type']) ?></td>
            <td><?= htmlspecialchars($r['status']) ?></td>
            <td>
                <?php if ($r['status'] === 'published' && $r['news_type'] !== 'retraction'): ?>
                <form method="POST" action="/admin/news/<?= (int)$r['news_id'] ?>/retract" style="display:flex; gap:6px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="text" name="reason" placeholder="Reason" required>
                    <button class="btn" type="submit">Retract</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3 style="margin-top:25px;">Candidates</h3>
<table>
    <thead><tr><th>#</th><th>Score</th><th>Status</th><th>Kind</th><th>Event</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($candidates as $c): $sheet = json_decode((string)$c['fact_sheet'], true) ?: []; ?>
        <tr>
            <td><?= (int)$c['id'] ?></td><td><?= htmlspecialchars((string)$c['score']) ?></td><td><?= htmlspecialchars($c['status']) ?></td>
            <td><?= htmlspecialchars($c['kind']) ?></td><td><?= htmlspecialchars((string)($sheet['facts']['event_type'] ?? 'digest')) ?></td>
            <td><?= htmlspecialchars(date('M j H:i', strtotime((string)$c['created_at']))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
