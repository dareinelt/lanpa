<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$count = count($labels);
$styleQuery = http_build_query(array_intersect_key($_GET, ['copies' => 1]));
?>
<div class="page-header no-print">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="<?= e($backUrl) ?>">Zurück</a></p>
        <h1 class="page-title">Etiketten drucken</h1>
        <p class="text-muted mb-0"><?= $count ?> Etikett<?= $count === 1 ? '' : 'en' ?><?= $copies > 1 ? ' × ' . $copies . ' Exemplare' : '' ?> · Format <?= e($layout['width_mm']) ?> × <?= e($layout['height_mm']) ?> mm</p>
    </div>
    <div class="page-actions">
        <form method="get" action="/labels" class="flex items-center gap-2" id="copies-form">
            <?php foreach ($_GET as $k => $v): if ($k === 'copies' || !is_scalar($v)) { continue; } ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
            <label for="copies" class="text-muted">Exemplare</label>
            <input type="number" id="copies" name="copies" class="input-narrow" min="1" max="20" value="<?= (int) $copies ?>" data-autosubmit>
        </form>
        <?php if ($can('settings.manage')): ?><a class="btn btn-secondary" href="/admin/labels"><?= icon('settings') ?> Layout</a><?php endif; ?>
        <button type="button" class="btn btn-primary" id="print-button" <?= $count === 0 ? 'disabled' : '' ?>><?= icon('print') ?> Drucken</button>
    </div>
</div>

<?php if ($count === 0): ?>
<div class="card no-print"><div class="empty-state"><?= icon('print') ?><p>Keine Assets für den Etikettendruck ausgewählt.</p><a class="btn btn-secondary" href="/assets">Zur Assetliste</a></div></div>
<?php else: ?>
<?php if ($limitHit): ?><div class="alert alert-warning no-print"><?= icon('warning') ?> Es werden höchstens 500 Etiketten pro Druckauftrag ausgegeben. Bitte die Auswahl weiter einschränken.</div><?php endif; ?>
<div class="alert alert-info no-print"><?= icon('info') ?> Druckereinstellungen: Papierformat <?= e($layout['width_mm']) ?> × <?= e($layout['height_mm']) ?> mm (Etikettendrucker) bzw. „Tatsächliche Größe“ ohne Skalierung; Ränder auf 0 setzen. Jedes Etikett wird auf einer eigenen Seite ausgegeben. Der Druck wird in der Assethistorie protokolliert.</div>
<div class="label-sheet" id="label-sheet" data-ids="<?= e(implode(',', $ids)) ?>" data-autoprint="<?= !empty($autoprint) ? '1' : '0' ?>">
    <?php foreach ($labels as $label): for ($i = 0; $i < $copies; $i++) { include __DIR__ . '/../partials/label.php'; } endforeach; ?>
</div>
<?php endif; ?>
<?php
$innerContent = ob_get_clean();
$scripts = ['/js/labels.js'];
$bodyClass = 'is-label-print';
$extraStyles = ['/labels/style.css'];
include __DIR__ . '/../partials/app_layout.php';
