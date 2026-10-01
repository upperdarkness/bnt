<?php
$title = $title ?? 'NPC - Admin';
$showHeader = false;
$heading = 'NPC: ' . $npc['character_name'];
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$csrf = htmlspecialchars($session->getCsrfToken());
$persona = $npc['persona'];
$id = (int)$npc['ship_id'];
?>
<p><a href="/admin/npcs" style="color:#3498db;">← All NPCs</a></p>

<div class="npc-grid">
    <div class="stat-card"><div class="stat-label">Faction</div><div class="stat-value" style="font-size:20px;"><?= htmlspecialchars($faction['label']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Controller</div>
        <div class="stat-value" style="font-size:20px;"><?= htmlspecialchars($npc['controller']) ?></div>
        <small style="color:<?= $fallbackReason === null ? '#2ecc71' : '#e67e22' ?>;">
            <?= $fallbackReason === null ? 'LLM active' : 'running scripted (' . htmlspecialchars($fallbackReason) . ')' ?></small></div>
    <div class="stat-card"><div class="stat-label">Sector / turns</div><div class="stat-value" style="font-size:20px;"><?= (int)$npc['sector'] ?> / <?= number_format((int)$npc['turns']) ?></div></div>
    <div class="stat-card"><div class="stat-label">Status</div><div class="stat-value" style="font-size:20px;color:<?= $npc['ship_destroyed'] ? '#e74c3c' : '#2ecc71' ?>;">
        <?= $npc['ship_destroyed'] ? 'Destroyed' : 'Alive' ?></div>
        <?php if ($npc['respawn_at']): ?><small>respawns <?= htmlspecialchars(date('M j H:i', strtotime((string)$npc['respawn_at']))) ?></small><?php endif; ?></div>
    <div class="stat-card"><div class="stat-label">Spend today</div><div class="stat-value" style="font-size:20px;">$<?= number_format($spendToday, 4) ?></div><small>of $<?= number_format($budget, 2) ?></small></div>
</div>

<h3>Persona &amp; control</h3>
<form method="POST" action="/admin/npcs/<?= $id ?>/update" class="npc-form" style="margin-bottom:20px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <label>Controller</label>
    <select name="controller">
        <option value="scripted" <?= $npc['controller'] === 'scripted' ? 'selected' : '' ?>>Scripted</option>
        <option value="llm" <?= $npc['controller'] === 'llm' ? 'selected' : '' ?>>LLM (scripted fallback always available)</option>
    </select>
    <label>Model (OpenRouter id; blank = default<?= $defaultModel !== '' ? ': ' . htmlspecialchars($defaultModel) : '' ?>)</label>
    <input type="text" name="model" value="<?= htmlspecialchars((string)$npc['model']) ?>">
    <label>Fallback models (comma separated)</label>
    <input type="text" name="fallback_models" value="<?= htmlspecialchars(implode(', ', $persona['fallback_models'] ?? [])) ?>">
    <label>Temperament</label>
    <input type="text" name="temperament" value="<?= htmlspecialchars((string)($persona['temperament'] ?? '')) ?>">
    <label>Speech style</label>
    <input type="text" name="speech_style" value="<?= htmlspecialchars((string)($persona['speech_style'] ?? '')) ?>">
    <label>Goals (one per line)</label>
    <textarea name="goals" rows="4"><?= htmlspecialchars(implode("\n", $persona['goals'] ?? [])) ?></textarea>
    <p style="margin-top:12px;"><button class="btn" type="submit">Save</button></p>
</form>

<form method="POST" action="/admin/npcs/<?= $id ?>/wake" style="margin-bottom: 25px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <button class="btn" type="submit" <?= $npc['controller'] !== 'llm' ? 'disabled title="Switch the controller to LLM first"' : '' ?>>⚡ Wake now</button>
    <?php if ($pending): ?><span style="margin-left:10px;color:#95a5a6;"><?= count($pending) ?> pending event(s)</span><?php endif; ?>
</form>

<h3>Notebook</h3>
<pre class="json"><?= htmlspecialchars($npc['notebook'] !== '' ? $npc['notebook'] : '(empty)') ?></pre>

<h3 style="margin-top:25px;">Recent wakes</h3>
<?php if (!$wakes): ?>
    <p style="color:#95a5a6;">No LLM wakes recorded.</p>
<?php else: ?>
<table>
    <thead><tr><th>When</th><th>Model</th><th>Tool calls</th><th>Tokens in / out</th><th>Cost</th><th>Summary</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($wakes as $w): ?>
        <tr>
            <td><?= htmlspecialchars(date('M j H:i:s', strtotime((string)$w['started']))) ?></td>
            <td><?= htmlspecialchars((string)$w['model']) ?></td>
            <td><?= (int)$w['tool_calls'] ?></td>
            <td><?= number_format((int)$w['input_tokens']) ?> / <?= number_format((int)$w['output_tokens']) ?></td>
            <td>$<?= number_format((float)$w['cost'], 4) ?></td>
            <td><?= htmlspecialchars((string)$w['summary']) ?></td>
            <td><a style="color:#3498db;" href="/admin/npcs/<?= $id ?>/wakes/<?= htmlspecialchars($w['wake_id']) ?>">Replay</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<p style="margin-top:25px;"><a class="btn" href="/admin/players/<?= $id ?>/alignment">Alignment log &amp; tools</a></p>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
