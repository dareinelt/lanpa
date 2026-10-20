<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$tabs = ['active' => 'Aktiv', 'available' => 'Verfügbar', 'used' => 'Verwendet', 'expiring' => 'Läuft ab', 'expired' => 'Abgelaufen', 'inactive' => 'Deaktiviert', 'all' => 'Alle'];
$tabCount = static fn (string $key): ?int => match ($key) {
    'active' => $counts['all_active'] ?? 0,
    'all' => null,
    default => $counts[$key] ?? 0,
};
$hasFilter = $filters['q'] || $filters['manufacturer_id'] || $filters['supplier_id'] || $filters['expiring'] || $filters['purchase_order_id'] || $filters['asset_id'];
?>
<div class="page-header">
    <div><h1>Lizenzen</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Lizenzen<?= $filters['expiring'] ? ' · laufen in den nächsten ' . (int) $filters['expiring'] . ' Tagen ab' : '' ?></p></div>
    <div class="page-actions">
        <?php if ($can('licenses.manage')): ?><a class="btn btn-primary" href="/licenses/new"><?= icon('plus') ?> Neue Lizenz</a><?php endif; ?>
    </div>
</div>
<div class="grid grid-4 mb-4">
    <div class="card stat-card"><span class="stat-value"><?= (int) $totals['licenses'] ?></span><span class="stat-label">Aktive Lizenzen</span></div>
    <div class="card stat-card is-info"><span class="stat-value"><?= (int) $totals['seats'] - (int) $totals['used'] ?> <span class="stat-sub">/ <?= (int) $totals['seats'] ?></span></span><span class="stat-label">Verfügbare Einheiten</span></div>
    <div class="card stat-card<?= ($counts['expiring'] ?? 0) > 0 ? ' is-warning' : '' ?>"><span class="stat-value"><?= (int) ($counts['expiring'] ?? 0) ?></span><span class="stat-label">Laufen in <?= (int) $expiringDays ?> Tagen ab</span></div>
    <div class="card stat-card<?= ($counts['expired'] ?? 0) > 0 ? ' is-danger' : '' ?>"><span class="stat-value"><?= (int) ($counts['expired'] ?? 0) ?></span><span class="stat-label">Abgelaufen</span></div>
</div>
<div class="status-tabs" role="tablist" aria-label="Status">
    <?php foreach ($tabs as $key => $label): $n = $tabCount($key); ?>
        <a class="status-tab<?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= e(query_url('/licenses', $query, ['status' => $key, 'page' => null, 'expiring' => null])) ?>" role="tab"><?= e($label) ?><?php if ($n !== null): ?> <span class="status-tab-count<?= in_array($key, ['expiring', 'expired'], true) && $n > 0 ? ' is-danger' : '' ?>"><?= $n ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<form method="get" action="/licenses" class="filter-bar" role="search">
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <?php if ($filters['expiring']): ?><input type="hidden" name="expiring" value="<?= e($filters['expiring']) ?>"><?php endif; ?>
    <?php if ($filters['asset_id']): ?><input type="hidden" name="asset_id" value="<?= e($filters['asset_id']) ?>"><?php endif; ?>
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Produkt, Hersteller, Lizenznummer, Schlüssel …">
    </div>
    <div class="form-group">
        <label for="filter-manufacturer">Hersteller</label>
        <select id="filter-manufacturer" name="manufacturer_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($manufacturers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($filters['manufacturer_id'], $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-supplier">Lieferant</label>
        <select id="filter-supplier" name="supplier_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($filters['supplier_id'], $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if ($hasFilter): ?><a class="btn btn-ghost" href="/licenses?status=<?= e($filters['status']) ?>"><?= icon('x') ?> Zurücksetzen</a><?php endif; ?>
</form>
<div class="card card-flush">
<?php if ($rows === []): ?>
    <div class="table-empty">Keine Lizenzen gefunden.</div>
<?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Produkt</th><th>Lizenztyp</th><th>Lizenznummer</th><th>Belegung</th><th>Ablauf</th><th>Lieferant</th><th class="text-right">Kosten</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $status = $r['expiry_status']; ?>
            <tr data-href="/licenses/<?= (int) $r['id'] ?>"<?= (int) $r['is_active'] === 0 ? ' class="is-muted"' : '' ?>>
                <td class="license-product"><a class="font-semibold" href="/licenses/<?= (int) $r['id'] ?>"><?= e($r['product']) ?></a><?= (int) $r['is_active'] === 0 ? ' ' . badge('Deaktiviert', 'neutral') : '' ?><?php if (!empty($r['manufacturer_name'])): ?><div class="text-xs text-muted"><?= e($r['manufacturer_name']) ?></div><?php endif; ?></td>
                <td><?= e($r['license_type'] ?? '–') ?></td>
                <td class="mono text-sm"><?= e($r['license_number'] ?? '–') ?></td>
                <td>
                    <div class="license-usage"><progress value="<?= (int) $r['used_count'] ?>" max="<?= (int) $r['quantity'] ?>"></progress><span class="text-xs text-muted"><?= (int) $r['used_count'] ?> / <?= (int) $r['quantity'] ?> · <?= (int) $r['available_count'] ?> frei</span></div>
                </td>
                <td class="nowrap">
                    <?php if ($r['expires_at'] === null): ?>
                        <span class="text-muted">–</span>
                    <?php else: ?>
                        <span<?= $status === 'expired' ? ' class="text-danger font-semibold"' : ($status === 'expiring' ? ' class="text-warning font-semibold"' : '') ?>><?= fmt_date($r['expires_at']) ?></span>
                        <div class="text-xs text-muted"><?= $status === 'expired' ? 'vor ' . abs((int) $r['days_left']) . ' Tagen' : 'in ' . (int) $r['days_left'] . ' Tagen' ?></div>
                    <?php endif; ?>
                </td>
                <td><?= e($r['supplier_name'] ?? '–') ?></td>
                <td class="text-right mono nowrap"><?= $r['cost'] !== null ? fmt_money($r['cost']) : '–' ?></td>
                <td class="table-actions nowrap"><?= badge($expiryLabels[$status] ?? $status, $expiryColors[$status] ?? 'neutral') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
