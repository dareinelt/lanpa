<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$statuses = App\Services\PurchaseOrderService::STATUSES;
$colors = App\Services\PurchaseOrderService::STATUS_COLORS;
$tabs = ['open' => 'Offen', 'overdue' => 'Überfällig', 'draft' => 'Entwürfe', 'ordered' => 'Bestellt', 'partially_delivered' => 'Teilgeliefert', 'delivered' => 'Geliefert', 'closed' => 'Abgeschlossen', 'cancelled' => 'Storniert', 'all' => 'Alle'];
$tabCount = static fn (string $key): ?int => match ($key) {
    'open' => ($counts['draft'] ?? 0) + ($counts['ordered'] ?? 0) + ($counts['partially_delivered'] ?? 0),
    'all' => null,
    default => $counts[$key] ?? 0,
};
$today = date('Y-m-d');
?>
<div class="page-header">
    <div><h1>Bestellungen</h1><p class="page-subtitle text-muted mb-0"><?= $paginator->total ?> Bestellungen</p></div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/orders/requests">Bedarfe</a>
        <a class="btn btn-secondary" href="/orders/templates">Vorlagen</a>
        <?php if ($can('orders.manage')): ?><a class="btn btn-primary" href="/orders/new"><?= icon('plus') ?> Neue Bestellung</a><?php endif; ?>
    </div>
</div>
<div class="status-tabs" role="tablist" aria-label="Status">
    <?php foreach ($tabs as $key => $label): $n = $tabCount($key); ?>
        <a class="status-tab<?= $filters['status'] === $key ? ' is-active' : '' ?>" href="<?= e(query_url('/orders', $query, ['status' => $key, 'page' => null])) ?>" role="tab"><?= e($label) ?><?php if ($n !== null): ?> <span class="status-tab-count<?= $key === 'overdue' && $n > 0 ? ' is-danger' : '' ?>"><?= $n ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<form method="get" action="/orders" class="filter-bar" role="search">
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <div class="form-group filter-wide">
        <label for="filter-q">Suche</label>
        <input id="filter-q" type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Bestellnr., Lieferant, Position …">
    </div>
    <div class="form-group">
        <label for="filter-supplier">Lieferant</label>
        <select id="filter-supplier" name="supplier_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($filters['supplier_id'], $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="filter-cc">Kostenstelle</label>
        <select id="filter-cc" name="cost_center_id" data-autosubmit>
            <option value="">Alle</option>
            <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected($filters['cost_center_id'], $cc['id']) ?>><?= e($cc['number']) ?> · <?= e($cc['description']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label for="filter-from">Bestellt von</label><input id="filter-from" type="date" name="date_from" value="<?= e($filters['date_from']) ?>" data-autosubmit></div>
    <div class="form-group"><label for="filter-to">bis</label><input id="filter-to" type="date" name="date_to" value="<?= e($filters['date_to']) ?>" data-autosubmit></div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Filtern</button>
    <?php if ($filters['q'] || $filters['supplier_id'] || $filters['cost_center_id'] || $filters['date_from'] || $filters['date_to']): ?>
        <a class="btn btn-ghost" href="/orders?status=<?= e($filters['status']) ?>"><?= icon('x') ?> Zurücksetzen</a>
    <?php endif; ?>
</form>
<div class="card card-flush">
<?php if ($rows === []): ?>
    <div class="table-empty">Keine Bestellungen gefunden.</div>
<?php else: ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Bestellnr.</th><th>Lieferant</th><th>Bestelldatum</th><th>Lieferung erwartet</th><th>Besteller</th><th class="text-right">Pos.</th><th>Lieferfortschritt</th><th class="text-right">Netto</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $overdue = in_array($r['status'], ['ordered', 'partially_delivered'], true) && $r['expected_delivery_date'] && $r['expected_delivery_date'] < $today;
        ?>
            <tr data-href="/orders/<?= (int) $r['id'] ?>">
                <td class="mono font-semibold"><a href="/orders/<?= (int) $r['id'] ?>"><?= e($r['order_number']) ?></a></td>
                <td><?= e($r['supplier_name']) ?></td>
                <td><?= fmt_date($r['order_date']) ?></td>
                <td<?= $overdue ? ' class="text-danger font-semibold"' : '' ?>><?= fmt_date($r['expected_delivery_date']) ?><?= $overdue ? ' ' . icon('warning', 'icon icon-sm') : '' ?></td>
                <td><?= e($r['ordered_by_display'] ?? '–') ?></td>
                <td class="text-right"><?= (int) $r['item_count'] ?></td>
                <td>
                    <?php if ((int) $r['quantity_total'] > 0): ?>
                    <div class="delivery-progress"><progress value="<?= (int) $r['quantity_received'] ?>" max="<?= (int) $r['quantity_total'] ?>"></progress><span class="text-xs text-muted"><?= (int) $r['quantity_received'] ?> / <?= (int) $r['quantity_total'] ?></span></div>
                    <?php else: ?><span class="text-muted">–</span><?php endif; ?>
                </td>
                <td class="text-right mono"><?= fmt_money($r['total_net']) ?></td>
                <td><?= badge($statuses[$r['status']] ?? $r['status'], $colors[$r['status']] ?? 'neutral') ?></td>
                <td class="table-actions"><?= icon('chevron-right', 'icon icon-sm icon-muted') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/pagination.php'; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
