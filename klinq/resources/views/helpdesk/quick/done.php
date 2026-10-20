<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start(); ?>
<div class="quick-page">
    <div class="quick-card quick-card-done">
        <span class="quick-check" aria-hidden="true"><?= icon('check') ?></span>
        <h1 class="text-lg">Vielen Dank – Ihre Meldung ist angekommen.</h1>
        <p class="text-muted">Der Help Desk kümmert sich darum und meldet sich bei Ihnen.</p>
        <p class="quick-number">Ticketnummer<strong><?= e($number) ?></strong></p>
        <a class="btn btn-secondary btn-block" href="/stoerung">Weitere Störung melden</a>
    </div>
</div>
<?php
$content = ob_get_clean();
$moduleTitle = \App\Support\ModuleNavigation::label(\App\Support\ModuleNavigation::HELPDESK);
include __DIR__ . '/../../partials/layout.php';
