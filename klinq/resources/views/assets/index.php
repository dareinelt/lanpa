<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Assets</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Einträge</p></div>
    <div class="page-actions">
        <?php if ($can('labels.print') && $rows): ?><a class="btn btn-secondary" href="/labels?<?= e(http_build_query(array_filter(array_merge($query, ['page' => null]), static fn ($v) => $v !== null && $v !== ''))) ?>"><?= icon('print') ?> Etiketten</a><?php endif; ?>
        <?php if ($can('assets.manage')): ?><a class="btn btn-primary" href="/assets/new"><?= icon('plus') ?> Asset anlegen</a><?php endif; ?>
    </div>
</div>
<div class="status-tabs" role="tablist" aria-label="Status">
    <?php
    $finalCodes = array_column(array_filter($statuses, static fn (array $s): bool => (int) $s['is_final'] === 1), 'code');
    $tabs = [['active', 'Aktive', array_sum(array_filter($statusCounts, static fn (string $k): bool => !in_array($k, $finalCodes, true), ARRAY_FILTER_USE_KEY))]];
    foreach ($statuses as $s) { $tabs[] = [$s['code'], $s['name'], $statusCounts[$s['code']] ?? 0]; }
    $tabs[] = ['all', 'Alle', array_sum($statusCounts)];
    foreach ($tabs as [$code, $label, $count]): ?>
        <a class="status-tab<?= ($filters['status'] ?? 'active') === $code ? ' is-active' : '' ?>" href="<?= e(query_url('/assets', $query, ['status' => $code, 'page' => null])) ?>" role="tab"><?= e($label) ?> <span class="status-tab-count"><?= (int) $count ?></span></a>
    <?php endforeach; ?>
</div>
<form method="get" action="/assets" class="filter-bar" role="search">
    <input type="hidden" name="status" value="<?= e($filters['status'] ?? '') ?>">
    <?php foreach (['employee_id', 'article_id', 'supplier_id', 'parent_asset_id', 'purchase_order_id', 'asset_category_id'] as $hidden): if (!empty($filters[$hidden])): ?><input type="hidden" name="<?= $hidden ?>" value="<?= (int) $filters[$hidden] ?>"><?php endif; endforeach; ?>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Inventarnr., Seriennr., MAC, IMEI, Bezeichnung, Mitarbeiter …">
    </div>
    <div class="form-group">
        <label for="filter-type">Assettyp</label>
        <select id="filter-type" name="asset_type_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($filters['asset_type_id'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-location">Standort (inkl. untergeordnete)</label>
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
        <label for="filter-manufacturer">Hersteller</label>
        <select id="filter-manufacturer" name="manufacturer_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($manufacturers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($filters['manufacturer_id'], $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-warranty">Garantie</label>
        <select id="filter-warranty" name="warranty" data-autosubmit>
            <option value="">Alle</option>
            <option value="valid"<?= selected($filters['warranty'], 'valid') ?>>Gültig</option>
            <option value="expiring"<?= selected($filters['warranty'], 'expiring') ?>>Läuft in 90 Tagen ab</option>
            <option value="expired"<?= selected($filters['warranty'], 'expired') ?>>Abgelaufen</option>
            <option value="none"<?= selected($filters['warranty'], 'none') ?>>Ohne Angabe</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php $activeFilters = array_filter(array_intersect_key($filters, array_flip(['q', 'asset_type_id', 'location_id', 'cost_center_id', 'manufacturer_id', 'warranty', 'employee_id', 'article_id', 'supplier_id', 'parent_asset_id', 'purchase_order_id', 'asset_category_id']))); if ($activeFilters): ?>
        <a class="btn btn-ghost" href="/assets?status=<?= e($filters['status'] ?? '') ?>"><?= icon('x') ?> Zurücksetzen</a>
    <?php endif; ?>
</form>
<?php if ($filterContext): ?>
    <div class="filter-context">
        <?php foreach ($filterContext as $key => $label): ?>
            <a class="badge badge-info badge-lg" href="<?= e(query_url('/assets', $query, [$key => null, 'page' => null])) ?>" title="Filter entfernen"><?= e($label) ?> ✕</a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Assets gefunden.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr>
            <th><?= sort_link('/assets', $query, 'inventory_number', 'Inventarnr.') ?></th>
            <th><?= sort_link('/assets', $query, 'type', 'Typ') ?></th>
            <th><?= sort_link('/assets', $query, 'name', 'Bezeichnung') ?></th>
            <th>Seriennummer</th>
            <th><?= sort_link('/assets', $query, 'employee', 'Mitarbeiter') ?></th>
            <th><?= sort_link('/assets', $query, 'location', 'Standort') ?></th>
            <th>Kostenst.</th>
            <th><?= sort_link('/assets', $query, 'warranty_until', 'Garantie') ?></th>
            <th><?= sort_link('/assets', $query, 'status', 'Status') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $warrantyExpired = $r['warranty_until'] !== null && $r['warranty_until'] < date('Y-m-d'); ?>
            <tr class="is-clickable<?= (int) $r['status_final'] ? ' is-muted' : '' ?>" data-href="/assets/<?= (int) $r['id'] ?>">
                <td class="mono"><strong><?= e($r['inventory_number']) ?></strong><?= (int) $r['is_legacy'] ? ' <span class="text-muted text-xs" title="Nachinventarisierter Altbestand">Alt</span>' : '' ?></td>
                <td><?= icon($r['asset_type_icon'] ?: 'box', 'icon icon-muted') ?> <?= e($r['category_name'] ?? $r['asset_type_name']) ?></td>
                <td><?= e($r['name'] ?: ($r['article_name'] ?? '–')) ?><?= $r['manufacturer_name'] ? '<br><span class="text-muted text-sm">' . e($r['manufacturer_name']) . '</span>' : '' ?></td>
                <td class="mono text-sm"><?= e($r['serial_number'] ?? '–') ?></td>
                <td><?= $r['employee_name'] ? e($r['employee_name']) : '<span class="text-muted">–</span>' ?></td>
                <td class="text-sm"><?= e($r['location_path'] ?? '–') ?></td>
                <td class="mono"><?= e($r['cost_center_number'] ?? '–') ?></td>
                <td class="text-sm<?= $warrantyExpired ? ' text-danger' : '' ?>"><?= fmt_date($r['warranty_until']) ?></td>
                <td><?= badge($r['status_name'], $r['status_color']) ?></td>
                <td class="table-actions"><?php if ($can('assets.manage')): ?><a class="btn btn-ghost btn-sm" href="/assets/<?= (int) $r['id'] ?>/edit" title="Bearbeiten"><?= icon('pen') ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<dialog id="scan-choice-dialog" class="dialog" data-auto-open-if-query="scan">
    <h2><?= icon('qr') ?> Scannen</h2>
    <p class="text-muted">Die Scan-Funktion ist nur auf Mobilgeräten verfügbar. Bitte den gewünschten Vorgang für die manuelle Erfassung am Desktop wählen:</p>
    <div class="form-actions">
        <?php if ($can('movements.checkout')): ?><a class="btn btn-primary" href="/movements/checkout"><?= icon('checkout') ?> Entnahme</a><?php endif; ?>
        <?php if ($can('movements.return')): ?><a class="btn btn-primary" href="/movements/return"><?= icon('return') ?> Retoure</a><?php endif; ?>
        <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
    </div>
</dialog>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
