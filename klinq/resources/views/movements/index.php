<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$ranges = ['today' => 'Heute', 'yesterday' => 'Gestern', 'week' => 'Diese Woche', 'month' => 'Dieser Monat', 'all' => 'Alle'];
?>
<div class="page-header">
    <div><h1>Bewegungen</h1><p class="page-subtitle text-muted mb-0">Entnahmen und Retouren<?= $filters['date_from'] || $filters['date_to'] ? ' vom ' . e(fmt_date($filters['date_from'] ?: null, '…')) . ' bis ' . e(fmt_date($filters['date_to'] ?: null, '…')) : '' ?> · <?= $paginator->total ?> Einträge</p></div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/movements/open"><?= icon('clock') ?> Offene Vorgänge</a>
        <?php if ($can('movements.checkout')): ?><a class="btn btn-primary" href="/m"><?= icon('qr') ?> Erfassen</a><?php endif; ?>
    </div>
</div>
<section class="grid grid-4 mb-4" aria-label="Zusammenfassung">
    <div class="card stat-card is-info"><span class="stat-value"><?= (int) $summary['checkouts'] ?></span><span class="stat-label">Entnahmen im Zeitraum</span></div>
    <div class="card stat-card is-success"><span class="stat-value"><?= (int) $summary['returns'] ?></span><span class="stat-label">Retouren im Zeitraum</span></div>
    <a class="card stat-card<?= $summary['open_checkouts'] > 0 ? ' is-warning' : '' ?>" href="/movements/open?type=checkout"><span class="stat-value"><?= (int) $summary['open_checkouts'] ?></span><span class="stat-label">Offene Entnahmen (gesamt)</span></a>
    <a class="card stat-card<?= $summary['open_returns'] > 0 ? ' is-warning' : '' ?>" href="/movements/open?type=return"><span class="stat-value"><?= (int) $summary['open_returns'] ?></span><span class="stat-label">Offene Retouren (gesamt)</span></a>
</section>
<div class="status-tabs" role="tablist" aria-label="Zeitraum">
    <?php foreach ($ranges as $key => $label): ?>
        <a class="status-tab<?= $range === $key ? ' is-active' : '' ?>" href="<?= e(query_url('/movements', $query, ['range' => $key, 'date_from' => null, 'date_to' => null, 'page' => null])) ?>" role="tab"><?= e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($range === 'custom'): ?><span class="status-tab is-active">Zeitraum</span><?php endif; ?>
</div>
<form method="get" action="/movements" class="filter-bar" role="search">
    <input type="hidden" name="range" value="custom">
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
    <?php $withStatus = true; include __DIR__ . '/_filters.php'; ?>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if (array_filter(array_intersect_key($filters, array_flip(['q', 'type', 'status', 'employee_id', 'location_id', 'cost_center_id', 'asset_type_id'])))): ?>
        <a class="btn btn-ghost" href="/movements?range=<?= e($range === 'custom' ? 'today' : $range) ?>"><?= icon('x') ?> Zurücksetzen</a>
    <?php endif; ?>
</form>
<?php include __DIR__ . '/_table.php'; ?>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
