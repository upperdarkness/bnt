<h2>Alignment</h2>

<?php if (!$alignmentService->enabled()): ?>
    <div class="alert alert-info">The alignment system is currently disabled on this server.</div>
<?php else: ?>
<?php
$a = (int)$ship['alignment'];
$tierColor = $rules->color($a);
$tiers = [
    ['Paragon', '1,000 and above', 'Full FedSpace protection · 5% starbase discount · bounties pay 125%'],
    ['Lawful', '100 to 999', 'Full FedSpace protection · can claim bounties'],
    ['Neutral', '-99 to 99', 'FedSpace protection · can claim bounties'],
    ['Outlaw', '-999 to -100', 'No FedSpace protection · 15% starbase surcharge · police may engage when Wanted'],
    ['Pirate', '-1,000 and below', 'No FedSpace protection · refused at starbases · always Wanted · police hunt you'],
];
?>
<div class="stat-grid" style="margin-bottom: 25px;">
    <div class="stat-card">
        <div class="stat-label">Tier</div>
        <div class="stat-value" style="color: <?= $tierColor ?>;"><?= htmlspecialchars($rules->label($a)) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Alignment (visible only to you)</div>
        <div class="stat-value"><?= $a > 0 ? '+' : '' ?><?= number_format($a) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Federation status</div>
        <div class="stat-value" style="color: <?= $wanted ? '#e74c3c' : '#2ecc71' ?>;"><?= $wanted ? 'WANTED' : 'In good standing' ?></div>
    </div>
</div>

<?php if ($wanted): ?>
<div class="alert alert-error" style="margin-bottom: 20px;">
    <strong>The Federation wants you.</strong>
    <?php if (!empty($ship['wanted_until'])): ?>Wanted until <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$ship['wanted_until']))) ?> unless you reoffend.<?php endif; ?>
    A bounty of <strong><?= number_format($openBounty) ?></strong> credits is open on you.
    <?php if ($fine['pirate_blocked']): ?>
        Pirates cannot pay their way out: climb out of the Pirate tier through good acts or time first.
    <?php else: ?>
        You can pay a fine of <?= number_format($fine['fine']) ?> credits at any starbase to clear your record (alignment restored to <?= (int)$fine['restores_to'] ?>).
    <?php endif; ?>
</div>
<?php elseif ($fine['applicable'] && !$fine['pirate_blocked']): ?>
<div class="alert alert-info" style="margin-bottom: 20px;">
    You can pay a Federation fine of <?= number_format($fine['fine']) ?> credits at any starbase to restore your alignment to <?= (int)$fine['restores_to'] ?>.
</div>
<?php endif; ?>

<h3>Recent changes</h3>
<?php if (!$recent): ?>
    <p style="color:#95a5a6;">No alignment changes yet.</p>
<?php else: ?>
<table>
    <thead><tr><th>When</th><th>Change</th><th>New value</th><th>Reason</th><th>Related ship</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $row): ?>
        <tr>
            <td><?= htmlspecialchars(date('M j g:i A', strtotime((string)$row['created_at']))) ?></td>
            <td style="color: <?= $row['delta'] >= 0 ? '#2ecc71' : '#e74c3c' ?>;"><?= $row['delta'] > 0 ? '+' : '' ?><?= (int)$row['delta'] ?></td>
            <td><?= number_format((int)$row['new_value']) ?></td>
            <td><?= htmlspecialchars(str_replace('_', ' ', (string)$row['reason'])) ?></td>
            <td><?= htmlspecialchars((string)($row['related_name'] ?? '')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3 style="margin-top: 30px;">Place a bounty</h3>
<p style="color:#95a5a6; margin-bottom:10px;">
    Fund a bounty on another captain from your Intergalactic Bank balance (minimum <?= number_format((int)$rules->config('player_bounty_minimum', 10000)) ?> credits, non-refundable).
    Only Neutral-or-better captains can claim it, and never on a team-mate.
</p>
<form method="POST" action="/alignment/bounty" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 30px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
    <input type="text" name="target" placeholder="Captain name" required maxlength="50">
    <input type="number" name="amount" placeholder="Amount" min="<?= (int)$rules->config('player_bounty_minimum', 10000) ?>" required>
    <button type="submit" class="btn" onclick="return confirm('Bounties are non-refundable. Place it?');">Place bounty</button>
</form>

<h3 style="margin-top: 30px;">How tiers work</h3>
<table>
    <thead><tr><th>Tier</th><th>Range</th><th>Effects</th></tr></thead>
    <tbody>
    <?php foreach ($tiers as [$name, $range, $effects]): ?>
        <tr style="<?= $name === $rules->label($a) ? 'background: rgba(52,152,219,.2);' : '' ?>">
            <td><?= $name ?></td><td><?= $range ?></td><td><?= $effects ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div style="margin-top: 20px;"><a href="/main" class="btn">← Back to Main</a></div>
