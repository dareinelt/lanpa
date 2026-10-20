<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $duplicates = $duplicates ?? [];
$presetManufacturer = $isNew ? ($_GET['manufacturer_id'] ?? '') : '';
$selectedType = form_value($row, 'asset_type_id');
?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="/articles"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form">
    <?php if (!$manufacturers): ?>
        <div class="alert alert-warning"><?= icon('warning') ?> Es ist noch kein aktiver Hersteller vorhanden. <a href="/manufacturers/new">Zuerst Hersteller anlegen</a>.</div>
    <?php endif; ?>
    <?php if ($duplicates): ?>
    <div class="alert alert-warning alert-stacked" role="alert">
        <div class="flex gap-1"><?= icon('warning') ?> <strong>Mögliche Dubletten gefunden.</strong> Bitte prüfen Sie, ob der Artikel bereits existiert.</div>
        <ul class="duplicate-hint">
            <?php foreach ($duplicates as $d): ?>
                <li><a href="/articles/<?= (int) $d['id'] ?>/edit"><?= e(($d['manufacturer_name'] ?? '') !== '' ? $d['manufacturer_name'] . ' ' : '') ?><?= e($d['name']) ?></a><?= !empty($d['article_number']) ? ' <span class="mono">(' . e($d['article_number']) . ')</span>' : '' ?> – <?= e($d['reason']) ?><?= (int) $d['is_active'] ? '' : ' · inaktiv' ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mb-0 text-sm mt-2">Wenn es sich trotzdem um einen neuen Artikel handelt, bestätigen Sie unten mit „Trotzdem speichern“.</p>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= e($isNew ? '/articles' : '/articles/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo ?? '') ?>">
        <?php if ($duplicates): ?><input type="hidden" name="ignore_duplicates" value="1"><?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label for="f-manufacturer">Hersteller *</label>
                <select id="f-manufacturer" name="manufacturer_id" required data-duplicate-context="manufacturer_id">
                    <option value="">Bitte wählen</option>
                    <?php foreach ($manufacturers as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected(form_value($row, 'manufacturer_id', $presetManufacturer), $m['id']) ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('manufacturer_id') ?>
            </div>
            <div class="form-group">
                <label for="f-type">Assettyp *</label>
                <select id="f-type" name="asset_type_id" required data-category-filter>
                    <option value="">Bitte wählen</option>
                    <?php foreach ($assetTypes as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($selectedType, $t['id']) ?>><?= e($t['name']) ?> (<?= e($t['inventory_prefix']) ?>)</option><?php endforeach; ?>
                </select>
                <?= field_error('asset_type_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Bezeichnung *</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="200" placeholder="z. B. ThinkPad T14 Gen 5" data-duplicate-check="/api/articles/check<?= $isNew ? '' : '?exclude=' . (int) $row['id'] ?>" data-duplicate-entity="articles" <?= has_error('name') ? 'aria-invalid="true"' : '' ?>>
                <?= field_error('name') ?>
                <div id="duplicate-live" class="duplicate-hint" hidden></div>
            </div>
            <div class="form-group">
                <label for="f-number">Artikelnummer</label>
                <input id="f-number" name="article_number" value="<?= e(form_value($row, 'article_number')) ?>" maxlength="100" class="mono" data-duplicate-context="article_number">
                <?= field_error('article_number') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="f-category">Kategorie</label>
            <select id="f-category" name="asset_category_id">
                <option value="">Keine</option>
                <?php foreach ($categories as $cat): ?><option value="<?= (int) $cat['id'] ?>" data-type="<?= (int) $cat['asset_type_id'] ?>"<?= selected(form_value($row, 'asset_category_id'), $cat['id']) ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('asset_category_id') ?>
            <span class="form-hint">Nur Kategorien des gewählten Assettyps.</span>
        </div>
        <div class="form-group">
            <label for="f-desc">Beschreibung</label>
            <textarea id="f-desc" name="description" rows="3"><?= e(form_value($row, 'description')) ?></textarea>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <label class="checkbox-field"><input type="checkbox" name="is_handover_relevant" value="1"<?= form_checked($row, 'is_handover_relevant', false) ?>> <span>Relevant für Übergabeprotokoll</span></label>
        <span class="form-hint">Assets dieses Artikels erscheinen im Übergabeprotokoll des Mitarbeiters (z. B. Notebook, Smartphone – nicht Kabel oder Verbrauchsmaterial).</span>
        <label class="checkbox-field mt-3"><input type="checkbox" name="is_consumable" value="1"<?= form_checked($row, 'is_consumable', false) ?>> <span>Verbrauchsmaterial</span></label>
        <div class="form-row">
            <div class="form-group"><label for="f-min-stock">Mindestbestand</label><input id="f-min-stock" type="number" name="minimum_stock" min="0" value="<?= e(form_value($row, 'minimum_stock')) ?>"><span class="form-hint">Bei Unterschreitung wird ein Bestellvorschlag angezeigt.</span></div>
            <div class="form-group"><label for="f-stock">Aktueller Bestand</label><input id="f-stock" type="number" name="stock_quantity" min="0" value="<?= e(form_value($row, 'stock_quantity', '0')) ?>"></div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn <?= $duplicates ? 'btn-warning' : 'btn-primary' ?>"><?= icon('check') ?> <?= $duplicates ? 'Trotzdem speichern' : 'Speichern' ?></button>
            <a class="btn btn-ghost" href="/articles">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/category-filter.js', '/js/duplicate-check.js']; include __DIR__ . '/../partials/app_layout.php'; ?>
