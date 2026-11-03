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
        <div class="tree-toolbar">
            <div class="search-box tree-search">
                <?= icon('search') ?>
                <input type="search" data-tree-search-input placeholder="Standorte durchsuchen (Name oder Kürzel) …" aria-label="Standorte durchsuchen" autocomplete="off">
            </div>
            <div class="tree-toolbar-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-tree-expand-all><?= icon('chevron-down') ?> Alle aufklappen</button>
                <button type="button" class="btn btn-ghost btn-sm" data-tree-collapse-all><?= icon('chevron-right') ?> Alle einklappen</button>
            </div>
        </div>
        <?php if ($can('locations.manage')): ?>
            <p class="form-hint tree-hint">Am Griff <?= icon('grip', 'icon icon-xs') ?> ziehen, um Standorte zu verschieben: oberhalb oder unterhalb eines Eintrags ablegen ändert die Reihenfolge, mittig ablegen ordnet den Standort unter.</p>
        <?php endif; ?>
        <p class="text-muted tree-empty" data-tree-empty hidden>Keine Standorte gefunden.</p>
        <div class="tree" data-location-tree<?= $can('locations.manage') ? ' data-tree-movable' : '' ?>><?php $nodes = $tree; $treeParentId = null; include __DIR__ . '/_tree.php'; ?></div>
    <?php endif; ?>
</div>
<?php $scripts = ['/js/location-tree.js']; $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>