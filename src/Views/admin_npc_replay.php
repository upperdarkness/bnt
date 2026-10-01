<?php
$title = $title ?? 'Wake replay - Admin';
$showHeader = false;
$heading = 'Wake replay';
ob_start();
include __DIR__ . '/admin_npc_nav.php';
$pretty = static fn($v) => $v === null ? '' : json_encode(is_string($v) ? json_decode($v, true) : $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
<p><a href="/admin/npcs/<?= (int)($npc['ship_id'] ?? 0) ?>" style="color:#3498db;">← <?= htmlspecialchars($npc['character_name'] ?? 'NPC') ?></a>
   &middot; wake <code><?= htmlspecialchars($wakeId) ?></code></p>
<p style="color:#95a5a6; margin: 10px 0 20px;">
    This is the exact record of what the model saw and did. Text from other players appears inside &lt;&lt;&lt; &gt;&gt;&gt; delimiters.
</p>
<?php foreach ($steps as $s): ?>
    <div style="border-left: 3px solid <?= $s['tool'] === '_error' ? '#e74c3c' : ($s['tool'] === null ? '#9b59b6' : '#3498db') ?>; padding: 6px 12px; margin-bottom: 12px; background: rgba(255,255,255,.03);">
        <strong>
            <?php if ($s['tool'] === '_observation'): ?>Observation
            <?php elseif ($s['tool'] === '_error'): ?>Error
            <?php elseif ($s['tool'] === null): ?>Model call (step <?= (int)$s['step'] ?>)
            <?php else: ?>Tool: <?= htmlspecialchars($s['tool']) ?> (step <?= (int)$s['step'] ?>)<?php endif; ?>
        </strong>
        <small style="color:#95a5a6; margin-left: 8px;">
            <?= htmlspecialchars(date('H:i:s', strtotime((string)$s['created_at']))) ?>
            <?php if ($s['model']): ?>· <?= htmlspecialchars($s['model']) ?><?php endif; ?>
            <?php if ($s['input_tokens'] !== null): ?>· <?= (int)$s['input_tokens'] ?> in / <?= (int)$s['output_tokens'] ?> out<?php endif; ?>
            <?php if ($s['cost_usd'] !== null): ?>· $<?= number_format((float)$s['cost_usd'], 6) ?><?php endif; ?>
        </small>
        <?php if ($s['arguments'] !== null): ?><pre class="json"><?= htmlspecialchars($pretty($s['arguments'])) ?></pre><?php endif; ?>
        <?php if ($s['result'] !== null): ?>
            <?php $res = json_decode((string)$s['result'], true); ?>
            <?php if (is_array($res) && isset($res['text']) && count($res) === 1): ?>
                <pre class="json"><?= htmlspecialchars((string)$res['text']) ?></pre>
            <?php else: ?>
                <pre class="json"><?= htmlspecialchars($pretty($s['result'])) ?></pre>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php if (!$steps): ?><p>No records for this wake.</p><?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
