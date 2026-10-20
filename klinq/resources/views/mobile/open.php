<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<section class="card card-compact">
    <h2 class="text-sm text-muted mb-2"><?= count($rows) ?> offene Vorgänge</h2>
    <?php if (!$rows): ?><p class="text-muted mb-0">Nichts offen – alles erledigt.</p><?php else: ?>
    <ul class="m-list">
        <?php foreach ($rows as $r): $missing = App\Services\MovementService::missingLabels($r); ?>
            <li><a href="/movements/<?= (int) $r['id'] ?>">
                <?= icon($r['type'] === 'checkout' ? 'checkout' : 'return') ?>
                <div class="m-list-main">
                    <div class="m-list-title"><span class="mono"><?= e($r['inventory_number']) ?></span> · <?= $r['type'] === 'checkout' ? 'Entnahme' : 'Rückgabe' ?><?= $r['employee_name'] ? ' · ' . e($r['employee_name']) : '' ?></div>
                    <div class="m-list-sub"><?= fmt_datetime($r['movement_at']) ?><?= $missing ? ' · fehlt: ' . e(implode(', ', $missing)) : ' · Prüfung ausstehend' ?></div>
                </div>
                <?= icon('chevron-right') ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<p class="text-muted text-sm text-center">Die Vervollständigung erfolgt am Desktop unter „Offene Vorgänge“.</p>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/mobile_layout.php'; ?>
