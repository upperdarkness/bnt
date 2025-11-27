<h2>🛡️ Attacks Received</h2>

<div style="margin-bottom: 20px;">
    <div class="nav" style="margin: 0;">
        <a href="/logs">Recent Activity</a>
        <a href="/logs/made">Attacks Made</a>
        <a href="/logs/received" style="background: rgba(52, 152, 219, 0.4);">Attacks Received</a>
    </div>
</div>

<div style="margin-bottom: 20px;">
    <p style="color: #95a5a6;">
        Showing attacks against you (<?= number_format($totalCount) ?> total attacks)
    </p>
</div>

<?php if (empty($attacks)): ?>
    <div class="alert alert-info">
        No attacks received yet. You haven't been attacked by anyone.
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th style="width: 150px;">Attacker</th>
                    <th style="width: 100px;">Attack Type</th>
                    <th style="width: 100px;">Result</th>
                    <th style="width: 120px;">Damage Taken</th>
                    <th style="width: 80px;">Sector</th>
                    <th style="width: 180px;">Time</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attacks as $log): ?>
                <tr style="<?= $log['result'] === 'destroyed' ? 'background: rgba(231, 76, 60, 0.1);' : '' ?>">
                    <td>
                        <a href="/player/<?= (int)$log['attacker_id'] ?>" style="color: #e67e22; text-decoration: none; font-weight: bold;">
                            <?= htmlspecialchars($log['attacker_name']) ?>
                        </a>
                    </td>
                    <td style="text-align: center;">
                        <?php
                        $typeIcon = match($log['attack_type']) {
                            'ship' => '🚀',
                            'planet' => '🌍',
                            'defense' => '⚙️',
                            default => '⚔️'
                        };
                        $typeLabel = match($log['attack_type']) {
                            'ship' => 'Ship',
                            'planet' => 'Planet',
                            'defense' => 'Defense',
                            default => 'Unknown'
                        };
                        ?>
                        <span title="<?= $typeLabel ?>"><?= $typeIcon ?> <?= $typeLabel ?></span>
                    </td>
                    <td style="text-align: center;">
                        <?php
                        $resultColor = match($log['result']) {
                            'success' => '#e67e22',
                            'destroyed' => '#e74c3c',
                            'failure' => '#2ecc71',
                            'escaped' => '#3498db',
                            default => '#7f8c8d'
                        };
                        $resultLabel = match($log['result']) {
                            'success' => 'Hit',
                            'destroyed' => 'Destroyed',
                            'failure' => 'Defended',
                            'escaped' => 'Escaped',
                            default => $log['result']
                        };
                        $resultIcon = match($log['result']) {
                            'success' => '⚠️',
                            'destroyed' => '💀',
                            'failure' => '✓',
                            'escaped' => '→',
                            default => '•'
                        };
                        ?>
                        <span style="color: <?= $resultColor ?>; font-weight: bold;">
                            <?= $resultIcon ?> <?= $resultLabel ?>
                        </span>
                    </td>
                    <td style="text-align: right;">
                        <span style="color: #e74c3c; font-weight: bold;">
                            <?= number_format((int)$log['damage_dealt']) ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <a href="/main?sector=<?= (int)$log['sector'] ?>" style="color: #95a5a6; text-decoration: none;">
                            <?= number_format((int)$log['sector']) ?>
                        </a>
                    </td>
                    <td style="font-size: 12px; color: #95a5a6;">
                        <?= date('M j, Y g:i A', strtotime($log['timestamp'])) ?>
                    </td>
                    <td>
                        <a href="/messages/compose?recipient=<?= urlencode($log['attacker_name']) ?>"
                           class="btn"
                           style="padding: 5px 10px; font-size: 12px; margin: 0;">
                            Message
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="margin-top: 20px; text-align: center;">
        <div style="display: inline-flex; gap: 5px; align-items: center;">
            <?php if ($currentPage > 1): ?>
                <a href="/logs/received?page=<?= $currentPage - 1 ?>" class="btn" style="padding: 8px 15px; margin: 0;">← Previous</a>
            <?php endif; ?>

            <span style="color: #95a5a6; margin: 0 15px;">
                Page <?= $currentPage ?> of <?= $totalPages ?>
            </span>

            <?php if ($currentPage < $totalPages): ?>
                <a href="/logs/received?page=<?= $currentPage + 1 ?>" class="btn" style="padding: 8px 15px; margin: 0;">Next →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/logs" class="btn">← Back to Recent Activity</a>
    <a href="/defenses" class="btn">View Defenses</a>
</div>
