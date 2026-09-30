<?php
$title = 'Galaxy Map - BlackNova Traders';
$showHeader = true;
ob_start();
?>
<div class="galaxy-heading">
    <div><p class="space-eyebrow">NAVIGATION COMPUTER</p><h2>Galaxy map</h2></div>
    <span class="map-position">You are in sector <?= $currentSector ?></span>
</div>
<p class="map-intro">Explore <?= count($galaxy['sectors']) ?> sectors and their warp links. Positions are schematic; lines show actual routes.</p>
<div class="galaxy-layout" id="galaxy-app">
    <div class="galaxy-chart">
        <div class="map-controls" aria-label="Map controls" hidden>
            <button type="button" data-map-action="in" aria-label="Zoom in">+</button>
            <button type="button" data-map-action="out" aria-label="Zoom out">−</button>
            <button type="button" data-map-action="home">My sector</button>
            <button type="button" data-map-action="fit">Fit galaxy</button>
            <label><input type="checkbox" id="map-all-links"> All links</label>
        </div>
        <canvas id="galaxy-canvas" tabindex="0" role="img" aria-label="Interactive galaxy map. Drag to pan, use zoom buttons, or select a sector using the search form. Arrow keys pan; plus and minus zoom; Home returns to your sector."></canvas>
        <div class="map-legend"><span class="legend-current">● Your sector</span><span class="legend-base">◆ Starbase</span><span class="legend-port">● Trading port</span><span>● Empty sector</span></div>
        <noscript><p class="map-noscript">Enable JavaScript for the interactive map. Sector search and linked-sector movement below still work.</p></noscript>
    </div>
    <aside class="map-panel" aria-label="Sector details">
        <form method="get" action="/galaxy" id="map-search">
            <label for="map-sector">Find a sector</label>
            <div class="map-search-row"><input id="map-sector" name="sector" type="number" min="1" value="<?= $selected['id'] ?? $currentSector ?>" required><button class="btn" type="submit">Find</button></div>
        </form>
        <p id="map-feedback" role="status" aria-live="polite"></p>
        <div id="map-details" aria-live="polite">
            <p class="space-eyebrow" id="map-sector-label">SECTOR <?= $selected['id'] ?? '—' ?></p>
            <h3 id="map-sector-name"><?= htmlspecialchars($selected['name'] ?? 'No sectors available') ?></h3>
            <p id="map-sector-kind"><?= $selected ? ($selected['starbase'] ? 'Protected starbase' : ($selected['port'] === 'none' ? 'Empty space' : ucfirst($selected['port']) . ' port')) : 'Create a universe to get started.' ?></p>
            <p id="map-route-note"><?= $selected && $selected['id'] === $currentSector ? 'You are here.' : 'Movement is available only along an outgoing link from your current sector.' ?></p>
        </div>
        <form id="map-move" action="/move/<?= $selected['id'] ?? $currentSector ?>" method="post" <?= !$selected || !in_array($selected['id'], $linkedIds, true) ? 'hidden' : '' ?>>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
            <button class="btn" type="submit">Warp to selected sector</button>
        </form>
        <h4>Outgoing links from your sector</h4>
        <div class="map-neighbors">
        <?php foreach ($links as $link): ?>
            <a href="/galaxy?sector=<?= (int)$link['sector_id'] ?>" data-select-sector="<?= (int)$link['sector_id'] ?>">Sector <?= (int)$link['sector_id'] ?><?= $link['is_starbase'] ? ' · Starbase' : '' ?></a>
        <?php endforeach; ?>
        <?php if (!$links): ?><p>No outgoing warp links.</p><?php endif; ?>
        </div>
        <p class="map-help">Drag to pan. Scroll or use +/− to zoom. Select a dot to inspect a sector. Arrows show outgoing routes from your selection.</p>
    </aside>
</div>
<script type="application/json" id="galaxy-data"><?= json_encode($galaxy + ['current' => $currentSector, 'selected' => $selected['id'] ?? null], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="/assets/js/galaxy.js" defer></script>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
