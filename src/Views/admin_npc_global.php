<?php
$title = $title ?? 'NPC Controls - Admin';
$showHeader = false;
$heading = 'NPC Controls';
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$csrf = htmlspecialchars($session->getCsrfToken());
$pct = $settings['global_daily_budget_usd'] > 0 ? min(100, 100 * $spend['global'] / $settings['global_daily_budget_usd']) : 0;
?>
<div class="npc-grid">
    <div class="stat-card">
        <div class="stat-label">LLM control</div>
        <div class="stat-value" style="color: <?= $llmEnabled ? '#2ecc71' : '#e74c3c' ?>;"><?= $llmEnabled ? 'ENABLED' : 'DISABLED' ?></div>
        <form method="POST" action="/admin/npcs/global" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php if ($llmEnabled): ?>
                <button class="btn" name="action" value="kill_switch" style="background: rgba(231,76,60,.4);" onclick="return confirm('Stop all LLM wakes? NPCs continue on scripted behaviour.');">⛔ Kill switch</button>
            <?php else: ?>
                <button class="btn" name="action" value="enable_llm">Enable LLM control</button>
            <?php endif; ?>
        </form>
    </div>
    <div class="stat-card">
        <div class="stat-label">Worker heartbeat</div>
        <div class="stat-value" style="color: <?= $workerAlive ? '#2ecc71' : '#e74c3c' ?>;">
            <?= $heartbeatAge === null ? 'never' : number_format($heartbeatAge, 1) . ' min ago' ?>
        </div>
        <small><?= $workerAlive ? 'Worker is checking in.' : 'LLM NPCs are on scripted fallback.' ?></small>
    </div>
    <div class="stat-card">
        <div class="stat-label">Spend today (global)</div>
        <div class="stat-value">$<?= number_format($spend['global'], 4) ?></div>
        <small>of $<?= number_format($settings['global_daily_budget_usd'], 2) ?> (<?= number_format($pct, 0) ?>%)</small>
    </div>
    <div class="stat-card">
        <div class="stat-label">OpenRouter error rate (1 h)</div>
        <div class="stat-value" style="color: <?= ($errorRate ?? 0) > 0.2 ? '#e74c3c' : '#2ecc71' ?>;">
            <?= $errorRate === null ? '—' : number_format($errorRate * 100, 0) . '%' ?>
        </div>
        <small><?= $calls ?> calls</small>
    </div>
</div>

<h3>Settings</h3>
<form method="POST" action="/admin/npcs/global" class="npc-form" style="margin-bottom: 30px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <label>Default OpenRouter model id (routine NPCs)</label>
    <input type="text" name="default_model" value="<?= htmlspecialchars($settings['default_model']) ?>" placeholder="provider/model-name">
    <label>Daily budget per NPC (USD)</label>
    <input type="number" step="0.01" min="0" name="daily_budget_usd" value="<?= htmlspecialchars((string)$settings['daily_budget_usd']) ?>">
    <label>Global daily budget (USD)</label>
    <input type="number" step="0.01" min="0" name="global_daily_budget_usd" value="<?= htmlspecialchars((string)$settings['global_daily_budget_usd']) ?>">
    <label>Regular wake interval (minutes)</label>
    <input type="number" min="1" name="wake_interval_min" value="<?= (int)$settings['wake_interval_min'] ?>">
    <p style="margin-top:12px;"><button class="btn" type="submit">Save</button></p>
</form>
<p style="color:#95a5a6;">Also set a spending limit on the OpenRouter key itself as a backstop.</p>

<h3 style="margin-top:25px;">NPC framework</h3>
<form method="POST" action="/admin/npcs/global">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <button class="btn" name="action" value="toggle_npcs">
        <?= $npcEnabled ? 'Disable' : 'Enable' ?> all NPC activity (scripted + LLM)
    </button>
    <span style="margin-left:10px; color:#95a5a6;">Currently <?= $npcEnabled ? 'enabled' : 'disabled' ?>.</span>
</form>

<h3 style="margin-top:25px;">Alerts</h3>
<?php if (!$alerts): ?>
    <p style="color:#95a5a6;">No open alerts.</p>
<?php else: ?>
<table>
    <thead><tr><th>When</th><th>Kind</th><th>Ship</th><th>Detail</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($alerts as $a): ?>
        <tr>
            <td><?= htmlspecialchars(date('M j H:i', strtotime((string)$a['created_at']))) ?></td>
            <td><?= htmlspecialchars($a['kind']) ?></td>
            <td><?= $a['ship_id'] ? '<a style="color:#3498db" href="/admin/npcs/' . (int)$a['ship_id'] . '">' . (int)$a['ship_id'] . '</a>' : '—' ?></td>
            <td><code><?= htmlspecialchars(mb_substr((string)$a['detail'], 0, 200)) ?></code></td>
            <td>
                <form method="POST" action="/admin/npcs/global">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
                    <button class="btn" name="action" value="ack">Acknowledge</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
