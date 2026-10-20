<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$u = $stock['unassigned'];
$exportBtn = static function (string $section) use ($canExport): string {
    if (!$canExport) { return ''; }
    return '<a class="btn btn-secondary btn-sm" href="/reports/stock?format=csv&amp;section=' . e($section) . '">' . icon('download') . ' CSV</a>';
};
?>
<div class="page-header">
    <div>
        <nav class="breadcrumb" aria-label="Pfad"><a href="/reports">Berichte</a> <?= icon('chevron-right') ?> <span>Bestandsbericht</span></nav>
        <h1>Bestandsbericht</h1><p class="page-subtitle text-muted mb-0">Aktiver Bestand nach Status, Assettyp, Standort und Kostenstelle. Stand <?= date('d.m.Y H:i') ?></p>
    </div>
</div>

<section class="grid grid-4" aria-label="Kennzahlen">
    <a class="card stat-card" href="/reports/inventory"><span class="stat-value"><?= (int) $u['active_total'] ?></span><span class="stat-label">Aktive Assets</span></a>
    <div class="card stat-card"><span class="stat-value"><?= fmt_money($u['purchase_value'], '0,00 €') ?></span><span class="stat-label">Anschaffungswert (aktiv)</span></div>
    <a class="card stat-card <?= $u['without_location'] > 0 ? 'is-warning' : '' ?>" href="/reports/inventory?missing=location"><span class="stat-value"><?= (int) $u['without_location'] ?></span><span class="stat-label">Ohne Standort</span></a>
    <a class="card stat-card <?= $u['without_cost_center'] > 0 ? 'is-warning' : '' ?>" href="/reports/inventory?missing=cost_center"><span class="stat-value"><?= (int) $u['without_cost_center'] ?></span><span class="stat-label">Ohne Kostenstelle</span></a>
</section>

<div class="dashboard-grid mt-4">
    <section class="card card-flush col-span-4" id="by-status">
        <div class="card-header"><h2>Nach Status</h2><?= $exportBtn('status') ?></div>
        <?php $max = max(1, ...array_map(static fn (array $r): int => (int) $r['total'], $stock['by_status'] ?: [['total' => 0]])); ?>
        <ul class="bar-list">
            <?php foreach ($stock['by_status'] as $s): ?>
            <li>
                <a class="bar-label" href="/reports/inventory?status=<?= e($s['code']) ?>"><?= e($s['name']) ?></a>
                <progress class="bar is-<?= e($s['color']) ?>" value="<?= (int) $s['total'] ?>" max="<?= $max ?>"></progress>
                <span class="bar-value"><?= (int) $s['total'] ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card card-flush col-span-8" id="by-type">
        <div class="card-header"><h2>Nach Assettyp</h2><?= $exportBtn('type') ?></div>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Assettyp</th><th class="text-right">Gesamt</th><th class="text-right">Lager</th><th class="text-right">Ausg.</th><th class="text-right" title="Defekt / in Reparatur">Def./Rep.</th><th class="text-right" title="Ausgemustert / entsorgt">Ausgem.</th><th class="text-right">Wert</th></tr></thead>
            <tbody>
            <?php $sum = ['total' => 0, 'in_stock' => 0, 'issued' => 0, 'defective' => 0, 'final_count' => 0, 'purchase_value' => 0.0];
            foreach ($stock['by_type'] as $t): foreach ($sum as $k => $v) { $sum[$k] += $k === 'purchase_value' ? (float) $t[$k] : (int) $t[$k]; } ?>
                <tr class="<?= (int) $t['total'] === 0 ? 'is-muted' : '' ?>">
                    <td class="nowrap"><?= icon($t['icon'] ?: 'box', 'icon icon-muted') ?> <a href="/reports/inventory?asset_type_id=<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a></td>
                    <td class="text-right"><strong><?= (int) $t['total'] ?></strong></td>
                    <td class="text-right"><?= (int) $t['in_stock'] ?></td>
                    <td class="text-right"><?= (int) $t['issued'] ?></td>
                    <td class="text-right"><?= (int) $t['defective'] > 0 ? badge((string) (int) $t['defective'], 'warning') : '0' ?></td>
                    <td class="text-right text-muted"><?= (int) $t['final_count'] ?></td>
                    <td class="text-right nowrap"><?= fmt_money($t['purchase_value'], '–') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th>Summe</th><th class="text-right"><?= $sum['total'] ?></th><th class="text-right"><?= $sum['in_stock'] ?></th><th class="text-right"><?= $sum['issued'] ?></th><th class="text-right"><?= $sum['defective'] ?></th><th class="text-right"><?= $sum['final_count'] ?></th><th class="text-right nowrap"><?= fmt_money($sum['purchase_value'], '–') ?></th></tr></tfoot>
        </table>
        </div>
    </section>

    <section class="card card-flush col-span-6" id="by-location">
        <div class="card-header"><h2>Nach Standort</h2><?= $exportBtn('location') ?></div>
        <?php if (!$stock['by_location']): ?>
            <div class="table-empty">Keinem Standort sind Assets zugeordnet.</div>
        <?php else: ?>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Standort</th><th class="text-right">Gesamt</th><th class="text-right">Lager</th><th class="text-right">Ausg.</th><th class="text-right" title="Defekt / in Reparatur">Def./Rep.</th></tr></thead>
            <tbody>
            <?php foreach ($stock['by_location'] as $l): ?>
                <tr>
                    <td><a href="/reports/inventory?location_id=<?= (int) $l['id'] ?>"><?= e($l['full_path']) ?></a></td>
                    <td class="text-right"><strong><?= (int) $l['total'] ?></strong></td>
                    <td class="text-right"><?= (int) $l['in_stock'] ?></td>
                    <td class="text-right"><?= (int) $l['issued'] ?></td>
                    <td class="text-right"><?= (int) $l['defective'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="card card-flush col-span-6" id="by-cost-center">
        <div class="card-header"><h2>Nach Kostenstelle</h2><?= $exportBtn('cost_center') ?></div>
        <?php if (!$stock['by_cost_center']): ?>
            <div class="table-empty">Keiner Kostenstelle sind Assets zugeordnet.</div>
        <?php else: ?>
        <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Kostenstelle</th><th class="text-right">Gesamt</th><th class="text-right">Ausg.</th><th class="text-right">Wert</th></tr></thead>
            <tbody>
            <?php foreach ($stock['by_cost_center'] as $c): ?>
                <tr>
                    <td><a href="/reports/inventory?cost_center_id=<?= (int) $c['id'] ?>" class="mono"><?= e($c['number']) ?></a><br><span class="text-muted text-sm"><?= e($c['description']) ?></span></td>
                    <td class="text-right"><strong><?= (int) $c['total'] ?></strong></td>
                    <td class="text-right"><?= (int) $c['issued'] ?></td>
                    <td class="text-right nowrap"><?= fmt_money($c['purchase_value'], '–') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
