<?php
$title = 'Teams - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2>Teams &amp; Alliances</h2>

<?php if ($ship['team'] != 0): ?>
    <?php $myTeam = array_filter($teams, fn($t) => $t['team_id'] == $ship['team']); ?>
    <?php if ($myTeam): ?>
        <?php $myTeam = reset($myTeam); ?>
        <div class="alert alert-success">
            You are a member of <a href="/teams/<?= (int)$myTeam['team_id'] ?>" style="color: #2ecc71; text-decoration: underline;"><strong><?= htmlspecialchars($myTeam['team_name']) ?></strong></a>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="alert alert-info">
        You are not currently in a team. Create a new team or accept an invitation to join one.
    </div>
<?php endif; ?>

<?php if (!empty($invitations)): ?>
<div style="margin: 20px 0;">
    <h3>Team Invitations</h3>
    <table>
        <thead>
            <tr>
                <th>Team</th>
                <th>Invited By</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invitations as $inv): ?>
            <tr>
                <td><strong><?= htmlspecialchars($inv['team_name']) ?></strong></td>
                <td><?= htmlspecialchars($inv['inviter_name']) ?></td>
                <td><?= date('Y-m-d H:i', strtotime($inv['created_date'])) ?></td>
                <td>
                    <form action="/teams/invitations/<?= (int)$inv['invitation_id'] ?>/accept" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <button type="submit" class="btn" style="padding: 5px 15px; background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                            Accept
                        </button>
                    </form>
                    <form action="/teams/invitations/<?= (int)$inv['invitation_id'] ?>/decline" method="post" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                        <button type="submit" class="btn" style="padding: 5px 15px; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                            Decline
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div style="margin: 30px 0;">
    <h3>All Teams</h3>
    <?php if ($ship['team'] == 0): ?>
    <div style="margin-bottom: 15px;">
        <a href="/teams/create" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
            Create New Team
        </a>
    </div>
    <?php endif; ?>

    <?php if (empty($teams)): ?>
    <div style="padding: 40px; text-align: center;">
        <p style="color: #7f8c8d; font-size: 18px;">No teams exist yet. Be the first to create one!</p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Team Name</th>
                <th>Founder</th>
                <th>Members</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($teams as $team): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($team['team_name']) ?></strong>
                    <?php if ($team['team_id'] == $ship['team']): ?>
                        <span style="color: #2ecc71; font-size: 12px;">(Your Team)</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($team['founder_name']) ?></td>
                <td><?= (int)$team['member_count'] ?></td>
                <td>
                    <?php if (!empty($team['description'])): ?>
                        <?= htmlspecialchars(substr($team['description'], 0, 100)) ?>
                        <?= strlen($team['description']) > 100 ? '...' : '' ?>
                    <?php else: ?>
                        <span style="color: #7f8c8d; font-style: italic;">No description</span>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/teams/<?= (int)$team['team_id'] ?>" class="btn" style="padding: 5px 15px;">
                        View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div style="margin-top: 30px; background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px;">
    <h3>About Teams</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <div>
            <h4>Benefits</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Team members won't attack each other</li>
                <li>Shared sector defenses protect allies</li>
                <li>Team chat for coordination</li>
                <li>Shared team treasury</li>
                <li>Combined strength in combat</li>
            </ul>
        </div>
        <div>
            <h4>Team Management</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Founders can invite players</li>
                <li>Founders can kick members</li>
                <li>Members can leave anytime</li>
                <li>Team statistics and rankings</li>
                <li>Team messaging system</li>
            </ul>
        </div>
        <div>
            <h4>Strategy Tips</h4>
            <ul style="margin-left: 20px; color: #e0e0e0;">
                <li>Coordinate attacks and defenses</li>
                <li>Share resources through planets</li>
                <li>Control sectors together</li>
                <li>Build team bases strategically</li>
                <li>Protect weaker members</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 30px;">
    <a href="/main" class="btn">Back to Main</a>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
