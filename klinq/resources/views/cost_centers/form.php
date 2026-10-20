<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; ?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="/cost-centers"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-narrow">
    <form method="post" action="<?= e($isNew ? '/cost-centers' : '/cost-centers/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo ?? '') ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="f-number">Nummer (5 Ziffern) *</label>
                <input id="f-number" name="number" value="<?= e(form_value($row, 'number')) ?>" required pattern="\d{5}" inputmode="numeric" maxlength="5" class="mono" autofocus>
                <?= field_error('number') ?>
            </div>
            <div class="form-group">
                <label for="f-location">Standort</label>
                <select id="f-location" name="location_id">
                    <option value="">Kein Standort</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected(form_value($row, 'location_id'), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('location_id') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="f-desc">Beschreibung *</label>
            <input id="f-desc" name="description" value="<?= e(form_value($row, 'description')) ?>" required maxlength="200">
            <?= field_error('description') ?>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="/cost-centers">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
