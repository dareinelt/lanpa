<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Standorte</h1><p class="page-subtitle text-muted mb-0"><?= $total ?> Standorte · Standort → Gebäude → Etage → Raum → Arbeitsplatz</p></div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="/locations<?= $showInactive ? '' : '?inactive=1' ?>"><?= $showInactive ? 'Nur aktive anzeigen' : 'Inaktive anzeigen' ?></a>
        <?php if ($can('locations.manage')): ?><a class="btn btn-primary" href="/locations/new"><?= icon('plus') ?> Standort anlegen</a><?php endif; ?>
    </div>
</div>
<div class="card">
    <?php if (!$tree): ?>
        <div class="empty-state"><?= icon('map') ?><p>Noch keine Standorte angelegt.</p></div>
    <?php else: ?>
        <div class="tree"><?php $nodes = $tree; include __DIR__ . '/_tree.php'; ?></div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
