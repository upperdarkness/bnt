<h2>Team Rankings</h2>

<div style="margin-bottom: 20px;">
    <div class="nav" style="margin: 0;">
        <a href="/ranking">Player Rankings</a>
        <a href="/ranking/teams" style="background: rgba(52, 152, 219, 0.4);">Team Rankings</a>
    </div>
</div>

<div style="margin-bottom: 20px;">
    <p style="color: #95a5a6; font-size: 14px;">
        Rankings are based on total combined score of all team members.
        Only teams with active members are shown.
    </p>
</div>

<?php if (empty($teamRankings)): ?>
    <div class="alert alert-info">
        No active teams found.
    </div>
<?php else: ?>
    <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th style="width: 60px;">Rank</th>
                    <th>Team Name</th>
                    <th style="width: 120px;">Members</th>
                    <th style="width: 150px;">Total Score</th>
                    <th style="width: 150px;">Average Score</th>
                    <th style="width: 150px;">Top Player</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($teamRankings as $team): ?>
                <tr style="<?= $ship['team_id'] && $team['team_id'] == $ship['team_id'] ? 'background: rgba(52, 152, 219, 0.2); font-weight: bold;' : '' ?>">
                    <td style="text-align: center;">
                        <span style="color: <?= $team['rank'] <= 3 ? '#f39c12' : '#95a5a6' ?>;">
                            #<?= number_format($team['rank']) ?>
                        </span>
                    </td>
                    <td>
                        <a href="/teams/<?= (int)$team['team_id'] ?>" style="color: #9b59b6; font-weight: bold; text-decoration: none;">
                            <?= htmlspecialchars($team['team_name']) ?>
                        </a>
                    </td>
                    <td style="text-align: right; color: #3498db;">
                        <?= number_format((int)$team['member_count']) ?>
                    </td>
                    <td style="text-align: right; color: #2ecc71; font-weight: bold;">
                        <?= number_format((int)$team['total_score']) ?>
                    </td>
                    <td style="text-align: right; color: #e67e22;">
                        <?= number_format((int)$team['avg_score']) ?>
                    </td>
                    <td style="text-align: right; color: #f39c12;">
                        <?= number_format((int)$team['top_player_score']) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 20px; padding: 15px; background: rgba(15, 76, 117, 0.2); border-radius: 5px;">
        <h3 style="color: #3498db; margin-bottom: 10px;">Legend:</h3>
        <ul style="list-style: none; padding: 0; color: #95a5a6; font-size: 14px;">
            <li style="margin-bottom: 5px;"><strong>Members:</strong> Number of active players in the team</li>
            <li style="margin-bottom: 5px;"><strong>Total Score:</strong> Sum of all team members' scores</li>
            <li style="margin-bottom: 5px;"><strong>Average Score:</strong> Mean score across all team members</li>
            <li style="margin-bottom: 5px;"><strong>Top Player:</strong> Highest individual score in the team</li>
        </ul>
    </div>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="/ranking" class="btn">Player Rankings</a>
    <a href="/main" class="btn">← Back to Main</a>
</div>
