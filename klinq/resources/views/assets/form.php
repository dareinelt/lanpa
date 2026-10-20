<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $back = $isNew ? '/assets' : '/assets/' . (int) $row['id']; ?>
<div class="page-header">
    <div>
        <?php if (!$isNew): ?><p class="page-subtitle text-muted mb-0"><a href="/assets">Assets</a> / <a href="<?= e($back) ?>" class="mono"><?= e($row['inventory_number']) ?></a></p><?php endif; ?>
        <h1><?= e($title) ?></h1>
    </div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-wide">
    <form method="post" action="<?= e($isNew ? '/assets' : '/assets/' . (int) $row['id']) ?>" id="asset-form" novalidate
          data-asset-form data-check-url="/api/assets/check"<?= $isNew ? '' : ' data-asset-id="' . (int) $row['id'] . '"' ?>>
        <?= csrf_field() ?>
        <?php if (!$isNew): ?><input type="hidden" name="version" value="<?= (int) $row['version'] ?>"><?php endif; ?>

        <h3 class="text-sm text-muted">Identifikation</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="f-type">Assettyp *</label>
                <?php if ($isNew): ?>
                <select id="f-type" name="asset_type_id" required data-category-filter data-inventory-type autofocus>
                    <option value="">Bitte wählen …</option>
                    <?php foreach ($types as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"<?= selected(form_value($row, 'asset_type_id'), $t['id']) ?>
                                data-prefix="<?= e($t['inventory_prefix']) ?>" data-serial="<?= (int) $t['has_serial_number'] ?>" data-mac="<?= (int) $t['has_mac_address'] ?>" data-imei="<?= (int) $t['has_imei'] ?>"><?= e($t['name']) ?> (<?= e($t['inventory_prefix']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('asset_type_id') ?>
                <span class="form-hint">Bestimmt das Präfix der Inventarnummer und lässt sich später nicht ändern.</span>
                <?php else: ?>
                <input id="f-type" value="<?= e($row['asset_type_name']) ?>" readonly>
                <input type="hidden" name="asset_type_id" value="<?= (int) $row['asset_type_id'] ?>" data-category-filter data-prefix="<?= e($row['asset_type_code']) ?>" data-serial="<?= (int) $row['has_serial_number'] ?>" data-mac="<?= (int) $row['has_mac_address'] ?>" data-imei="<?= (int) $row['has_imei'] ?>">
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="f-category">Kategorie</label>
                <select id="f-category" name="asset_category_id">
                    <option value="">Keine</option>
                    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" data-type="<?= (int) $c['asset_type_id'] ?>"<?= selected(form_value($row, 'asset_category_id'), $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('asset_category_id') ?>
            </div>
        </div>
        <div class="form-row">
            <?php
            $articleId = form_value($row, 'article_id');
            $selectedArticle = null;
            if ($articleId !== '' && $articleId !== null) {
                foreach ($articles as $a) {
                    if ((int) $a['id'] === (int) $articleId) {
                        $selectedArticle = ['id' => $a['id'], 'name' => $a['manufacturer_name'] . ' ' . $a['name'], 'meta' => implode(' · ', array_filter([$a['article_number'], $a['asset_type_name'] ?? null])), 'data' => $a];
                        break;
                    }
                }
                if ($selectedArticle === null && !$isNew && (int) $row['article_id'] === (int) $articleId) {
                    $selectedArticle = ['id' => $row['article_id'], 'name' => trim(($row['manufacturer_name'] ?? '') . ' ' . ($row['article_name'] ?? '')), 'meta' => '', 'data' => ['asset_type_id' => $row['asset_type_id'], 'manufacturer_id' => $row['manufacturer_id'], 'manufacturer_name' => $row['manufacturer_name'] ?? '', 'asset_category_id' => null]];
                }
            }
            ?>
            <div class="form-group picker<?= has_error('article_id') ? ' has-error' : '' ?>" data-picker data-picker-browse data-search-url="/api/articles/search" data-article-picker>
                <label for="f-article">Artikel <span class="required">*</span></label>
                <input type="hidden" name="article_id" value="<?= e((string) ($selectedArticle['id'] ?? '')) ?>" data-picker-value
                    <?php if ($selectedArticle): ?> data-type="<?= (int) $selectedArticle['data']['asset_type_id'] ?>" data-manufacturer="<?= (int) $selectedArticle['data']['manufacturer_id'] ?>" data-manufacturer-name="<?= e((string) $selectedArticle['data']['manufacturer_name']) ?>" data-category="<?= (int) ($selectedArticle['data']['asset_category_id'] ?? 0) ?>"<?php endif; ?>>
                <div class="picker-selected" data-picker-selected<?= $selectedArticle ? '' : ' hidden' ?>>
                    <span class="picker-selected-text">
                        <span class="picker-selected-label" data-picker-label><?= e($selectedArticle['name'] ?? '') ?></span>
                        <span class="text-muted text-sm" data-picker-meta><?= e($selectedArticle['meta'] ?? '') ?></span>
                    </span>
                    <button type="button" class="btn btn-ghost btn-sm picker-clear" data-picker-clear aria-label="Auswahl entfernen"><?= icon('x') ?></button>
                </div>
                <input id="f-article" type="search" class="picker-input" placeholder="Artikel suchen (Hersteller, Bezeichnung, Artikelnummer) …" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" data-picker-input<?= $selectedArticle ? ' hidden' : '' ?>>
                <ul class="picker-results" role="listbox" hidden data-picker-results></ul>
                <?= field_error('article_id') ?>
                <span class="form-hint">Nur Artikel aus den Stammdaten sind wählbar. Assettyp, Hersteller und Kategorie werden übernommen.<?php if ($can('articles.manage')): ?> Fehlt ein Artikel? <a href="/articles/new" target="_blank" rel="noopener">Artikel anlegen</a>.<?php endif; ?></span>
            </div>
            <div class="form-group">
                <label for="f-manufacturer">Hersteller</label>
                <input id="f-manufacturer" value="<?= e($selectedArticle['data']['manufacturer_name'] ?? '') ?>" readonly placeholder="wird vom Artikel übernommen" data-manufacturer-display>
                <input type="hidden" name="manufacturer_id" value="<?= e((string) ($selectedArticle['data']['manufacturer_id'] ?? '')) ?>" data-manufacturer-id>
                <?= field_error('manufacturer_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Bezeichnung</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" maxlength="200" placeholder="z. B. Notebook Vertrieb, sonst Artikelname">
                <?= field_error('name') ?>
            </div>
            <div class="form-group" data-field="serial">
                <label for="f-serial">Seriennummer</label>
                <input id="f-serial" name="serial_number" value="<?= e(form_value($row, 'serial_number')) ?>" maxlength="120" class="mono" autocapitalize="characters" autocomplete="off" data-check="serial_number">
                <?= field_error('serial_number') ?>
                <ul class="field-hint-list" data-check-hints="serial_number" hidden></ul>
            </div>
        </div>
        <div class="form-row<?= $isNew ? ' form-row-3' : '' ?>">
            <div class="form-group" data-field="mac">
                <label for="f-mac">MAC-Adresse</label>
                <input id="f-mac" name="mac_address" value="<?= e(form_value($row, 'mac_address')) ?>" maxlength="40" class="mono" placeholder="00:1A:2B:3C:4D:5E" autocomplete="off" data-check="mac_address">
                <?= field_error('mac_address') ?>
                <ul class="field-hint-list" data-check-hints="mac_address" hidden></ul>
            </div>
            <div class="form-group" data-field="imei">
                <label for="f-imei">IMEI</label>
                <input id="f-imei" name="imei" value="<?= e(form_value($row, 'imei')) ?>" maxlength="30" class="mono" inputmode="numeric" autocomplete="off" data-check="imei">
                <?= field_error('imei') ?>
                <ul class="field-hint-list" data-check-hints="imei" hidden></ul>
            </div>
            <?php if ($isNew): ?>
            <div class="form-group" data-accepted-invno-group hidden>
                <label for="f-accepted-invno">Inventarnummer</label>
                <input id="f-accepted-invno" class="mono" readonly data-accepted-invno-display>
            </div>
            <?php endif; ?>
        </div>

        <h3 class="text-sm text-muted">Beschaffung</h3>
        <div class="form-row form-row-3">
            <div class="form-group"><label for="f-pdate">Kaufdatum</label><input id="f-pdate" name="purchase_date" type="date" value="<?= e(form_value($row, 'purchase_date')) ?>"><?= field_error('purchase_date') ?></div>
            <div class="form-group"><label for="f-price">Anschaffungskosten (€)</label><input id="f-price" name="purchase_price" inputmode="decimal" value="<?= e(isset($_SESSION['_old_input']['purchase_price']) || $row === null || $row['purchase_price'] === null ? form_value($row, 'purchase_price') : number_format((float) $row['purchase_price'], 2, ',', '.')) ?>" placeholder="0,00"><?= field_error('purchase_price') ?></div>
            <div class="form-group"><label for="f-warranty">Garantieende</label><input id="f-warranty" name="warranty_until" type="date" value="<?= e(form_value($row, 'warranty_until')) ?>"><?= field_error('warranty_until') ?></div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-supplier">Lieferant</label>
                <select id="f-supplier" name="supplier_id">
                    <option value="">Kein Lieferant</option>
                    <?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>"<?= selected(form_value($row, 'supplier_id'), $s['id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('supplier_id') ?>
            </div>
            <div class="form-group">
                <label for="f-order">Bestellung</label>
                <?php if (!$isNew && $row['purchase_order_id']): ?>
                    <input id="f-order" value="<?= e($row['order_number']) ?>" readonly><input type="hidden" name="purchase_order_id" value="<?= (int) $row['purchase_order_id'] ?>">
                <?php else: ?>
                    <input id="f-order" value="<?= form_value($row, 'purchase_order_id') !== '' ? '#' . e(form_value($row, 'purchase_order_id')) : '' ?>" placeholder="wird über den Wareneingang zugeordnet" readonly><input type="hidden" name="purchase_order_id" value="<?= e(form_value($row, 'purchase_order_id')) ?>">
                <?php endif; ?>
            </div>
        </div>

        <h3 class="text-sm text-muted">Zuordnung &amp; Status</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="f-employee">Mitarbeiter</label>
                <select id="f-employee" name="employee_id">
                    <option value="">Nicht zugeordnet</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected(form_value($row, 'employee_id'), $emp['id']) ?>><?= e($emp['display_name']) ?><?= $emp['department'] ? ' · ' . e($emp['department']) : '' ?></option><?php endforeach; ?>
                </select>
                <?= field_error('employee_id') ?>
                <span class="form-hint">Für den regulären Ablauf die <strong>Entnahme</strong> nutzen – hier nur Direktzuordnung/Korrektur. Mit Mitarbeiter wird der Status automatisch „Ausgegeben“.</span>
            </div>
            <div class="form-group">
                <label for="f-return">Rückgabe erwartet bis</label>
                <input id="f-return" name="expected_return_at" type="date" value="<?= e(form_value($row, 'expected_return_at')) ?>">
                <?= field_error('expected_return_at') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-location">Standort</label>
                <select id="f-location" name="location_id">
                    <option value="">Kein Standort</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected(form_value($row, 'location_id'), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('location_id') ?>
            </div>
            <div class="form-group">
                <label for="f-cc">Kostenstelle</label>
                <select id="f-cc" name="cost_center_id">
                    <option value="">Keine Kostenstelle</option>
                    <?php foreach ($costCenters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected(form_value($row, 'cost_center_id'), $c['id']) ?>><?= e($c['number']) ?> – <?= e($c['description']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('cost_center_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-status">Status</label>
                <select id="f-status" name="status_id">
                    <?php if ($isNew): ?><option value="">Automatisch (Lagerbestand)</option><?php endif; ?>
                    <?php foreach ($statuses as $s): $disabled = (int) $s['is_final'] === 1 && !$canRetire && (int) ($row['status_id'] ?? 0) !== (int) $s['id']; ?>
                        <option value="<?= (int) $s['id'] ?>"<?= selected(form_value($row, 'status_id'), $s['id']) ?><?= $disabled ? ' disabled' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= field_error('status_id') ?>
            </div>
            <div class="form-group">
                <label for="f-parent">Übergeordnetes Asset (Inventarnr.)</label>
                <input id="f-parent" list="parent-assets" class="mono" placeholder="z. B. PC24001" data-parent-input value="<?= e(!$isNew && $row['parent_inventory_number'] ? $row['parent_inventory_number'] : '') ?>" autocomplete="off">
                <input type="hidden" name="parent_asset_id" value="<?= e(form_value($row, 'parent_asset_id')) ?>" data-parent-id>
                <datalist id="parent-assets"></datalist>
                <?= field_error('parent_asset_id') ?>
                <span class="form-hint">z. B. Dockingstation am Notebook. Leer lassen, wenn nicht zutreffend.</span>
            </div>
        </div>
        <div class="form-group">
            <label for="f-note">Bemerkung</label>
            <textarea id="f-note" name="note" rows="3" maxlength="5000"><?= e(form_value($row, 'note')) ?></textarea>
            <?= field_error('note') ?>
        </div>

        <?php if ($isNew): ?>
        <div class="form-row mt-3" data-manual-invno-row<?= form_value($row, 'inventory_number') !== '' || has_error('inventory_number') ? '' : ' hidden' ?>>
            <div class="form-group">
                <label for="f-invno">Inventarnummer</label>
                <input id="f-invno" name="inventory_number" class="mono" maxlength="20" value="<?= e(form_value($row, 'inventory_number')) ?>" placeholder="z. B. PC24006" autocapitalize="characters" data-manual-invno>
                <?= field_error('inventory_number') ?>
                <span class="form-hint">Manuell vergebene Inventarnummer für den gewählten Gerätetyp (Format PRÄFIX + JJ + Nummer).</span>
            </div>
        </div>
        <details class="mt-3"<?= form_value($row, 'is_legacy') === '1' ? ' open' : '' ?>>
            <summary class="text-sm">Altbestand nachinventarisieren</summary>
            <div class="form-row mt-3">
                <div class="form-group">
                    <label class="checkbox-field"><input type="checkbox" name="is_legacy" value="1"<?= form_checked($row, 'is_legacy', false) ?>> <span>Altbestand – Inventarnummer mit Jahrescode <strong>88</strong> vergeben (z. B. PC88001)</span></label>
                </div>
            </div>
        </details>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <?php if ($isNew): ?><button type="submit" class="btn btn-secondary" name="save_and_new" value="1"><?= icon('plus') ?> Speichern &amp; weiteres anlegen</button><?php endif; ?>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
    <?php if ($isNew): ?>
    <dialog id="inventory-number-dialog" class="dialog" data-inventory-dialog>
        <div class="dialog-header"><h2>Inventarnummer</h2><button type="button" class="btn btn-ghost btn-sm" data-dialog-close aria-label="Schließen"><?= icon('x') ?></button></div>
        <div class="dialog-body">
            <p data-inventory-dialog-message></p>
        </div>
        <div class="dialog-footer">
            <button type="button" class="btn btn-ghost" data-inventory-dialog-no>Nein</button>
            <button type="button" class="btn btn-primary" data-inventory-dialog-yes>Ja</button>
        </div>
    </dialog>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/category-filter.js', '/js/picker.js', '/js/asset-form.js']; include __DIR__ . '/../partials/app_layout.php'; ?>
