<?php
$title = 'Galactic News - BlackNova Traders';
$showHeader = true;
ob_start();
?>
<h2>Galactic News</h2>
<p>The latest ship destructions and planet captures across the galaxy.</p>
<p style="margin-bottom: 15px;">
    <a class="btn" href="/news">All</a>
    <a class="btn" href="/news?source=journalist">From the Courier</a>
    <a class="btn" href="/news?source=system">Wire reports</a>
</p>
<?php if (!$items): ?>
<div class="alert alert-info">The galaxy is quiet. No news has been reported yet.</div>
<?php else: ?>
<?php foreach ($items as $item): ?>
<article style="margin: 20px 0; padding: 16px; border: 1px solid #3282b8; border-radius: 8px;">
    <h3><?= htmlspecialchars($item['headline'], ENT_QUOTES, 'UTF-8') ?></h3>
    <p><?= htmlspecialchars($item['newstext'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    <small>
        <?php if (($item['source'] ?? 'system') === 'journalist' && !empty($item['byline']) && ($item['news_type'] ?? '') !== 'retraction'): ?>
            By <?= htmlspecialchars(preg_replace('/^\[[^\]]*\]\s*/', '', $item['byline']), ENT_QUOTES, 'UTF-8') ?>, Galactic Courier &middot;
        <?php endif; ?>
        <?= htmlspecialchars($item['date'], ENT_QUOTES, 'UTF-8') ?> (server time)
    </small>
</article>
<?php endforeach; ?>
<?php endif; ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
