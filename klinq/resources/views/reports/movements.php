<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isReturn = $type === 'return';
$exportQuery = http_build_query(array_filter(array_merge($query, ['page' => null, 'per_page' => null, 'format' => 'csv', 'date_from' => $filters['date_from'], 'date_to' => $filters['date_to']]), static fn ($v) => $v !== null && $v !== ''));
$totalInPeriod = array_sum(array_map(static fn (array $m): int => (int) $m[$isReturn ? 'returns_' : 'checkouts'], $summary['by_month']));
$damagedInPeriod = array_sum(array_map(static fn (array $m): int => (int) $m['damaged'], $summary['by_month']));
$presets = [
    'Heute' => [date('Y-m-d'), date('Y-m-d')],
    'Diese Woche' => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d')],
    'Dieser Monat' => [date('Y-m-01'), date('Y-m-d')],
    'Letzter Monat' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '90 Tage' => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
    'Dieses Jahr' => [date('Y-01-01'), date('Y-m-d')],
];
?>
<div class="page-header">
    <div>
        <nav class="breadcrumb" aria-label="Pfad"><a href="/reports">Berichte</a> <?= icon('chevron-right') ?> <span><?= e($title) ?></span></nav>
        <h1><?= e($title) ?></h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Vorgänge · <?= fmt_date($filters['date_from']) ?> – <?= fmt_date($filters['date_to']) ?></p>
    </div>
    <div class="page-actions">
        <?php if ($canExport): ?><a class="btn btn-primary" href="<?= e($basePath) ?>?<?= e($exportQuery) ?>"><?= icon('download') ?> CSV exportieren</a><?php endif; ?>
    </div>
</div>

<div class="status-tabs" role="tablist" aria-label="Zeitraum">
    <?php foreach ($presets as $label => [$from, $to]): ?>
        <a class="status-tab<?= $filters['date_from'] === $from && $filters['date_to'] === $to ? ' is-active' : '' ?>" href="<?= e(query_url($basePath, $query, ['date_from' => $from, 'date_to' => $to, 'page' => null])) ?>" role="tab"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e($basePath) ?>" class="filter-bar" role="search">
    <div class="form-group">
        <label for="filter-from">Von</label>
        <input id="filter-from" type="date" name="date_from" value="<?= e($filters['date_from']) ?>">
    </div>
    <div class="form-group">
        <label for="filter-to">Bis</label>
        <input id="filter-to" type="date" name="date_to" value="<?= e($filters['date_to']) ?>">
    </div>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Inventarnr., Bezeichnung, Mitarbeiter, Bemerkung …">
    </div>
    <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status" data-autosubmit>
            <option value=""<?= selected($filters['status'], '') ?>>Alle (ohne stornierte)</option>
            <option value="all"<?= selected($filters['status'], 'all') ?>>Alle inkl. stornierte</option>
            <?php foreach ($statusLabels as $code => $label): ?><option value="<?= e($code) ?>"<?= selected($filters['status'], $code) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-employee">Mitarbeiter</label>
        <select id="filter-employee" name="employee_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected($filters['employee_id'], $emp['id']) ?>><?= e($emp['display_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-type">Assettyp</label>
        <select id="filter-type" name="asset_type_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($filters['asset_type_id'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-location"><?= $isReturn ? 'Einlagerungsort' : 'Standort' ?></label>
        <select id="filter-location" name="location_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected($filters['location_id'], $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-cc">Kostenstelle</label>
        <select id="filter-cc" name="cost_center_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($costCenters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected($filters['cost_center_id'], $c['id']) ?>><?= e($c['number']) ?> – <?= e($c['description']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-source">Quelle</label>
        <select id="filter-source" name="source" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($sources as $code => $label): ?><option value="<?= e($code) ?>"<?= selected($filters['source'], $code) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <a class="btn btn-ghost" href="<?= e($basePath) ?>"><?= icon('x') ?> Zurücksetzen</a>
</form>

<section class="grid grid-4" aria-label="Kennzahlen">
    <div class="card stat-card"><span class="stat-value"><?= $totalInPeriod ?></span><span class="stat-label"><?= $isReturn ? 'Retouren' : 'Entnahmen' ?> im Zeitraum</span></div>
    <?php if ($isReturn): ?>
        <div class="card stat-card <?= $damagedInPeriod > 0 ? 'is-danger' : '' ?>"><span class="stat-value"><?= $damagedInPeriod ?></span><span class="stat-label">mit Schaden</span></div>
        <div class="card stat-card">
            <span class="stat-label">Zustand</span>
            <span class="text-sm">
            <?php $parts = []; foreach ($conditions as $code => $label) { if (!empty($summary['conditions'][$code])) { $parts[] = e($label) . ' <strong>' . (int) $summary['conditions'][$code] . '</strong>'; } } echo $parts ? implode(' · ', $parts) : '<span class="text-muted">–</span>'; ?>
            </span>
        </div>
    <?php else: ?>
        <div class="card stat-card col-span-2">
            <span class="stat-label">Häufigste Empfänger</span>
            <span class="text-sm">
            <?php $parts = array_map(static fn (array $e): string => e($e['display_name']) . ' <strong>' . (int) $e['total'] . '</strong>', array_slice($summary['top_employees'], 0, 5)); echo $parts ? implode(' · ', $parts) : '<span class="text-muted">–</span>'; ?>
            </span>
        </div>
    <?php endif; ?>
    <div class="card stat-card">
        <span class="stat-label">Je Monat</span>
        <span class="text-sm"><?php $parts = array_map(static fn (array $m): string => e(date('m/Y', strtotime($m['month'] . '-01'))) . ' <strong>' . (int) $m[$isReturn ? 'returns_' : 'checkouts'] . '</strong>', array_slice($summary['by_month'], -6)); echo $parts ? implode(' · ', $parts) : '<span class="text-muted">–</span>'; ?></span>
    </div>
</section>

<div class="card card-flush mt-4">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine <?= $isReturn ? 'Retouren' : 'Entnahmen' ?> für diese Auswahl.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table table-compact">
        <thead><tr>
            <th>Datum</th>
            <th>Asset</th>
            <th>Mitarbeiter</th>
            <?php if ($isReturn): ?>
                <th>Zustand</th><th>Einlagerungsort</th><th>Zielstatus</th>
            <?php else: ?>
                <th>Standort</th><th>Kostenstelle</th>
            <?php endif; ?>
            <th>Quelle</th>
            <th>Erfasst von</th>
            <th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $m): ?>
            <tr class="is-clickable" data-href="/movements/<?= (int) $m['id'] ?>">
                <td class="nowrap"><?= fmt_date($m['movement_date']) ?><br><span class="text-muted text-xs"><?= e(\App\Services\ReportService::time($m['movement_at']) ?? '') ?></span></td>
                <td><strong class="mono"><?= e($m['inventory_number']) ?></strong><br><span class="text-muted text-sm"><?= e($m['asset_name'] ?: $m['asset_type_name']) ?></span></td>
                <td><?= e($m['employee_name'] ?? '–') ?><?= $m['employee_department'] ? '<br><span class="text-muted text-xs">' . e($m['employee_department']) . '</span>' : '' ?></td>
                <?php if ($isReturn): ?>
                    <td><?= $m['condition_code'] ? badge($conditions[$m['condition_code']] ?? $m['condition_code'], in_array($m['condition_code'], ['damaged', 'defective'], true) ? 'danger' : ($m['condition_code'] === 'worn' ? 'warning' : 'success')) : '–' ?><?= (int) $m['has_damage'] ? ' ' . badge('Schaden', 'danger') : '' ?></td>
                    <td class="text-sm"><?= e($m['to_location_path'] ?? '–') ?></td>
                    <td class="text-sm"><?= e(\App\Services\MovementService::RETURN_TARGETS[$m['target_status_code']] ?? ($m['target_status_code'] ?? '–')) ?></td>
                <?php else: ?>
                    <td class="text-sm"><?= e($m['to_location_path'] ?? '–') ?></td>
                    <td class="mono"><?= e($m['cost_center_number'] ?? '–') ?></td>
                <?php endif; ?>
                <td class="text-sm"><?= e($sources[$m['source']] ?? $m['source']) ?></td>
                <td class="text-sm"><?= e($m['created_by_name']) ?></td>
                <td><?= $m['status'] === 'open' ? badge('Offen', 'warning') : ($m['status'] === 'cancelled' ? badge('Storniert', 'neutral') : badge('Abgeschlossen', 'success')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
