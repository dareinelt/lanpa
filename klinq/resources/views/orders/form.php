<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isNew = $row === null || !empty($row['is_new']);
$back = $isNew ? '/orders' : '/orders/' . (int) $row['id'];
?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-wide">
    <form method="post" action="<?= e($isNew ? '/orders' : '/orders/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group<?= has_error('order_number') ? ' has-error' : '' ?>">
                <label for="f-number">Bestellnummer</label>
                <input id="f-number" name="order_number" value="<?= e(form_value($row, 'order_number')) ?>" maxlength="60" class="mono" placeholder="<?= e($nextNumber) ?> (automatisch)">
                <p class="form-hint">Leer lassen für die nächste freie Nummer (<?= e($nextNumber) ?>) oder die Nummer des ERP-/Lieferantensystems eintragen.</p>
                <?= field_error('order_number') ?>
            </div>
            <div class="form-group<?= has_error('supplier_id') ? ' has-error' : '' ?>">
                <label for="f-supplier">Lieferant <span class="required">*</span></label>
                <select id="f-supplier" name="supplier_id" required<?= $isNew ? ' autofocus' : '' ?>>
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected(form_value($row, 'supplier_id'), $s['id']) ?>><?= e($s['name']) ?><?= $s['customer_number'] ? ' · Kd.-Nr. ' . e($s['customer_number']) : '' ?></option><?php endforeach; ?>
                </select>
                <?= field_error('supplier_id') ?>
            </div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('order_date') ? ' has-error' : '' ?>">
                <label for="f-date">Bestelldatum</label>
                <input id="f-date" type="date" name="order_date" value="<?= e(form_value($row, 'order_date')) ?>">
                <?= field_error('order_date') ?>
            </div>
            <div class="form-group<?= has_error('expected_delivery_date') ? ' has-error' : '' ?>">
                <label for="f-expected">Erwartetes Lieferdatum</label>
                <input id="f-expected" type="date" name="expected_delivery_date" value="<?= e(form_value($row, 'expected_delivery_date')) ?>">
                <?= field_error('expected_delivery_date') ?>
            </div>
            <div class="form-group<?= has_error('ordered_by_name') ? ' has-error' : '' ?>">
                <label for="f-orderer">Besteller</label>
                <input id="f-orderer" name="ordered_by_name" value="<?= e(form_value($row, 'ordered_by_name', $row['ordered_by_display'] ?? '')) ?>" maxlength="200">
                <?= field_error('ordered_by_name') ?>
            </div>
        </div>
        <div class="form-group<?= has_error('cost_center_id') ? ' has-error' : '' ?>">
            <label for="f-cc">Kostenstelle</label>
            <select id="f-cc" name="cost_center_id">
                <option value="">– keine –</option>
                <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected(form_value($row, 'cost_center_id'), $cc['id']) ?>><?= e($cc['number']) ?> · <?= e($cc['description']) ?></option><?php endforeach; ?>
            </select>
            <p class="form-hint">Wird beim Wareneingang als Kostenstelle der neuen Assets übernommen.</p>
            <?= field_error('cost_center_id') ?>
        </div>
        <div class="form-group<?= has_error('note') ? ' has-error' : '' ?>">
            <label for="f-note">Bemerkung</label>
            <textarea id="f-note" name="note" rows="3"><?= e(form_value($row, 'note')) ?></textarea>
            <?= field_error('note') ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isNew ? 'Anlegen und Positionen erfassen' : 'Speichern' ?></button>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
