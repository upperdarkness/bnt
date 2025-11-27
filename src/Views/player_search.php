<h2>Search Players</h2>

<div style="margin-bottom: 30px;">
    <form action="/player/search" method="get">
        <div style="display: flex; gap: 10px; align-items: center;">
            <input
                type="text"
                name="q"
                value="<?= htmlspecialchars($searchQuery) ?>"
                placeholder="Enter player name..."
                style="flex: 1; max-width: 500px;"
                autofocus
            >
            <button type="submit" class="btn" style="margin: 0;">
                🔍 Search
            </button>
        </div>
        <small style="color: #95a5a6; display: block; margin-top: 8px;">
            Minimum 2 characters required
        </small>
    </form>
</div>

<?php if ($searchQuery && strlen($searchQuery) >= 2): ?>
    <?php if (empty($results)): ?>
        <div class="alert alert-info">
            No players found matching "<?= htmlspecialchars($searchQuery) ?>".
        </div>
    <?php else: ?>
        <div style="margin-bottom: 15px;">
            <p style="color: #95a5a6;">
                Found <?= count($results) ?> player<?= count($results) !== 1 ? 's' : '' ?> matching
                "<?= htmlspecialchars($searchQuery) ?>"
            </p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Player</th>
                    <th style="width: 120px;">Score</th>
                    <th style="width: 150px;">Team</th>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $player): ?>
                <tr>
                    <td>
                        <a href="/player/<?= (int)$player['ship_id'] ?>" style="color: #3498db; font-weight: bold; text-decoration: none;">
                            <?= htmlspecialchars($player['character_name']) ?>
                        </a>
                    </td>
                    <td style="text-align: right; color: #2ecc71;">
                        <?= number_format((int)$player['score']) ?>
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
                            <span style="color: #2ecc71; font-weight: bold;">● Online</span>
                        <?php else: ?>
                            <span style="color: #7f8c8d;">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/player/<?= (int)$player['ship_id'] ?>" class="btn" style="padding: 5px 10px; font-size: 12px; margin: 0;">
                            View Profile
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php elseif ($searchQuery): ?>
    <div class="alert alert-error">
        Search query must be at least 2 characters long.
    </div>
<?php else: ?>
    <div style="padding: 40px; text-align: center; color: #95a5a6;">
        <p style="font-size: 18px; margin: 0;">
            Enter a player name to search
        </p>
    </div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/ranking" class="btn">← Back to Rankings</a>
    <a href="/main" class="btn">Main Menu</a>
</div>
