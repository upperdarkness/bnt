<?php
$title = htmlspecialchars($team['team_name']) . ' - BlackNova Traders';
$showHeader = true;
ob_start();
?>

<h2><?= htmlspecialchars($team['team_name']) ?></h2>

<div class="alert alert-info">
    Founded by <?= htmlspecialchars($team['founder_name'] ?? 'Unknown') ?> |
    Created: <?= date('Y-m-d', strtotime($team['created_date'])) ?>
    <?php if ($isMember): ?>
        | <span style="color: #2ecc71;">✓ You are a member</span>
    <?php endif; ?>
</div>

<?php if (!empty($team['description'])): ?>
<div style="background: rgba(15, 76, 117, 0.2); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
    <p style="color: #e0e0e0;"><?= nl2br(htmlspecialchars($team['description'])) ?></p>
</div>
<?php endif; ?>

<!-- Team Statistics -->
<h3>Team Statistics</h3>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Members</div>
        <div class="stat-value"><?= number_format($statistics['member_count']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Credits</div>
        <div class="stat-value"><?= number_format($statistics['total_credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Fighters</div>
        <div class="stat-value"><?= number_format($statistics['total_fighters']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Planets Owned</div>
        <div class="stat-value"><?= number_format($statistics['planet_count']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Team Treasury</div>
        <div class="stat-value"><?= number_format($team['team_credits']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Average Turns</div>
        <div class="stat-value"><?= number_format($statistics['avg_turns'], 1) ?></div>
    </div>
</div>

<!-- Team Members -->
<h3 style="margin-top: 30px;">Team Members</h3>
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Credits</th>
            <th>Fighters</th>
            <th>Turns</th>
            <th>Sector</th>
            <?php if ($isFounder): ?>
            <th>Actions</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($members as $member): ?>
        <tr>
            <td>
                <?= htmlspecialchars($member['character_name']) ?>
                <?php if ($member['ship_id'] == $team['founder_id']): ?>
                    <span style="color: #f39c12; font-size: 12px;">⭐ Founder</span>
                <?php endif; ?>
                <?php if ($member['ship_id'] == $ship['ship_id']): ?>
                    <span style="color: #2ecc71; font-size: 12px;">(You)</span>
                <?php endif; ?>
            </td>
            <td><?= number_format($member['credits']) ?></td>
            <td><?= number_format($member['ship_fighters']) ?></td>
            <td><?= number_format($member['turns']) ?></td>
            <td><?= (int)$member['sector'] ?></td>
            <?php if ($isFounder && $member['ship_id'] != $ship['ship_id']): ?>
            <td>
                <form action="/teams/members/<?= (int)$member['ship_id'] ?>/kick" method="post"
                      onsubmit="return confirm('Kick <?= htmlspecialchars($member['character_name']) ?> from the team?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <button type="submit" class="btn" style="padding: 5px 10px; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
                        Kick
                    </button>
                </form>
            </td>
            <?php elseif ($isFounder): ?>
            <td>-</td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($isMember): ?>
<!-- Team Communication -->
<h3 style="margin-top: 30px;">Team Messages</h3>
<div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
    <form action="/teams/<?= (int)$team['team_id'] ?>/messages" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <textarea name="message" rows="3" placeholder="Send a message to your team..." required maxlength="1000" style="margin-bottom: 10px;"></textarea>
        <button type="submit" class="btn">Post Message</button>
    </form>
</div>

<?php if (!empty($messages)): ?>
<div style="background: rgba(22, 33, 62, 0.8); padding: 20px; border-radius: 8px; max-height: 400px; overflow-y: auto;">
    <?php foreach ($messages as $msg): ?>
    <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid rgba(52, 152, 219, 0.2);">
        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
            <strong style="color: #3498db;"><?= htmlspecialchars($msg['character_name']) ?></strong>
            <span style="color: #7f8c8d; font-size: 12px;"><?= date('Y-m-d H:i', strtotime($msg['created_date'])) ?></span>
        </div>
        <div style="color: #e0e0e0;"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<p style="color: #7f8c8d; text-align: center; padding: 20px;">No messages yet. Be the first to post!</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($isFounder): ?>
<!-- Founder Controls -->
<h3 style="margin-top: 30px;">Founder Controls</h3>
<div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
    <!-- Invite Player -->
    <h4>Invite Player</h4>
    <form action="/teams/<?= (int)$team['team_id'] ?>/invite" method="post" style="margin-bottom: 30px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <div style="display: flex; gap: 10px;">
            <input type="text" name="player_name" placeholder="Player name" required style="flex: 1;">
            <button type="submit" class="btn">Send Invitation</button>
        </div>
    </form>

    <?php if (!empty($pendingInvitations)): ?>
    <h4>Pending Invitations</h4>
    <table style="margin-bottom: 30px;">
        <thead>
            <tr>
                <th>Player</th>
                <th>Date Sent</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pendingInvitations as $inv): ?>
            <tr>
                <td><?= htmlspecialchars($inv['invitee_name']) ?></td>
                <td><?= date('Y-m-d H:i', strtotime($inv['created_date'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Update Team Settings -->
    <h4>Update Team Settings</h4>
    <form action="/teams/<?= (int)$team['team_id'] ?>/update" method="post" style="margin-bottom: 30px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px;">Description (public):</label>
            <textarea name="description" rows="3"><?= htmlspecialchars($team['description']) ?></textarea>
        </div>
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px;">Team Notes (members only):</label>
            <textarea name="team_desc" rows="3"><?= htmlspecialchars($team['team_desc'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn">Update Settings</button>
    </form>

    <!-- Disband Team -->
    <h4 style="color: #e74c3c;">Danger Zone</h4>
    <form action="/teams/<?= (int)$team['team_id'] ?>/disband" method="post"
          onsubmit="return confirm('Are you sure you want to disband this team? This action cannot be undone!');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
            Disband Team
        </button>
    </form>
</div>
<?php endif; ?>

<div style="margin-top: 30px;">
    <a href="/teams" class="btn">Back to Teams</a>
    <?php if ($isMember && !$isFounder): ?>
    <form action="/teams/leave" method="post" style="display: inline;"
          onsubmit="return confirm('Are you sure you want to leave this team?');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
            Leave Team
        </button>
    </form>
    <?php elseif ($isFounder && count($members) == 1): ?>
    <form action="/teams/leave" method="post" style="display: inline;"
          onsubmit="return confirm('As the only member, leaving will disband the team. Continue?');">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
        <button type="submit" class="btn" style="background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;">
            Leave &amp; Disband Team
        </button>
    </form>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
