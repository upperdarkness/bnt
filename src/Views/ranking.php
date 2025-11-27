<h2>Player Rankings</h2>

<div style="margin-bottom: 20px;">
    <a href="/player/search" class="btn">🔍 Search Players</a>
</div>

<div class="stat-grid" style="margin-bottom: 25px;">
    <div class="stat-card">
        <div class="stat-label">Total Players</div>
        <div class="stat-value"><?= number_format($playerCount) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Your Rank</div>
        <div class="stat-value" style="color: #f39c12;">
            <?= $currentPlayerRank ? '#' . number_format($currentPlayerRank) : 'Unranked' ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Displaying</div>
        <div class="stat-value"><?= number_format(min(count($rankings), $maxRank)) ?></div>
    </div>
</div>

<div style="margin-bottom: 20px;">
    <p style="color: #95a5a6; font-size: 14px;">
        Showing top <?= number_format($maxRank) ?> active players.
        Excludes destroyed ships. Click column headers to sort.
    </p>
</div>

<?php if (empty($rankings)): ?>
    <div class="alert alert-info">
        No active players found.
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th style="width: 60px;">Rank</th>
                    <th style="width: 120px;">
                        <a href="/ranking?sort=score" style="color: inherit; text-decoration: none;">
                            Score <?= $sortBy === 'score' ? '▼' : '' ?>
                        </a>
                    </th>
                    <th>Player</th>
                    <th style="width: 100px;">
                        <a href="/ranking?sort=turns" style="color: inherit; text-decoration: none;">
                            Turns <?= $sortBy === 'turns' ? '▼' : '' ?>
                        </a>
                    </th>
                    <th style="width: 180px;">
                        <a href="/ranking?sort=login" style="color: inherit; text-decoration: none;">
                            Last Login <?= $sortBy === 'login' ? '▼' : '' ?>
                        </a>
                    </th>
                    <th style="width: 100px;">
                        <a href="/ranking?sort=good" style="color: inherit; text-decoration: none;">
                            Rating <?= in_array($sortBy, ['good', 'bad']) ? '▼' : '' ?>
                        </a>
                    </th>
                    <th style="width: 150px;">
                        <a href="/ranking?sort=alliance" style="color: inherit; text-decoration: none;">
                            Team <?= $sortBy === 'alliance' ? '▼' : '' ?>
                        </a>
                    </th>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 100px;">
                        <a href="/ranking?sort=efficiency" style="color: inherit; text-decoration: none;">
                            Efficiency <?= $sortBy === 'efficiency' ? '▼' : '' ?>
                        </a>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rankings as $player): ?>
                <tr style="<?= $player['ship_id'] == $ship['ship_id'] ? 'background: rgba(52, 152, 219, 0.2); font-weight: bold;' : '' ?>">
                    <td style="text-align: center;">
                        <span style="color: <?= $player['rank'] <= 3 ? '#f39c12' : '#95a5a6' ?>;">
                            #<?= number_format($player['rank']) ?>
                        </span>
                    </td>
                    <td style="text-align: right; color: #2ecc71; font-weight: bold;">
                        <?= number_format((int)$player['score']) ?>
                    </td>
                    <td>
                        <a href="/player/<?= (int)$player['ship_id'] ?>" style="color: #3498db; font-weight: bold; text-decoration: none;">
                            <?= htmlspecialchars($player['character_name']) ?>
                        </a>
                    </td>
                    <td style="text-align: right;">
                        <?= number_format((int)$player['turns_used']) ?>
                    </td>
                    <td style="font-size: 12px; color: #95a5a6;">
                        <?= $player['last_login'] ? date('M j, Y g:i A', strtotime($player['last_login'])) : 'Never' ?>
                    </td>
                    <td style="text-align: right;">
                        <?php
                        $rating = (int)$player['formatted_rating'];
                        $ratingColor = $rating > 0 ? '#2ecc71' : ($rating < 0 ? '#e74c3c' : '#95a5a6');
                        ?>
                        <span style="color: <?= $ratingColor ?>;">
                            <?= $rating > 0 ? '+' : '' ?><?= number_format($rating) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($player['team_name']): ?>
                            <span style="color: #9b59b6;">
                                <?= htmlspecialchars($player['team_name']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #7f8c8d; font-style: italic;">None</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ($player['is_online']): ?>
                            <span style="color: #2ecc71; font-weight: bold;" title="Online in last 5 minutes">
                                ● Online
                            </span>
                        <?php else: ?>
                            <span style="color: #7f8c8d;">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php if ((int)$player['efficiency'] > 0): ?>
                            <span style="color: #3498db;">
                                <?= number_format((int)$player['efficiency']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color: #7f8c8d;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 20px; padding: 15px; background: rgba(15, 76, 117, 0.2); border-radius: 5px;">
        <h3 style="color: #3498db; margin-bottom: 10px;">Legend:</h3>
        <ul style="list-style: none; padding: 0; color: #95a5a6; font-size: 14px;">
            <li style="margin-bottom: 5px;"><strong>Score:</strong> Total points accumulated</li>
            <li style="margin-bottom: 5px;"><strong>Turns:</strong> Total number of turns used</li>
            <li style="margin-bottom: 5px;"><strong>Rating:</strong> Good (+) / Evil (-) alignment based on actions</li>
            <li style="margin-bottom: 5px;"><strong>Status:</strong> Online if active within last 5 minutes</li>
            <li style="margin-bottom: 5px;"><strong>Efficiency:</strong> Score per turn (only shown if turns > 150)</li>
        </ul>
    </div>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/main" class="btn">← Back to Main</a>
</div>
