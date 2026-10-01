<h2>Faction Rankings</h2>

<p style="color: #95a5a6; margin-bottom: 20px;">
    Non-player factions are ranked separately from the player top 100.
</p>

<?php if (empty($factions)): ?>
    <div class="alert alert-info">No NPC factions are active.</div>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Faction</th>
            <th style="text-align:right;">Ships</th>
            <th style="text-align:right;">Active</th>
            <th style="text-align:right;">Total score</th>
            <th style="text-align:right;">Top score</th>
            <th style="text-align:right;">Credits held</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($factions as $i => $f): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($factionLabels[$f['faction']] ?? $f['faction']) ?></td>
            <td style="text-align:right;"><?= number_format((int)$f['members']) ?></td>
            <td style="text-align:right;"><?= number_format((int)$f['alive']) ?></td>
            <td style="text-align:right;"><?= number_format((int)$f['total_score']) ?></td>
            <td style="text-align:right;"><?= number_format((int)$f['top_score']) ?></td>
            <td style="text-align:right;"><?= number_format((int)$f['total_credits']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/ranking" class="btn">← Player Rankings</a>
</div>
