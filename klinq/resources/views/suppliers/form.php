<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $back = $isNew ? '/suppliers' : '/suppliers/' . (int) $row['id']; ?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-wide">
    <form method="post" action="<?= e($isNew ? '/suppliers' : '/suppliers/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo ?? '') ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Firmenname *</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="200" autofocus>
                <?= field_error('name') ?>
            </div>
            <div class="form-group">
                <label for="f-customer">Kundennummer</label>
                <input id="f-customer" name="customer_number" value="<?= e(form_value($row, 'customer_number')) ?>" maxlength="80" class="mono">
                <?= field_error('customer_number') ?>
            </div>
        </div>
        <h3 class="text-sm text-muted">Anschrift</h3>
        <div class="form-group">
            <label for="f-street">Straße, Hausnummer</label>
            <input id="f-street" name="street" value="<?= e(form_value($row, 'street')) ?>" maxlength="200">
        </div>
        <div class="form-row form-row-3">
            <div class="form-group"><label for="f-plz">PLZ</label><input id="f-plz" name="postal_code" value="<?= e(form_value($row, 'postal_code')) ?>" maxlength="20"></div>
            <div class="form-group"><label for="f-city">Ort</label><input id="f-city" name="city" value="<?= e(form_value($row, 'city')) ?>" maxlength="120"></div>
            <div class="form-group"><label for="f-country">Land</label><input id="f-country" name="country" value="<?= e(form_value($row, 'country', $isNew ? 'Deutschland' : '')) ?>" maxlength="80"></div>
        </div>
        <h3 class="text-sm text-muted">Kontakt</h3>
        <div class="form-row form-row-3">
            <div class="form-group"><label for="f-contact">Ansprechpartner</label><input id="f-contact" name="contact_person" value="<?= e(form_value($row, 'contact_person')) ?>" maxlength="200"></div>
            <div class="form-group"><label for="f-email">E-Mail</label><input id="f-email" name="email" type="email" value="<?= e(form_value($row, 'email')) ?>"><?= field_error('email') ?></div>
            <div class="form-group"><label for="f-phone">Telefon</label><input id="f-phone" name="phone" type="tel" value="<?= e(form_value($row, 'phone')) ?>" maxlength="60"></div>
        </div>
        <div class="form-group"><label for="f-web">Webseite</label><input id="f-web" name="website" value="<?= e(form_value($row, 'website')) ?>" inputmode="url"><?= field_error('website') ?></div>
        <div class="form-group"><label for="f-note">Bemerkung</label><textarea id="f-note" name="note" rows="3"><?= e(form_value($row, 'note')) ?></textarea></div>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
