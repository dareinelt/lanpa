<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div><h1>Berichte</h1><p class="page-subtitle text-muted mb-0">Auswertungen zu Bestand und Vorgängen – als Tabelle oder CSV-Export (UTF-8, Semikolon).</p></div>
</div>

<section class="grid grid-4" aria-label="Berichte">
    <?php foreach ($reports as $key => $r): ?>
    <a class="card report-card" href="/reports/<?= e($key) ?>">
        <span class="report-card-icon"><?= icon($r['icon']) ?></span>
        <span class="report-card-title"><?= e($r['label']) ?></span>
        <span class="report-card-text"><?= e($r['description']) ?></span>
    </a>
    <?php endforeach; ?>
</section>

<div class="dashboard-grid mt-4">
    <section class="card card-flush col-span-4">
        <div class="card-header"><h2>Nach Status</h2><a class="btn btn-link btn-sm" href="/reports/stock">Details</a></div>
        <?php $max = max(1, ...array_map(static fn (array $r): int => (int) $r['total'], $byStatus ?: [['total' => 0]])); ?>
        <ul class="bar-list">
            <?php foreach ($byStatus as $s): ?>
            <li>
                <a class="bar-label" href="/assets?status=<?= e($s['code']) ?>"><?= e($s['name']) ?></a>
                <progress class="bar is-<?= e($s['color']) ?>" value="<?= (int) $s['total'] ?>" max="<?= $max ?>"></progress>
                <span class="bar-value"><?= (int) $s['total'] ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card card-flush col-span-4">
        <div class="card-header"><h2>Nach Assettyp</h2><a class="btn btn-link btn-sm" href="/reports/stock#by-type">Details</a></div>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Typ</th><th class="text-right">Gesamt</th><th class="text-right">Lager</th><th class="text-right">Ausg.</th></tr></thead>
            <tbody>
            <?php foreach ($byType as $t): if ((int) $t['total'] === 0) { continue; } ?>
                <tr>
                    <td><a href="/reports/inventory?asset_type_id=<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a></td>
                    <td class="text-right"><?= (int) $t['total'] ?></td>
                    <td class="text-right"><?= (int) $t['in_stock'] ?></td>
                    <td class="text-right"><?= (int) $t['issued'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card card-flush col-span-4">
        <div class="card-header"><h2>Je Monat</h2><span class="text-muted text-sm"><?= fmt_date($periodFrom) ?> – <?= fmt_date($periodTo) ?></span></div>
        <?php if (!$byMonth): ?>
            <div class="table-empty">Keine Bewegungen im Zeitraum.</div>
        <?php else: ?>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Monat</th><th class="text-right">Entnahmen</th><th class="text-right">Retouren</th><th class="text-right">Schaden</th></tr></thead>
            <tbody>
            <?php foreach ($byMonth as $m): $first = $m['month'] . '-01'; $last = date('Y-m-t', strtotime($first)); ?>
                <tr>
                    <td><?= e(date('m/Y', strtotime($first))) ?></td>
                    <td class="text-right"><a href="/reports/checkouts?date_from=<?= e($first) ?>&amp;date_to=<?= e($last) ?>"><?= (int) $m['checkouts'] ?></a></td>
                    <td class="text-right"><a href="/reports/returns?date_from=<?= e($first) ?>&amp;date_to=<?= e($last) ?>"><?= (int) $m['returns_'] ?></a></td>
                    <td class="text-right"><?= (int) $m['damaged'] > 0 ? badge((string) (int) $m['damaged'], 'danger') : '0' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="card col-span-12">
        <div class="card-header"><h2>Kennzahlen</h2></div>
        <div class="kpi-row">
            <div><span class="kpi-value"><?= (int) $stats['assets_total'] ?></span><span class="kpi-label">Assets gesamt</span></div>
            <div><span class="kpi-value"><?= (int) $stats['assets_in_stock'] ?></span><span class="kpi-label">Lagerbestand</span></div>
            <div><span class="kpi-value"><?= (int) $stats['assets_issued'] ?></span><span class="kpi-label">Ausgegeben</span></div>
            <div><span class="kpi-value"><?= (int) $stats['assets_defective'] ?></span><span class="kpi-label">Defekt</span></div>
            <div><span class="kpi-value"><?= (int) $stats['assets_repair'] ?></span><span class="kpi-label">In Reparatur</span></div>
            <div><span class="kpi-value"><?= (int) $stats['open_checkouts'] ?></span><span class="kpi-label">Offene Entnahmen</span></div>
            <div><span class="kpi-value"><?= (int) $stats['open_returns'] ?></span><span class="kpi-label">Offene Retouren</span></div>
            <div><span class="kpi-value"><?= (int) $stats['orders_open'] ?></span><span class="kpi-label">Offene Bestellungen</span></div>
            <div><span class="kpi-value"><?= (int) $stats['deliveries_expected'] ?></span><span class="kpi-label">Erwartete Lieferungen</span></div>
            <div><span class="kpi-value"><?= (int) $stats['licenses_expiring'] ?></span><span class="kpi-label">Lizenzen laufen ab</span></div>
        </div>
    </section>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
