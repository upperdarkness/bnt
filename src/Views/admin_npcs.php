<?php
$title = $title ?? 'NPCs - Admin';
$showHeader = false;
$heading = 'NPCs';
ob_start();
include __DIR__ . '/admin_npc_nav.php';
?>
<p style="margin-bottom:15px;">
    LLM control: <strong style="color: <?= $llmEnabled ? '#2ecc71' : '#e74c3c' ?>;"><?= $llmEnabled ? 'ENABLED' : 'DISABLED' ?></strong>
    &middot; <a href="/admin/npcs/global">Kill switch, budgets &amp; worker status →</a>
</p>

<h3>Faction advantages</h3>
<p style="color:#95a5a6; margin-bottom:10px;">NPCs follow player rules. Advantages are configured explicitly and listed here.</p>
<table style="margin-bottom: 25px;">
    <thead><tr><th>Faction</th><th>Role</th><th>Default alignment</th><th>Turn multiplier</th><th>Home repair</th><th>Trading skill</th><th>Credit cap</th><th>Count / 1,000 sectors</th></tr></thead>
    <tbody>
    <?php foreach ($factions as $key => $f): ?>
        <tr>
            <td><?= htmlspecialchars($f['label']) ?></td>
            <td><?= htmlspecialchars($f['archetype']) ?></td>
            <td><?= number_format((int)$f['alignment']) ?></td>
            <td><?= number_format((float)($f['turn_multiplier'] ?? 1.0), 2) ?>×</td>
            <td><?= !empty($config['npc']['repair_at_home']) ? 'Yes (home zone)' : 'No' ?></td>
            <td><?= (int)($f['loadout']['skill_trading'] ?? 0) ?></td>
            <td><?= (float)($config['npc']['credit_cap_multiplier'] ?? 0) > 0 ? number_format((int)round(($f['loadout']['credits'] ?? 0) * $config['npc']['credit_cap_multiplier'])) : 'none' ?></td>
            <td><?= (float)$f['count_per_1000'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3>NPCs (<?= count($npcs) ?>)</h3>
<?php if (!$npcs): ?>
    <div class="alert alert-info">No NPCs yet. They are spawned by the scheduler (<code>npc_population</code>) or with <code>php scripts/npc_spawn.php</code>.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table>
    <thead>
        <tr><th>Name</th><th>Faction</th><th>Controller</th><th>State</th><th>Sector</th><th>Alignment</th><th>Turns</th><th>Spend today</th><th>Last wake</th></tr>
    </thead>
    <tbody>
    <?php foreach ($npcs as $n): ?>
        <tr>
            <td><a href="/admin/npcs/<?= (int)$n['ship_id'] ?>" style="color:#3498db;"><?= htmlspecialchars($n['character_name']) ?></a></td>
            <td><?= htmlspecialchars($factions[$n['faction']]['label'] ?? $n['faction']) ?></td>
            <td>
                <?= htmlspecialchars($n['controller']) ?>
                <?php if ($n['controller'] === 'llm'): ?>
                    <small style="color: <?= $n['effective'] === 'llm' ? '#2ecc71' : '#e67e22' ?>;">
                        (<?= $n['effective'] === 'llm' ? 'active' : 'fallback: ' . htmlspecialchars((string)$n['fallback_reason']) ?>)
                    </small>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($n['ship_destroyed']): ?>
                    <span style="color:#e74c3c;">dead<?= $n['respawn_at'] ? ', respawn ' . htmlspecialchars(date('H:i', strtotime((string)$n['respawn_at']))) : '' ?></span>
                <?php else: ?>
                    <?= htmlspecialchars((string)$n['script_state']) ?: 'idle' ?>
                <?php endif; ?>
            </td>
            <td><?= (int)$n['sector'] ?></td>
            <td style="color: <?= $rules->color((int)$n['alignment']) ?>;"><?= htmlspecialchars($rules->label((int)$n['alignment'])) ?> (<?= number_format((int)$n['alignment']) ?>)</td>
            <td><?= number_format((int)$n['turns']) ?></td>
            <td>$<?= number_format((float)$n['spend_today'], 4) ?></td>
            <td><?= $n['last_wake_at'] ? htmlspecialchars(date('M j H:i', strtotime((string)$n['last_wake_at']))) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
