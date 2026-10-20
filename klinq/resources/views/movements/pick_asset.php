<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$label = $action === 'checkout' ? 'Entnahme' : 'Rückgabe';
$actionIcon = $action === 'checkout' ? 'checkout' : 'return';
?>
<div class="page-header">
    <div>
        <p class="breadcrumb text-sm text-muted mb-2"><a href="/assets"><?= icon('arrow-left', 'icon icon-sm') ?> Zurück</a></p>
        <h1><?= icon($actionIcon) ?> <?= e($label) ?> – Asset wählen</h1>
        <p class="page-subtitle text-muted mb-0">Inventar- oder Seriennummer eingeben oder aus der Liste auswählen.</p>
    </div>
</div>

<div class="card mt-4">
    <form method="get" action="/movements/<?= e($action) ?>" class="card-form-wide" id="pick-asset-form" autocomplete="off">
        <div class="form-group" style="position: relative;">
            <label for="f-pick-asset">Inventar- oder Seriennummer <span class="required">*</span></label>
            <input id="f-pick-asset" type="text" name="asset" placeholder="z. B. PC26001" autocapitalize="characters" required autofocus>
            <ul class="search-results" id="pick-asset-results" hidden></ul>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('arrow-right') ?> Weiter</button>
            <a class="btn btn-ghost" href="/assets">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/pick-asset.js']; include __DIR__ . '/../partials/app_layout.php'; ?>
