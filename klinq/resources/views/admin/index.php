<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <h1 class="page-title">Administration</h1>
        <p class="text-muted mb-0">Systemweite Einstellungen und Protokolle.</p>
    </div>
</div>
<div class="grid grid-3">
    <?php foreach ($areas as $area): ?>
    <a class="card admin-tile" href="<?= e($area['href']) ?>">
        <span class="admin-tile-icon"><?= icon($area['icon']) ?></span>
        <h2><?= e($area['title']) ?></h2>
        <p class="text-muted mb-0"><?= e($area['text']) ?></p>
    </a>
    <?php endforeach; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php';
