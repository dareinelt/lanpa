<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Offene Vorgänge</h1><p class="page-subtitle text-muted mb-0">Unvollständige Entnahmen und Retouren zur Nachbearbeitung · <?= $paginator->total ?> offen</p></div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/movements"><?= icon('history') ?> Alle Bewegungen</a>
        <?php if ($can('movements.checkout')): ?><a class="btn btn-primary" href="/m"><?= icon('qr') ?> Erfassen</a><?php endif; ?>
    </div>
</div>
<div class="status-tabs" role="tablist" aria-label="Vorgangsart">
    <a class="status-tab<?= $filters['type'] === '' ? ' is-active' : '' ?>" href="<?= e(query_url('/movements/open', $query, ['type' => null, 'page' => null])) ?>" role="tab">Alle <span class="status-tab-count"><?= (int) $summary['open_checkouts'] + (int) $summary['open_returns'] ?></span></a>
    <a class="status-tab<?= $filters['type'] === 'checkout' ? ' is-active' : '' ?>" href="<?= e(query_url('/movements/open', $query, ['type' => 'checkout', 'page' => null])) ?>" role="tab">Entnahmen <span class="status-tab-count"><?= (int) $summary['open_checkouts'] ?></span></a>
    <a class="status-tab<?= $filters['type'] === 'return' ? ' is-active' : '' ?>" href="<?= e(query_url('/movements/open', $query, ['type' => 'return', 'page' => null])) ?>" role="tab">Retouren <span class="status-tab-count"><?= (int) $summary['open_returns'] ?></span></a>
</div>
<form method="get" action="/movements/open" class="filter-bar" role="search">
    <input type="hidden" name="type" value="<?= e($filters['type']) ?>">
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Inventarnr., Seriennr., Mitarbeiter, Notiz …">
    </div>
    <div class="form-group">
        <label for="filter-from">Von</label>
        <input id="filter-from" type="date" name="date_from" value="<?= e($filters['date_from']) ?>" data-autosubmit>
    </div>
    <div class="form-group">
        <label for="filter-to">Bis</label>
        <input id="filter-to" type="date" name="date_to" value="<?= e($filters['date_to']) ?>" data-autosubmit>
    </div>
    <?php $withType = false; $withMissing = true; include __DIR__ . '/_filters.php'; ?>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if (array_filter(array_intersect_key($filters, array_flip(['q', 'date_from', 'date_to', 'employee_id', 'location_id', 'cost_center_id', 'asset_type_id', 'missing'])))): ?>
        <a class="btn btn-ghost" href="/movements/open?type=<?= e($filters['type']) ?>"><?= icon('x') ?> Zurücksetzen</a>
    <?php endif; ?>
</form>
<?php $showStatus = false; $showMissing = true; $emptyText = 'Keine offenen Vorgänge – alles erledigt.'; include __DIR__ . '/_table.php'; ?>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
