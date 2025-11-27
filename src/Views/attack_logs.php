<h2>⚔️ Combat History</h2>

<div style="margin-bottom: 20px;">
    <div class="nav" style="margin: 0;">
        <a href="/logs" style="background: rgba(52, 152, 219, 0.4);">Recent Activity</a>
        <a href="/logs/made">Attacks Made</a>
        <a href="/logs/received">Attacks Received</a>
    </div>
</div>

<div class="stat-grid" style="margin-bottom: 30px;">
    <div class="stat-card">
        <div class="stat-label">Attacks Made</div>
        <div class="stat-value" style="color: #e67e22;"><?= number_format($statistics['attacks_made']) ?></div>
        <div class="stat-label" style="margin-top: 5px; font-size: 11px;">
            <?= $statistics['attacks_made'] > 0 ? round(($statistics['successful_attacks'] / $statistics['attacks_made']) * 100, 1) : 0 ?>% Success
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Successful Attacks</div>
        <div class="stat-value" style="color: #2ecc71;"><?= number_format($statistics['successful_attacks']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Damage Dealt</div>
        <div class="stat-value" style="color: #f39c12;"><?= number_format($statistics['total_damage_dealt']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Attacks Received</div>
        <div class="stat-value" style="color: #e74c3c;"><?= number_format($statistics['attacks_received']) ?></div>
        <div class="stat-label" style="margin-top: 5px; font-size: 11px;">
            Destroyed: <?= number_format($statistics['times_destroyed']) ?>×
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Damage Received</div>
        <div class="stat-value" style="color: #9b59b6;"><?= number_format($statistics['total_damage_received']) ?></div>
    </div>
</div>

<h3 style="color: #3498db; margin-bottom: 15px;">Recent Combat Activity (Last 25)</h3>

<?php if (empty($recentActivity)): ?>
    <div class="alert alert-info">
        No combat activity recorded yet. Your attacks and defenses will appear here.
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">Type</th>
                    <th style="width: 150px;">Attacker</th>
                    <th style="width: 150px;">Defender</th>
                    <th style="width: 100px;">Attack Type</th>
                    <th style="width: 100px;">Result</th>
                    <th style="width: 100px;">Damage</th>
                    <th style="width: 80px;">Sector</th>
                    <th style="width: 180px;">Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentActivity as $log): ?>
                <?php
                $isAttacker = ($log['attacker_id'] == $ship['ship_id']);
                $rowColor = $isAttacker ? 'rgba(230, 126, 34, 0.1)' : 'rgba(231, 76, 60, 0.1)';
                ?>
                <tr style="background: <?= $rowColor ?>;">
                    <td style="text-align: center;">
                        <?php if ($isAttacker): ?>
                            <span style="color: #e67e22; font-size: 18px;" title="You attacked">⚔️</span>
                        <?php else: ?>
                            <span style="color: #e74c3c; font-size: 18px;" title="You were attacked">🛡️</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span style="color: <?= $isAttacker ? '#f39c12' : '#3498db' ?>; font-weight: <?= $isAttacker ? 'bold' : 'normal' ?>;">
                            <?= htmlspecialchars($log['attacker_name']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($log['defender_name']): ?>
                            <span style="color: <?= !$isAttacker ? '#f39c12' : '#3498db' ?>; font-weight: <?= !$isAttacker ? 'bold' : 'normal' ?>;">
                                <?= htmlspecialchars($log['defender_name']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #7f8c8d; font-style: italic;">Sector Defenses</span>
                        <?php endif; ?>
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
                            'success' => '#2ecc71',
                            'destroyed' => '#e74c3c',
                            'failure' => '#95a5a6',
                            'escaped' => '#3498db',
                            default => '#7f8c8d'
                        };
                        $resultLabel = match($log['result']) {
                            'success' => 'Success',
                            'destroyed' => 'Destroyed',
                            'failure' => 'Failed',
                            'escaped' => 'Escaped',
                            default => $log['result']
                        };
                        ?>
                        <span style="color: <?= $resultColor ?>; font-weight: bold;">
                            <?= $resultLabel ?>
                        </span>
                    </td>
                    <td style="text-align: right; color: #e67e22;">
                        <?= number_format((int)$log['damage_dealt']) ?>
                    </td>
                    <td style="text-align: center; color: #95a5a6;">
                        <?= number_format((int)$log['sector']) ?>
                    </td>
                    <td style="font-size: 12px; color: #95a5a6;">
                        <?= date('M j, g:i A', strtotime($log['timestamp'])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 20px; text-align: center;">
        <a href="/logs/made" class="btn">View All Attacks Made →</a>
        <a href="/logs/received" class="btn">View All Attacks Received →</a>
    </div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">← Back to Main</a>
    <a href="/combat" class="btn">Combat</a>
</div>
