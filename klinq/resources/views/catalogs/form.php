<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isNew = $row === null;
$isTypes = $kind === 'asset-types';
$action = '/catalogs/' . $kind . ($isNew ? '' : '/' . (int) $row['id']);
$back = '/catalogs?tab=' . $kind;
$source = $row ?? $prefill;
?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-narrow">
    <form method="post" action="<?= e($action) ?>" novalidate>
        <?= csrf_field() ?>
        <?php if ($isTypes): ?>
        <?php $prefixLocked = !$isNew && $assetCount > 0; ?>
        <div class="form-group">
            <label for="f-name">Bezeichnung *</label>
            <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="100" autofocus>
            <?= field_error('name') ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-code">Code *</label>
                <input id="f-code" name="code" value="<?= e(form_value($row, 'code')) ?>" required maxlength="20" class="mono" pattern="[A-Za-z0-9_\-]{1,20}">
                <p class="form-hint">Kurzkennung, z. B. für den CSV-Import (Großbuchstaben, Ziffern, „-“, „_“).</p>
                <?= field_error('code') ?>
            </div>
            <div class="form-group">
                <label for="f-prefix">Inventarnummern-Präfix *</label>
                <?php if ($prefixLocked): ?>
                    <input id="f-prefix" value="<?= e((string) $row['inventory_prefix']) ?>" class="mono" readonly>
                    <input type="hidden" name="inventory_prefix" value="<?= e((string) $row['inventory_prefix']) ?>">
                    <p class="form-hint">Nicht mehr änderbar: <?= (int) $assetCount ?> Asset(s) tragen Inventarnummern mit diesem Präfix.</p>
                <?php else: ?>
                    <input id="f-prefix" name="inventory_prefix" value="<?= e(form_value($row, 'inventory_prefix')) ?>" required maxlength="6" class="mono" pattern="[A-Za-z]{1,6}">
                    <p class="form-hint">1 bis 6 Buchstaben; Inventarnummern lauten Präfix + Jahr + laufende Nummer (z. B. PC26001). Nach dem ersten Asset nicht mehr änderbar.</p>
                <?php endif; ?>
                <?= field_error('inventory_prefix') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-icon">Symbol *</label>
                <select id="f-icon" name="icon" required>
                    <?php foreach ($icons as $iconKey => $iconLabel): ?><option value="<?= e($iconKey) ?>"<?= selected(form_value($row, 'icon', 'box'), $iconKey) ?>><?= e($iconLabel) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('icon') ?>
            </div>
            <div class="form-group">
                <label for="f-sort">Sortierung</label>
                <input id="f-sort" name="sort_order" type="number" min="0" step="1" value="<?= e(form_value($row, 'sort_order', '0')) ?>">
                <?= field_error('sort_order') ?>
            </div>
        </div>
        <fieldset class="form-group">
            <legend>Kennungsfelder und Funktionen</legend>
            <label class="checkbox-field"><input type="checkbox" name="has_serial_number" value="1"<?= form_checked($row, 'has_serial_number') ?>> <span>Seriennummer erfassen</span></label>
            <label class="checkbox-field"><input type="checkbox" name="has_mac_address" value="1"<?= form_checked($row, 'has_mac_address', false) ?>> <span>MAC-Adresse erfassen</span></label>
            <label class="checkbox-field"><input type="checkbox" name="has_imei" value="1"<?= form_checked($row, 'has_imei', false) ?>> <span>IMEI erfassen</span></label>
            <label class="checkbox-field"><input type="checkbox" name="supports_licenses" value="1"<?= form_checked($row, 'supports_licenses', false) ?>> <span>Lizenzen zuordenbar</span></label>
        </fieldset>
        <?php else: ?>
        <?php $typeLocked = !$isNew && $categoryUsage > 0; ?>
        <div class="form-group">
            <label for="f-type">Assettyp *</label>
            <?php if ($typeLocked): ?>
                <select id="f-type" disabled>
                    <?php foreach ($types as $t): ?><option<?= selected($row['asset_type_id'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="hidden" name="asset_type_id" value="<?= (int) $row['asset_type_id'] ?>">
                <p class="form-hint">Nicht mehr änderbar: Die Kategorie wird von <?= (int) $categoryUsage ?> Asset(s)/Artikel(n) verwendet.</p>
            <?php else: ?>
                <select id="f-type" name="asset_type_id" required>
                    <option value="">Bitte wählen …</option>
                    <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected(form_value($source, 'asset_type_id'), $t['id']) ?>><?= e($t['name']) ?><?= (int) $t['is_active'] === 1 ? '' : ' (inaktiv)' ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?= field_error('asset_type_id') ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Bezeichnung *</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="100" autofocus>
                <?= field_error('name') ?>
            </div>
            <div class="form-group">
                <label for="f-sort">Sortierung</label>
                <input id="f-sort" name="sort_order" type="number" min="0" step="1" value="<?= e(form_value($row, 'sort_order', '0')) ?>">
                <?= field_error('sort_order') ?>
            </div>
        </div>
        <?php endif; ?>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
