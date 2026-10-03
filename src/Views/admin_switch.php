<form method="POST" action="/admin/content/toggle" style="display:inline-flex; gap:8px; align-items:center; margin-right:20px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($session->getCsrfToken()) ?>">
    <input type="hidden" name="key" value="<?= htmlspecialchars($switchKey) ?>">
    <input type="hidden" name="value" value="<?= $switchOn ? '0' : '1' ?>">
    <span><?= htmlspecialchars($switchLabel) ?>:
        <strong style="color: <?= $switchOn ? '#2ecc71' : '#e74c3c' ?>;"><?= $switchOn ? 'ON' : 'OFF' ?></strong></span>
    <button class="btn" type="submit" style="padding: 4px 10px;"><?= $switchOn ? 'Turn off' : 'Turn on' ?></button>
</form>
