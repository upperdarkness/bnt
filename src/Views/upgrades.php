<h2>Ship Upgrades</h2>

<div class="stat-grid" style="margin-bottom: 25px;">
    <div class="stat-card">
        <div class="stat-label">Available Credits</div>
        <div class="stat-value" style="color: #2ecc71;"><?= number_format((int)$ship['credits']) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Ship Level</div>
        <div class="stat-value" style="color: #3498db;"><?= number_format($totalLevel) ?></div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Ship Name</div>
        <div class="stat-value" style="color: #9b59b6; font-size: 18px;">
            <?= htmlspecialchars($ship['character_name']) ?>
        </div>
    </div>
</div>

<div style="margin-bottom: 20px; padding: 15px; background: rgba(52, 152, 219, 0.1); border-left: 4px solid #3498db; border-radius: 5px;">
    <p style="color: #e0e0e0; margin: 0;">
        <strong>Upgrade your ship components to improve performance!</strong><br>
        Each upgrade increases the component level and improves its effectiveness.
        Costs increase exponentially with each level. You can downgrade components for a 50% refund if needed.
    </p>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <?php foreach ($upgradeInfo as $info): ?>
    <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; border: 1px solid rgba(52, 152, 219, 0.3);">
        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
            <div>
                <h3 style="color: #3498db; margin: 0 0 5px 0; font-size: 18px;">
                    <span style="font-size: 24px; margin-right: 8px;"><?= $info['icon'] ?></span>
                    <?= htmlspecialchars($info['name']) ?>
                </h3>
                <p style="color: #95a5a6; font-size: 13px; margin: 0;">
                    <?= htmlspecialchars($info['description']) ?>
                </p>
            </div>
        </div>

        <div style="background: rgba(22, 33, 62, 0.6); padding: 15px; border-radius: 5px; margin-bottom: 15px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span style="color: #95a5a6;">Current Level:</span>
                <span style="color: #3498db; font-weight: bold; font-size: 18px;">
                    <?= number_format($info['current_level']) ?>
                </span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #95a5a6;">Upgrade Cost:</span>
                <span style="color: <?= $info['can_afford'] ? '#2ecc71' : '#e74c3c' ?>; font-weight: bold;">
                    <?= number_format($info['upgrade_cost']) ?> CR
                </span>
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <!-- Upgrade Form -->
            <form action="/upgrades/upgrade" method="post" style="flex: 1;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="component" value="<?= htmlspecialchars($info['key']) ?>">
                <button
                    type="submit"
                    style="width: 100%; padding: 10px; background: <?= $info['can_afford'] ? 'rgba(46, 204, 113, 0.3)' : 'rgba(127, 140, 141, 0.3)' ?>; color: <?= $info['can_afford'] ? '#2ecc71' : '#7f8c8d' ?>; border: 1px solid <?= $info['can_afford'] ? '#2ecc71' : '#7f8c8d' ?>; border-radius: 5px; cursor: <?= $info['can_afford'] ? 'pointer' : 'not-allowed' ?>; font-weight: bold; transition: all 0.3s;"
                    <?= !$info['can_afford'] ? 'disabled' : '' ?>
                    <?= $info['can_afford'] ? 'onmouseover="this.style.background=\'rgba(46, 204, 113, 0.5)\'" onmouseout="this.style.background=\'rgba(46, 204, 113, 0.3)\'"' : '' ?>
                >
                    ↑ Upgrade
                </button>
            </form>

            <!-- Downgrade Form -->
            <form action="/upgrades/downgrade" method="post" style="flex: 1;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                <input type="hidden" name="component" value="<?= htmlspecialchars($info['key']) ?>">
                <button
                    type="submit"
                    style="width: 100%; padding: 10px; background: <?= $info['current_level'] > 0 ? 'rgba(230, 126, 34, 0.3)' : 'rgba(127, 140, 141, 0.3)' ?>; color: <?= $info['current_level'] > 0 ? '#e67e22' : '#7f8c8d' ?>; border: 1px solid <?= $info['current_level'] > 0 ? '#e67e22' : '#7f8c8d' ?>; border-radius: 5px; cursor: <?= $info['current_level'] > 0 ? 'pointer' : 'not-allowed' ?>; font-weight: bold; transition: all 0.3s;"
                    <?= $info['current_level'] <= 0 ? 'disabled' : '' ?>
                    <?= $info['current_level'] > 0 ? 'onmouseover="this.style.background=\'rgba(230, 126, 34, 0.5)\'" onmouseout="this.style.background=\'rgba(230, 126, 34, 0.3)\'"' : '' ?>
                    onclick="return confirm('Downgrade <?= htmlspecialchars($info['name']) ?>? You will receive 50% refund.');"
                >
                    ↓ Downgrade
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div style="margin-top: 30px; padding: 20px; background: rgba(15, 76, 117, 0.2); border-radius: 8px;">
    <h3 style="color: #3498db; margin-bottom: 15px;">Component Details</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
        <div>
            <h4 style="color: #2ecc71; margin: 0 0 8px 0; font-size: 14px;">Offensive</h4>
            <ul style="list-style: none; padding: 0; color: #95a5a6; font-size: 13px;">
                <li style="margin-bottom: 5px;">⚔️ <strong>Beams:</strong> Direct damage weapons</li>
                <li style="margin-bottom: 5px;">🚀 <strong>Torpedoes:</strong> Long-range missiles</li>
            </ul>
        </div>
        <div>
            <h4 style="color: #3498db; margin: 0 0 8px 0; font-size: 14px;">Defensive</h4>
            <ul style="list-style: none; padding: 0; color: #95a5a6; font-size: 13px;">
                <li style="margin-bottom: 5px;">🛡️ <strong>Shields:</strong> Energy-based protection</li>
                <li style="margin-bottom: 5px;">🔰 <strong>Armor:</strong> Physical damage reduction</li>
                <li style="margin-bottom: 5px;">🛡️ <strong>Hull:</strong> Cargo capacity & structure</li>
            </ul>
        </div>
        <div>
            <h4 style="color: #9b59b6; margin: 0 0 8px 0; font-size: 14px;">Utility</h4>
            <ul style="list-style: none; padding: 0; color: #95a5a6; font-size: 13px;">
                <li style="margin-bottom: 5px;">⚡ <strong>Engines:</strong> Speed & maneuverability</li>
                <li style="margin-bottom: 5px;">🔋 <strong>Power:</strong> Energy generation</li>
                <li style="margin-bottom: 5px;">💻 <strong>Computer:</strong> Targeting systems</li>
                <li style="margin-bottom: 5px;">📡 <strong>Sensors:</strong> Detection range</li>
                <li style="margin-bottom: 5px;">👻 <strong>Cloak:</strong> Stealth capability</li>
            </ul>
        </div>
    </div>
</div>

<div style="margin-top: 20px; padding: 15px; background: rgba(230, 126, 34, 0.1); border-left: 4px solid #e67e22; border-radius: 5px;">
    <p style="color: #e0e0e0; margin: 0; font-size: 14px;">
        <strong>💡 Tips:</strong>
    </p>
    <ul style="color: #95a5a6; font-size: 13px; margin: 10px 0 0 20px;">
        <li>Upgrade costs increase exponentially - plan your build carefully!</li>
        <li>Balanced upgrades are often more effective than specializing too early</li>
        <li>Downgrading refunds 50% - useful for respeccing your ship</li>
        <li>Higher levels provide greater benefits but become very expensive</li>
    </ul>
</div>

<div style="margin-top: 20px;">
    <a href="/main" class="btn">← Back to Main</a>
    <a href="/status" class="btn">View Ship Status</a>
</div>
