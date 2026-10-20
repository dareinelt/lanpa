<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<div class="page-header">
    <div>
        <p class="breadcrumb text-sm text-muted mb-2"><a href="/orders/<?= (int) $row['id'] ?>"><?= icon('arrow-left', 'icon icon-sm') ?> Bestellung <?= e($row['order_number']) ?></a></p>
        <h1><?= icon('download') ?> Wareneingang vom <?= fmt_date($receipt['received_at']) ?></h1>
        <p class="page-subtitle text-muted mb-0"><?= e($receipt['supplier_name']) ?><?= $receipt['delivery_note_number'] ? ' · Lieferschein <span class="mono">' . e($receipt['delivery_note_number']) . '</span>' : '' ?> · gebucht <?= fmt_datetime($receipt['created_at']) ?> von <?= e($receipt['received_by_name'] ?? '?') ?></p>
    </div>
    <div class="page-actions">
        <?php if ($assets && $can('labels.print')): ?><a class="btn btn-primary" href="/labels?ids=<?= implode(',', $assetIds) ?>"><?= icon('print') ?> <?= count($assets) ?> Etikett<?= count($assets) === 1 ? '' : 'en' ?> drucken</a><?php endif; ?>
        <a class="btn btn-secondary" href="/orders/<?= (int) $row['id'] ?>"><?= icon('cart') ?> Bestellung</a>
    </div>
</div>

<?php if ($assets): ?>
<div class="alert alert-success"><?= icon('check') ?> <?= count($assets) ?> Asset<?= count($assets) === 1 ? '' : 's' ?> mit Inventarnummer angelegt<?= $receipt['location_path'] ? ' und dem Standort <strong>' . e($receipt['location_path']) . '</strong> zugeordnet' : '' ?>. Nächster Schritt: Etiketten drucken und die Geräte ins Lager legen.</div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Lieferung</h2></div>
        <dl class="detail-list">
            <dt>Lieferdatum</dt><dd><?= fmt_date($receipt['received_at']) ?></dd>
            <dt>Lieferschein</dt><dd class="mono"><?= e($receipt['delivery_note_number'] ?? '–') ?></dd>
            <dt>Lagerort</dt><dd><?= e($receipt['location_path'] ?? '–') ?></dd>
            <dt>Bemerkung</dt><dd><?= nl2br_e($receipt['note']) ?: '–' ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Positionen</h2></div>
        <table class="table table-compact">
            <thead><tr><th>Pos.</th><th>Bezeichnung</th><th class="text-right">Menge</th></tr></thead>
            <tbody>
            <?php foreach ($lines as $l): ?>
                <tr><td class="text-muted"><?= (int) $l['position'] ?></td><td><?= e($l['description']) ?><?= $l['note'] ? '<br><span class="text-sm text-muted">' . e($l['note']) . '</span>' : '' ?></td><td class="text-right"><?= (int) $l['quantity'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header"><h2>Angelegte Assets</h2><span class="text-muted text-sm"><?= count($assets) ?></span></div>
    <?php if ($assets): ?>
    <div class="table-wrapper">
    <table class="table">
        <thead><tr><th>Inventarnr.</th><th>Typ</th><th>Bezeichnung</th><th>Seriennummer</th><th>Standort</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
            <tr data-href="/assets/<?= (int) $a['id'] ?>">
                <td class="mono font-semibold"><a href="/assets/<?= (int) $a['id'] ?>"><?= e($a['inventory_number']) ?></a></td>
                <td><?= icon($a['asset_type_icon'] ?: 'box', 'icon icon-sm icon-muted') ?> <?= e($a['asset_type_name']) ?></td>
                <td><?= e($a['name'] ?: ($a['article_name'] ?? '–')) ?></td>
                <td class="mono"><?= e($a['serial_number'] ?? '–') ?></td>
                <td><?= e($a['location_path'] ?? '–') ?></td>
                <td><?= badge($a['status_name'], $a['status_color']) ?></td>
                <td class="table-actions"><?php if ($can('labels.print')): ?><a class="btn btn-ghost btn-sm" href="/labels?ids=<?= (int) $a['id'] ?>" title="Etikett drucken"><?= icon('print') ?></a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?><p class="text-muted mb-0">Diese Lieferung enthielt nur Positionen ohne Asset-Erzeugung.</p><?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
