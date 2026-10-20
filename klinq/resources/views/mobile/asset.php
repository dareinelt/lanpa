<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$issued = $asset['employee_id'] !== null || in_array($asset['status_code'], ['issued', 'return_expected'], true);
$final = (int) $asset['status_final'] === 1;
$inv = rawurlencode($asset['inventory_number']);
?>
<?php include __DIR__ . '/_asset_card.php'; ?>

<?php if ($open): ?>
<a class="alert alert-warning" href="/m/done/<?= (int) $open['id'] ?>"><?= icon('warning') ?> Offene <?= $open['type'] === 'checkout' ? 'Entnahme' : 'Rückgabe' ?> vom <?= fmt_date($open['movement_date']) ?> – fehlende Angaben: <?= e(implode(', ', App\Services\MovementService::missingLabels($open)) ?: 'Prüfung') ?></a>
<?php endif; ?>

<div class="m-actions">
    <?php if ($final): ?>
        <div class="alert alert-info"><?= icon('info') ?> Das Asset ist <?= e(mb_strtolower($asset['status_name'])) ?>; es sind keine Bewegungen mehr möglich.</div>
    <?php elseif ($issued): ?>
        <?php if ($can('movements.return')): ?><a class="btn btn-primary btn-xl btn-block" href="/m/return?asset=<?= $inv ?>"><?= icon('return') ?> Rückgabe erfassen</a><?php endif; ?>
        <p class="text-muted text-sm text-center mb-0">Weitergabe an andere Mitarbeiter: zuerst Rückgabe, dann neue Entnahme.</p>
    <?php else: ?>
        <?php if ($can('movements.checkout')): ?><a class="btn btn-primary btn-xl btn-block" href="/m/checkout?asset=<?= $inv ?>"><?= icon('checkout') ?> Ausgeben (Entnahme)</a><?php endif; ?>
        <?php if ($can('movements.return') && $asset['status_code'] !== 'in_stock'): ?><a class="btn btn-secondary btn-block" href="/m/return?asset=<?= $inv ?>"><?= icon('return') ?> Rückgabe / Einlagerung</a><?php endif; ?>
    <?php endif; ?>
    <div class="m-actions-row">
        <a class="btn btn-ghost" href="/assets/<?= (int) $asset['id'] ?>"><?= icon('laptop') ?> Details (Desktop)</a>
        <a class="btn btn-ghost" href="/m"><?= icon('qr') ?> Weiter scannen</a>
    </div>
</div>

<?php if ($children): ?>
<section class="card card-compact">
    <h2 class="text-sm text-muted mb-2">Zugehörige Assets (<?= count($children) ?>)</h2>
    <ul class="m-list">
        <?php foreach ($children as $c): ?>
            <li><a href="/m/asset/<?= e(rawurlencode($c['inventory_number'])) ?>">
                <?= icon($c['asset_type_icon'] ?: 'box') ?>
                <div class="m-list-main"><div class="m-list-title mono"><?= e($c['inventory_number']) ?></div><div class="m-list-sub"><?= e($c['category_name'] ?? $c['asset_type_name']) ?> · <?= e($c['name'] ?: ($c['article_name'] ?? '')) ?></div></div>
                <?= badge($c['status_name'], $c['status_color']) ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($lastMovements): ?>
<section class="card card-compact">
    <h2 class="text-sm text-muted mb-2">Letzte Bewegungen</h2>
    <ul class="m-list">
        <?php foreach ($lastMovements as $m): ?>
            <li><div class="m-list-item">
                <?= icon($m['type'] === 'checkout' ? 'checkout' : 'return') ?>
                <div class="m-list-main">
                    <div class="m-list-title"><?= $m['type'] === 'checkout' ? 'Entnahme' : 'Rückgabe' ?><?= $m['employee_name'] ? ' · ' . e($m['employee_name']) : '' ?></div>
                    <div class="m-list-sub"><?= fmt_datetime($m['movement_at']) ?><?= $m['to_location_path'] ? ' · ' . e($m['to_location_path']) : '' ?></div>
                </div>
                <?php if ($m['status'] === 'open'): ?><?= badge('offen', 'warning') ?><?php elseif ($m['status'] === 'cancelled'): ?><?= badge('storniert', 'neutral') ?><?php endif; ?>
            </div></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); $backHref = '/m'; include __DIR__ . '/../partials/mobile_layout.php'; ?>
