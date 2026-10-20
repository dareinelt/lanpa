<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); ?>
<section class="card card-compact">
    <h2 class="text-sm text-muted mb-2"><?= count($drafts) ?> offene Übergabeprotokolle</h2>
    <?php if ($drafts === []): ?>
        <p class="text-muted mb-0">Derzeit keine Entwürfe. Protokolle werden am Desktop unter „Übergabeprotokolle“ angelegt.</p>
    <?php else: ?>
    <ul class="m-list">
        <?php foreach ($drafts as $d): ?>
            <li><a href="/m/handover/<?= (int) $d['id'] ?>/sign">
                <?= icon('signature') ?>
                <div class="m-list-main">
                    <div class="m-list-title"><?= e($d['employee_name']) ?> · Version <?= (int) $d['version'] ?></div>
                    <div class="m-list-sub"><span class="mono"><?= e($d['protocol_number']) ?></span> · <?= (int) $d['item_count'] ?> Arbeitsmittel · angelegt <?= fmt_datetime($d['created_at']) ?></div>
                </div>
                <?= icon('chevron-right') ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<p class="text-muted text-sm text-center">Der Mitarbeiter unterschreibt direkt auf diesem Gerät; anschließend wird die Version gültig und das PDF archiviert.</p>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/mobile_layout.php'; ?>
