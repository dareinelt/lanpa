<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $fromAd = !$isNew && $row['source'] === 'ad'; $back = $isNew ? '/employees' : '/employees/' . (int) $row['id']; ?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-wide">
    <?php if ($fromAd): ?>
        <div class="alert alert-info"><?= icon('info') ?> Dieser Mitarbeiter stammt aus dem Active Directory. Personendaten werden bei der Synchronisation überschrieben; hier können nur Standort und Kostenstelle lokal zugeordnet werden.</div>
    <?php endif; ?>
    <form method="post" action="<?= e($isNew ? '/employees' : '/employees/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <?php $ro = $fromAd ? 'readonly' : ''; ?>
        <div class="form-row">
            <div class="form-group"><label for="f-first">Vorname</label><input id="f-first" name="first_name" value="<?= e(form_value($row, 'first_name')) ?>" maxlength="120" <?= $ro ?> autofocus><?= field_error('first_name') ?></div>
            <div class="form-group"><label for="f-last">Nachname *</label><input id="f-last" name="last_name" value="<?= e(form_value($row, 'last_name')) ?>" maxlength="120" required <?= $ro ?>><?= field_error('last_name') ?></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="f-display">Anzeigename</label><input id="f-display" name="display_name" value="<?= e(form_value($row, 'display_name')) ?>" maxlength="250" <?= $ro ?> placeholder="wird aus Vor- und Nachname gebildet"><?= field_error('display_name') ?></div>
            <div class="form-group"><label for="f-user">Benutzername</label><input id="f-user" name="username" value="<?= e(form_value($row, 'username')) ?>" maxlength="120" class="mono" <?= $ro ?> autocapitalize="none"><?= field_error('username') ?></div>
        </div>
        <div class="form-row form-row-3">
            <div class="form-group"><label for="f-email">E-Mail</label><input id="f-email" name="email" type="email" value="<?= e(form_value($row, 'email')) ?>" <?= $ro ?>><?= field_error('email') ?></div>
            <div class="form-group"><label for="f-pn">Personalnummer</label><input id="f-pn" name="personnel_number" value="<?= e(form_value($row, 'personnel_number')) ?>" maxlength="50" class="mono" <?= $ro ?>><?= field_error('personnel_number') ?></div>
            <div class="form-group"><label for="f-phone">Telefon</label><input id="f-phone" name="phone" type="tel" value="<?= e(form_value($row, 'phone')) ?>" maxlength="60" <?= $ro ?>></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="f-dep">Abteilung</label><input id="f-dep" name="department" value="<?= e(form_value($row, 'department')) ?>" maxlength="150" <?= $ro ?>></div>
            <div class="form-group"><label for="f-pos">Position</label><input id="f-pos" name="position" value="<?= e(form_value($row, 'position')) ?>" maxlength="150" <?= $ro ?>></div>
        </div>
        <h3 class="text-sm text-muted">Zuordnung</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="f-location">Standort</label>
                <select id="f-location" name="location_id">
                    <option value="">Kein Standort</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected(form_value($row, 'location_id'), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($fromAd && $row['ad_location']): ?><span class="form-hint">AD-Angabe: <?= e($row['ad_location']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="f-cc">Kostenstelle</label>
                <select id="f-cc" name="cost_center_id">
                    <option value="">Keine Kostenstelle</option>
                    <?php foreach ($costCenters as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected(form_value($row, 'cost_center_id'), $c['id']) ?>><?= e($c['number']) ?> – <?= e($c['description']) ?></option><?php endforeach; ?>
                </select>
                <?php if ($fromAd && $row['ad_cost_center']): ?><span class="form-hint">AD-Angabe: <?= e($row['ad_cost_center']) ?></span><?php endif; ?>
            </div>
        </div>
        <?php if (!$fromAd): ?>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
