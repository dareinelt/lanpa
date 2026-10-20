<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$exportQuery = http_build_query(array_filter(array_merge($query, ['page' => null, 'per_page' => null, 'format' => 'csv']), static fn ($v) => $v !== null && $v !== ''));
?>
<div class="page-header">
    <div>
        <nav class="breadcrumb" aria-label="Pfad"><a href="/reports">Berichte</a> <?= icon('chevron-right') ?> <span>Inventarliste</span></nav>
        <h1>Inventarliste</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Assets</p>
    </div>
    <div class="page-actions">
        <?php if ($canExport): ?><a class="btn btn-primary" href="/reports/inventory?<?= e($exportQuery) ?>"><?= icon('download') ?> CSV exportieren</a><?php endif; ?>
    </div>
</div>
<form method="get" action="/reports/inventory" class="filter-bar" role="search">
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Inventarnr., Seriennr., Bezeichnung, Mitarbeiter …">
    </div>
    <div class="form-group">
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status" data-autosubmit>
            <option value="active"<?= selected($filters['status'], 'active') ?>>Aktive (nicht endgültig)</option>
            <?php foreach ($statuses as $s): ?><option value="<?= e($s['code']) ?>"<?= selected($filters['status'], $s['code']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            <option value="unassigned"<?= selected($filters['status'], 'unassigned') ?>>Ohne Mitarbeiter</option>
            <option value="final"<?= selected($filters['status'], 'final') ?>>Ausgemustert / Entsorgt</option>
            <option value="all"<?= selected($filters['status'], 'all') ?>>Alle</option>
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
        <label for="filter-employee">Mitarbeiter</label>
        <select id="filter-employee" name="employee_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected($filters['employee_id'], $emp['id']) ?>><?= e($emp['display_name']) ?></option><?php endforeach; ?>
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
    <div class="form-group">
        <label for="filter-legacy">Altbestand</label>
        <select id="filter-legacy" name="legacy" data-autosubmit>
            <option value="">Alle</option>
            <option value="1"<?= selected($filters['legacy'], '1') ?>>Nur Altbestand</option>
            <option value="0"<?= selected($filters['legacy'], '0') ?>>Ohne Altbestand</option>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-missing">Fehlende Angabe</label>
        <select id="filter-missing" name="missing" data-autosubmit>
            <option value="">–</option>
            <option value="location"<?= selected($filters['missing'], 'location') ?>>Ohne Standort</option>
            <option value="cost_center"<?= selected($filters['missing'], 'cost_center') ?>>Ohne Kostenstelle</option>
            <option value="serial"<?= selected($filters['missing'], 'serial') ?>>Ohne Seriennummer</option>
            <option value="purchase_date"<?= selected($filters['missing'], 'purchase_date') ?>>Ohne Kaufdatum</option>
            <option value="purchase_price"<?= selected($filters['missing'], 'purchase_price') ?>>Ohne Kaufpreis</option>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if (array_filter(array_diff_key($filters, ['status' => 1]), static fn ($v) => $v !== '' && $v !== null)): ?>
        <a class="btn btn-ghost" href="/reports/inventory"><?= icon('x') ?> Zurücksetzen</a>
    <?php endif; ?>
</form>
<div class="card card-flush">
    <?php if (!$rows): ?>
        <div class="table-empty">Keine Assets für diese Auswahl.</div>
    <?php else: ?>
    <div class="table-wrapper">
    <table class="table table-compact">
        <thead><tr>
            <th><?= sort_link($basePath, $query, 'inventory_number', 'Inventarnr.') ?></th>
            <th><?= sort_link($basePath, $query, 'type', 'Typ') ?></th>
            <th><?= sort_link($basePath, $query, 'name', 'Bezeichnung') ?></th>
            <th>Seriennummer</th>
            <th><?= sort_link($basePath, $query, 'status', 'Status') ?></th>
            <th><?= sort_link($basePath, $query, 'employee', 'Mitarbeiter') ?></th>
            <th><?= sort_link($basePath, $query, 'location', 'Standort') ?></th>
            <th>Kostenst.</th>
            <th><?= sort_link($basePath, $query, 'purchase_date', 'Kaufdatum') ?></th>
            <th class="text-right">Kaufpreis</th>
            <th><?= sort_link($basePath, $query, 'warranty_until', 'Garantie') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $warrantyExpired = $r['warranty_until'] !== null && $r['warranty_until'] < date('Y-m-d'); ?>
            <tr class="is-clickable<?= (int) $r['status_final'] ? ' is-muted' : '' ?>" data-href="/assets/<?= (int) $r['id'] ?>">
                <td class="mono nowrap"><strong><?= e($r['inventory_number']) ?></strong></td>
                <td class="nowrap"><?= icon($r['asset_type_icon'] ?: 'box', 'icon icon-muted') ?> <?= e($r['asset_type_name']) ?></td>
                <td class="nowrap"><?= e($r['name'] ?: ($r['article_name'] ?? '–')) ?><br><span class="text-muted text-xs"><?= e($r['manufacturer_name'] ?? '') ?></span></td>
                <td class="mono text-sm"><?= e($r['serial_number'] ?? '–') ?></td>
                <td><?= badge($r['status_name'], $r['status_color']) ?></td>
                <td class="nowrap"><?= $r['employee_name'] ? e($r['employee_name']) : '<span class="text-muted">–</span>' ?></td>
                <?php $segments = $r['location_path'] ? explode(' / ', $r['location_path']) : []; ?>
                <td class="text-sm nowrap"><?= $segments ? '<span title="' . e($r['location_path']) . '">' . e(end($segments)) . '</span>' . (count($segments) > 1 ? '<br><span class="text-muted text-xs">' . e(implode(' / ', array_slice($segments, 0, -1))) . '</span>' : '') : '–' ?></td>
                <td class="mono"><?= e($r['cost_center_number'] ?? '–') ?></td>
                <td class="text-sm nowrap"><?= fmt_date($r['purchase_date']) ?></td>
                <td class="text-right nowrap"><?= fmt_money($r['purchase_price']) ?></td>
                <td class="text-sm nowrap<?= $warrantyExpired ? ' text-danger' : '' ?>"><?= fmt_date($r['warranty_until']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
