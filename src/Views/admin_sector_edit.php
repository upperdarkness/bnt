<?php
$title = 'Edit Sector - Admin - BlackNova Traders';
$showHeader = false;
ob_start();
?>

<style>
    .admin-nav {
        background: rgba(231, 76, 60, 0.2);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid rgba(231, 76, 60, 0.5);
    }

    .admin-nav a {
        color: #e74c3c;
        margin-right: 15px;
        text-decoration: none;
        padding: 8px 15px;
        border-radius: 5px;
        transition: background 0.3s;
    }

    .admin-nav a:hover {
        background: rgba(231, 76, 60, 0.3);
    }
</style>

<h2 style="color: #e74c3c;">🛡️ Edit Sector: <?= (int)$sector['sector_id'] ?></h2>

<div class="admin-nav">
    <a href="/admin">Dashboard</a>
    <a href="/admin/players">Players</a>
    <a href="/admin/teams">Teams</a>
    <a href="/admin/universe"><strong>Universe</strong></a>
    <a href="/admin/settings">Settings</a>
    <a href="/admin/logs">Logs</a>
    <a href="/admin/statistics">Statistics</a>
</div>

<div style="background: rgba(15, 76, 117, 0.2); padding: 30px; border-radius: 8px; max-width: 1000px;">
    <form action="/admin/universe/sector/<?= (int)$sector['sector_id'] ?>/update" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
            <div>
                <h3>Sector Information</h3>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Sector ID:</label>
                    <input type="text" value="<?= (int)$sector['sector_id'] ?>" disabled style="background: rgba(127, 140, 141, 0.2);">
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">Sector ID cannot be changed</small>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Sector Name:</label>
                    <input type="text" name="sector_name" value="<?= htmlspecialchars($sector['sector_name'] ?? '') ?>" style="width: 100%;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Zone ID:</label>
                    <input type="number" name="zone_id" value="<?= (int)($sector['zone_id'] ?? 1) ?>" min="1" style="width: 100%;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Port Type:</label>
                    <?php
                    $currentPortType = $sector['port_type'] ?? 'none';
                    if (empty($currentPortType) || $currentPortType === '') {
                        $currentPortType = 'none';
                    }
                    ?>
                    <select name="port_type" style="width: 100%; padding: 8px;">
                        <option value="none" <?= $currentPortType === 'none' ? 'selected' : '' ?>>None</option>
                        <option value="ore" <?= $currentPortType === 'ore' ? 'selected' : '' ?>>Ore</option>
                        <option value="organics" <?= $currentPortType === 'organics' ? 'selected' : '' ?>>Organics</option>
                        <option value="goods" <?= $currentPortType === 'goods' ? 'selected' : '' ?>>Goods</option>
                        <option value="energy" <?= $currentPortType === 'energy' ? 'selected' : '' ?>>Energy</option>
                        <option value="special" <?= $currentPortType === 'special' ? 'selected' : '' ?>>Special</option>
                    </select>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">
                        <input type="checkbox" name="is_starbase" value="1" <?= ($sector['is_starbase'] ?? false) ? 'checked' : '' ?>>
                        Starbase (Safe Zone)
                    </label>
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        Starbases prohibit combat and allow ship upgrades
                    </small>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Beacon Message:</label>
                    <textarea name="beacon" rows="3" style="width: 100%;"><?= htmlspecialchars($sector['beacon'] ?? '') ?></textarea>
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        Beacon message displayed to players entering this sector
                    </small>
                </div>
            </div>

            <div>
                <h3>Port Inventory</h3>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Ore:</label>
                    <input type="number" name="port_ore" value="<?= (int)($sector['port_ore'] ?? 0) ?>" min="0" style="width: 100%;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Organics:</label>
                    <input type="number" name="port_organics" value="<?= (int)($sector['port_organics'] ?? 0) ?>" min="0" style="width: 100%;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Goods:</label>
                    <input type="number" name="port_goods" value="<?= (int)($sector['port_goods'] ?? 0) ?>" min="0" style="width: 100%;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Energy:</label>
                    <input type="number" name="port_energy" value="<?= (int)($sector['port_energy'] ?? 0) ?>" min="0" style="width: 100%;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px;">Colonists:</label>
                    <input type="number" name="port_colonists" value="<?= (int)($sector['port_colonists'] ?? 0) ?>" min="0" style="width: 100%;">
                </div>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(52, 152, 219, 0.2);">
            <h3>Planets in This Sector</h3>
            
            <?php if (!empty($planets)): ?>
            <div style="margin-bottom: 20px;">
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Planet Name</th>
                            <th>Owner</th>
                            <th>Base</th>
                            <th>Colonists</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($planets as $planet): ?>
                        <tr>
                            <td><?= htmlspecialchars($planet['planet_name']) ?></td>
                            <td>
                                <?php if ($planet['owner'] && $planet['owner'] != 0): ?>
                                    <span style="color: #e74c3c;"><?= htmlspecialchars($planet['owner_name'] ?? 'Unknown') ?></span>
                                <?php else: ?>
                                    <span style="color: #7f8c8d;">Unowned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($planet['base'] ?? false): ?>
                                    <span style="color: #2ecc71;">Yes</span>
                                <?php else: ?>
                                    <span style="color: #7f8c8d;">No</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($planet['colonists'] ?? 0) ?></td>
                            <td>
                                <?php if (!$planet['owner'] || $planet['owner'] == 0): ?>
                                <form action="/admin/universe/sector/<?= (int)$sector['sector_id'] ?>/planet/<?= (int)$planet['planet_id'] ?>/delete" method="post" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                                    <button type="submit" class="btn" style="padding: 5px 10px; font-size: 12px; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;"
                                            onclick="return confirm('Are you sure you want to delete planet <?= htmlspecialchars($planet['planet_name']) ?>?');">
                                        Delete
                                    </button>
                                </form>
                                <?php else: ?>
                                <span style="color: #7f8c8d; font-size: 12px;">Cannot delete owned planet</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="color: #7f8c8d;">No planets currently exist in this sector.</p>
            <?php endif; ?>

            <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; margin-top: 20px;">
                <h4>Create New Planet</h4>
                <form action="/admin/universe/sector/<?= (int)$sector['sector_id'] ?>/planet/create" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <label style="white-space: nowrap;">Planet Name:</label>
                        <input type="text" name="planet_name" required style="width: 300px;" placeholder="Enter planet name">
                        <button type="submit" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                            Create Planet
                        </button>
                    </div>
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        New planets will be unowned and have random initial resources.
                    </small>
                </form>
            </div>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(52, 152, 219, 0.2);">
            <h3>Sector Statistics</h3>
            <table style="width: 100%;">
                <tr>
                    <td><strong>Linked Sectors:</strong></td>
                    <td><?= count($linkedSectors) ?></td>
                </tr>
            </table>
        </div>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(52, 152, 219, 0.2);">
            <h3>Navigation Links</h3>
            
            <?php if (!empty($linkedSectors)): ?>
            <div style="margin-bottom: 20px;">
                <h4>Current Links</h4>
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Sector ID</th>
                            <th>Name</th>
                            <th>Port Type</th>
                            <th>Starbase</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($linkedSectors as $link): ?>
                        <tr>
                            <td><?= (int)$link['sector_id'] ?></td>
                            <td><?= htmlspecialchars($link['sector_name']) ?></td>
                            <td>
                                <?php if ($link['port_type'] && $link['port_type'] !== 'none'): ?>
                                    <span style="color: #2ecc71;"><?= htmlspecialchars(ucfirst($link['port_type'])) ?></span>
                                <?php else: ?>
                                    <span style="color: #7f8c8d;">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($link['is_starbase'] ?? false): ?>
                                    <span style="color: #2ecc71;">🛡️ Yes</span>
                                <?php else: ?>
                                    <span style="color: #7f8c8d;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/universe/sector/<?= (int)$link['sector_id'] ?>" class="btn" style="padding: 5px 10px; font-size: 12px; margin-right: 5px;">
                                    View
                                </a>
                                <form action="/admin/universe/sector/<?= (int)$sector['sector_id'] ?>/link/delete" method="post" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                                    <input type="hidden" name="target_sector" value="<?= (int)$link['sector_id'] ?>">
                                    <button type="submit" class="btn" style="padding: 5px 10px; font-size: 12px; background: rgba(231, 76, 60, 0.3); border-color: #e74c3c;"
                                            onclick="return confirm('Are you sure you want to remove the link to Sector <?= (int)$link['sector_id'] ?>?');">
                                        Remove Link
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p style="color: #7f8c8d;">No navigation links currently exist for this sector.</p>
            <?php endif; ?>

            <div style="background: rgba(15, 76, 117, 0.3); padding: 20px; border-radius: 8px; margin-top: 20px;">
                <h4>Create New Link</h4>
                <form action="/admin/universe/sector/<?= (int)$sector['sector_id'] ?>/link/create" method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <label style="white-space: nowrap;">Link to Sector ID:</label>
                        <input type="number" name="target_sector" min="1" required style="width: 150px;">
                        <button type="submit" class="btn" style="background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                            Create Link
                        </button>
                    </div>
                    <small style="color: #7f8c8d; display: block; margin-top: 5px;">
                        Links are bidirectional. Creating a link from Sector <?= (int)$sector['sector_id'] ?> to Sector X will also create a link from Sector X back to Sector <?= (int)$sector['sector_id'] ?>.
                    </small>
                </form>
            </div>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 30px;">
            <button type="submit" class="btn" style="flex: 1; background: rgba(46, 204, 113, 0.3); border-color: #2ecc71;">
                Update Sector
            </button>
            <a href="/admin/universe" class="btn" style="flex: 1; text-align: center;">
                Back to Universe
            </a>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
?>
