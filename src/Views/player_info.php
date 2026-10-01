<h2>Player Information</h2>

<?php if ($isOwnProfile): ?>
<div class="alert alert-info" style="margin-bottom: 20px;">
    <strong>ℹ️ Viewing your own profile.</strong> This is how other players see your public information.
</div>
<?php endif; ?>

<div style="background: rgba(15, 76, 117, 0.3); padding: 30px; border-radius: 10px; margin-bottom: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: start; flex-wrap: wrap; gap: 20px;">
        <div>
            <h1 style="color: #3498db; margin: 0 0 10px 0; font-size: 32px;">
                <?= \BNT\Core\ViewHelper::shipName($targetPlayer, $alignmentService ?? null) ?>
            </h1>
            <?php if (!empty($targetPlayer['is_npc'])): ?>
                <p style="margin: 0 0 10px 0;">
                    <span style="padding:3px 10px;border:1px solid #7f8c8d;border-radius:12px;">NPC<?php
                        $factionLabel = $config['npc']['factions'][$targetPlayer['faction'] ?? '']['label'] ?? null;
                        echo $factionLabel ? ' &middot; ' . htmlspecialchars($factionLabel) : '';
                    ?></span>
                </p>
            <?php endif; ?>
            <?php if (!empty($alignmentService) && $alignmentService->enabled()): ?>
                <p style="margin: 0 0 10px 0;">
                    Alignment: <strong><?= htmlspecialchars(\BNT\Core\ViewHelper::tierLabel($targetPlayer, $alignmentService)) ?></strong>
                    <?php if ($isOwnProfile): ?>(<?= number_format((int)$targetPlayer['alignment']) ?>) &middot; <a href="/alignment">details</a><?php endif; ?>
                    <?php if ($openBounty > 0): ?> &middot; <span style="color:#e74c3c;">Bounty: <?= number_format($openBounty) ?> cr</span><?php endif; ?>
                </p>
            <?php endif; ?>
            <?php if ($targetPlayer['ship_name']): ?>
                <p style="color: #95a5a6; font-size: 18px; margin: 0 0 15px 0;">
                    Ship: <span style="color: #e0e0e0;"><?= htmlspecialchars($targetPlayer['ship_name']) ?></span>
                </p>
            <?php endif; ?>

            <?php if ($targetPlayer['is_online']): ?>
                <div style="display: inline-block; padding: 5px 15px; background: rgba(46, 204, 113, 0.2); border: 1px solid #2ecc71; border-radius: 20px; margin-bottom: 10px;">
                    <span style="color: #2ecc71; font-weight: bold;">● Online</span>
                </div>
            <?php else: ?>
                <div style="display: inline-block; padding: 5px 15px; background: rgba(127, 140, 141, 0.2); border: 1px solid #7f8c8d; border-radius: 20px; margin-bottom: 10px;">
                    <span style="color: #7f8c8d;">○ Offline</span>
                </div>
            <?php endif; ?>

            <?php if ($targetPlayer['ship_destroyed']): ?>
                <div style="display: inline-block; padding: 5px 15px; background: rgba(231, 76, 60, 0.2); border: 1px solid #e74c3c; border-radius: 20px; margin-left: 10px;">
                    <span style="color: #e74c3c; font-weight: bold;">💀 Destroyed</span>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: right;">
            <?php if (!$isOwnProfile && $canMessage): ?>
                <a href="/messages/compose?recipient=<?= urlencode($targetPlayer['character_name']) ?>"
                   class="btn"
                   style="background: rgba(52, 152, 219, 0.3); margin-bottom: 10px; display: inline-block;">
                    ✉️ Send Message
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="stat-grid" style="margin-bottom: 30px;">
    <div class="stat-card">
        <div class="stat-label">Rank</div>
        <div class="stat-value" style="color: #f39c12;">
            #<?= $rank ? number_format($rank) : 'Unranked' ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Score</div>
        <div class="stat-value" style="color: #2ecc71;">
            <?= number_format((int)$targetPlayer['score']) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Ship Level</div>
        <div class="stat-value" style="color: #3498db;">
            <?= number_format($shipLevel) ?>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Rating</div>
        <div class="stat-value" style="color: <?= $formattedRating > 0 ? '#2ecc71' : ($formattedRating < 0 ? '#e74c3c' : '#95a5a6') ?>;">
            <?= $formattedRating > 0 ? '+' : '' ?><?= number_format($formattedRating) ?>
        </div>
        <div class="stat-label" style="margin-top: 5px; font-size: 11px;">
            <?= $formattedRating > 0 ? 'Good' : ($formattedRating < 0 ? 'Evil' : 'Neutral') ?>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <!-- Ship Components -->
    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h3 style="color: #3498db; margin: 0 0 15px 0;">Ship Components</h3>
        <table style="width: 100%; margin: 0;">
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">🛡️ Hull</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['hull']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">⚡ Engines</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['engines']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">🔋 Power</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['power']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">💻 Computer</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['computer']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">📡 Sensors</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['sensors']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">⚔️ Beams</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['beams']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">🚀 Torpedoes</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['torp_launchers']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">🛡️ Shields</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['shields']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">🔰 Armor</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['armor']) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 5px 0; color: #95a5a6;">👻 Cloak</td>
                <td style="border: none; padding: 5px 0; text-align: right; color: #3498db; font-weight: bold;">
                    Level <?= number_format((int)$targetPlayer['cloak']) ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Statistics -->
    <div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <h3 style="color: #3498db; margin: 0 0 15px 0;">Statistics</h3>
        <table style="width: 100%; margin: 0;">
            <tr>
                <td style="border: none; padding: 8px 0; color: #95a5a6;">Turns Used</td>
                <td style="border: none; padding: 8px 0; text-align: right; color: #e0e0e0;">
                    <?= number_format((int)$targetPlayer['turns_used']) ?>
                </td>
            </tr>
            <?php if ($efficiency > 0): ?>
            <tr>
                <td style="border: none; padding: 8px 0; color: #95a5a6;">Efficiency</td>
                <td style="border: none; padding: 8px 0; text-align: right; color: #3498db; font-weight: bold;">
                    <?= number_format($efficiency) ?>
                </td>
            </tr>
            <?php endif; ?>
            <tr>
                <td style="border: none; padding: 8px 0; color: #95a5a6;">Planets Owned</td>
                <td style="border: none; padding: 8px 0; text-align: right; color: #e67e22;">
                    <?= number_format($planetCount) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 8px 0; color: #95a5a6;">Account Age</td>
                <td style="border: none; padding: 8px 0; text-align: right; color: #e0e0e0;">
                    <?= number_format($activitySummary['days_active']) ?> days
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 8px 0; color: #95a5a6;">Last Login</td>
                <td style="border: none; padding: 8px 0; text-align: right; color: #e0e0e0; font-size: 12px;">
                    <?= date('M j, Y g:i A', strtotime($targetPlayer['last_login'])) ?>
                </td>
            </tr>
            <tr>
                <td style="border: none; padding: 8px 0; color: #95a5a6;">Joined</td>
                <td style="border: none; padding: 8px 0; text-align: right; color: #e0e0e0; font-size: 12px;">
                    <?= date('M j, Y', strtotime($targetPlayer['created_at'])) ?>
                </td>
            </tr>
        </table>
    </div>
</div>

<!-- Team Information -->
<?php if ($targetPlayer['team_id']): ?>
<div style="background: rgba(15, 76, 117, 0.2); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3); margin-bottom: 30px;">
    <h3 style="color: #9b59b6; margin: 0 0 15px 0;">Team Affiliation</h3>
    <div style="margin-bottom: 15px;">
        <a href="/teams/<?= (int)$targetPlayer['team_id'] ?>" style="color: #9b59b6; font-size: 20px; font-weight: bold; text-decoration: none;">
            <?= htmlspecialchars($targetPlayer['team_name']) ?>
        </a>
        <?php if ($targetPlayer['team_description']): ?>
            <p style="color: #95a5a6; margin: 10px 0 0 0; font-size: 14px;">
                <?= htmlspecialchars($targetPlayer['team_description']) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($teamMembers)): ?>
        <h4 style="color: #95a5a6; margin: 20px 0 10px 0; font-size: 14px;">Team Members:</h4>
        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <?php foreach ($teamMembers as $member): ?>
                <a href="/player/<?= (int)$member['ship_id'] ?>"
                   style="padding: 8px 15px; background: rgba(155, 89, 182, 0.2); border: 1px solid rgba(155, 89, 182, 0.5); border-radius: 5px; color: #9b59b6; text-decoration: none; font-size: 13px; transition: all 0.3s;"
                   onmouseover="this.style.background='rgba(155, 89, 182, 0.4)'"
                   onmouseout="this.style.background='rgba(155, 89, 182, 0.2)'">
                    <?= htmlspecialchars($member['character_name']) ?>
                    <?php if ($member['is_online']): ?>
                        <span style="color: #2ecc71;">●</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="background: rgba(127, 140, 141, 0.1); padding: 20px; border-radius: 8px; border: 1px solid rgba(127, 140, 141, 0.3); margin-bottom: 30px;">
    <p style="color: #95a5a6; margin: 0; text-align: center;">
        This player is not currently in a team.
    </p>
</div>
<?php endif; ?>

<div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
    <a href="/ranking" class="btn">← Back to Rankings</a>
    <?php if ($isOwnProfile): ?>
        <a href="/upgrades" class="btn">Upgrade Ship</a>
        <a href="/status" class="btn">View Full Status</a>
    <?php endif; ?>
</div>
