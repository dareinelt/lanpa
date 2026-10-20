<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isNew = $row === null;
$back = $isNew ? '/licenses' : '/licenses/' . (int) $row['id'];
$val = static fn (string $key, string $default = ''): string => form_value($row, $key, $prefill[$key] ?? $default);
?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-wide">
    <form method="post" action="<?= e($isNew ? '/licenses' : '/licenses/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group<?= has_error('manufacturer_id') ? ' has-error' : '' ?>">
                <label for="f-manufacturer">Hersteller</label>
                <select id="f-manufacturer" name="manufacturer_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($manufacturers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected($val('manufacturer_id'), $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('manufacturer_id') ?>
            </div>
            <div class="form-group<?= has_error('product') ? ' has-error' : '' ?>">
                <label for="f-product">Produkt <span class="required">*</span></label>
                <input id="f-product" name="product" value="<?= e($val('product')) ?>" maxlength="200" required<?= $isNew ? ' autofocus' : '' ?> placeholder="z. B. Office 365 E3, Acrobat Pro, Windows Server CAL">
                <?= field_error('product') ?>
            </div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('license_type') ? ' has-error' : '' ?>">
                <label for="f-type">Lizenztyp</label>
                <input id="f-type" name="license_type" value="<?= e($val('license_type')) ?>" maxlength="100" list="license-types">
                <datalist id="license-types"><?php foreach ($licenseTypes as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
                <?= field_error('license_type') ?>
            </div>
            <div class="form-group<?= has_error('license_number') ? ' has-error' : '' ?>">
                <label for="f-number">Lizenznummer</label>
                <input id="f-number" name="license_number" value="<?= e($val('license_number')) ?>" maxlength="120" class="mono" placeholder="Vertrags-/Lizenz-Nr.">
                <?= field_error('license_number') ?>
            </div>
            <div class="form-group<?= has_error('quantity') ? ' has-error' : '' ?>">
                <label for="f-quantity">Anzahl Einheiten <span class="required">*</span></label>
                <input id="f-quantity" type="number" name="quantity" value="<?= e($val('quantity', '1')) ?>" min="<?= $row !== null ? max(1, (int) $row['used_count']) : 1 ?>" max="100000" required inputmode="numeric">
                <?php if ($row !== null && (int) $row['used_count'] > 0): ?><p class="form-hint"><?= (int) $row['used_count'] ?> Einheiten sind zugeordnet.</p><?php endif; ?>
                <?= field_error('quantity') ?>
            </div>
        </div>
        <div class="form-group<?= has_error('license_key') ? ' has-error' : '' ?>">
            <label for="f-key">Lizenzschlüssel</label>
            <textarea id="f-key" name="license_key" rows="2" class="mono" autocomplete="off" spellcheck="false"><?= e($val('license_key')) ?></textarea>
            <p class="form-hint">Wird in der Detailansicht ausgeblendet und nur auf Klick angezeigt; landet nicht im Audit-Log.</p>
            <?= field_error('license_key') ?>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('purchase_date') ? ' has-error' : '' ?>">
                <label for="f-purchase">Kaufdatum</label>
                <input id="f-purchase" type="date" name="purchase_date" value="<?= e($val('purchase_date')) ?>">
                <?= field_error('purchase_date') ?>
            </div>
            <div class="form-group<?= has_error('expires_at') ? ' has-error' : '' ?>">
                <label for="f-expires">Ablaufdatum</label>
                <input id="f-expires" type="date" name="expires_at" value="<?= e($val('expires_at')) ?>">
                <p class="form-hint">Leer = unbefristet.</p>
                <?= field_error('expires_at') ?>
            </div>
            <div class="form-group<?= has_error('cost') ? ' has-error' : '' ?>">
                <label for="f-cost">Kosten (netto, €)</label>
                <input id="f-cost" name="cost" value="<?= e($val('cost')) ?>" inputmode="decimal" placeholder="0,00" class="mono">
                <?= field_error('cost') ?>
            </div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group<?= has_error('supplier_id') ? ' has-error' : '' ?>">
                <label for="f-supplier">Lieferant</label>
                <select id="f-supplier" name="supplier_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected($val('supplier_id'), $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('supplier_id') ?>
            </div>
            <div class="form-group<?= has_error('purchase_order_id') ? ' has-error' : '' ?>">
                <label for="f-order">Bestellung</label>
                <select id="f-order" name="purchase_order_id">
                    <option value="">– keine –</option>
                    <?php foreach ($orders as $o): ?><option value="<?= (int) $o['id'] ?>"<?= selected($val('purchase_order_id'), $o['id']) ?>><?= e($o['order_number']) ?> · <?= e($o['supplier_name']) ?><?= $o['order_date'] ? ' · ' . fmt_date($o['order_date']) : '' ?></option><?php endforeach; ?>
                </select>
                <?= field_error('purchase_order_id') ?>
            </div>
            <div class="form-group<?= has_error('cost_center_id') ? ' has-error' : '' ?>">
                <label for="f-cc">Kostenstelle</label>
                <select id="f-cc" name="cost_center_id">
                    <option value="">– keine –</option>
                    <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected($val('cost_center_id'), $cc['id']) ?>><?= e($cc['number']) ?> · <?= e($cc['description']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('cost_center_id') ?>
            </div>
        </div>
        <div class="form-group<?= has_error('note') ? ' has-error' : '' ?>">
            <label for="f-note">Bemerkung</label>
            <textarea id="f-note" name="note" rows="3"><?= e($val('note')) ?></textarea>
            <?= field_error('note') ?>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> <?= $isNew ? 'Lizenz anlegen' : 'Speichern' ?></button>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
