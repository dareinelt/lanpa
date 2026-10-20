<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? [];
$inv = rawurlencode($asset['inventory_number']);
?>
<?php $compact = true; include __DIR__ . '/_asset_card.php'; ?>
<form method="post" action="/m/checkout" class="m-form" id="movement-form" data-movement="checkout">
    <?= csrf_field() ?>
    <input type="hidden" name="inventory_number" value="<?= e($asset['inventory_number']) ?>">
    <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
    <input type="hidden" name="asset_version" value="<?= (int) $asset['version'] ?>">
    <input type="hidden" name="client_transaction_id" value="<?= e($old['client_transaction_id'] ?? bin2hex(random_bytes(16))) ?>">

    <?php $name = 'employee_id'; $label = 'Mitarbeiter'; $searchUrl = '/api/employees/search'; $required = true; $placeholder = 'Name, Benutzername oder Personalnr.';
        $selected = $employee ? ['id' => $employee['id'], 'name' => $employee['display_name'], 'meta' => implode(' · ', array_filter([$employee['personnel_number'] ?? null, $employee['department'] ?? null]))] : null;
        include __DIR__ . '/_picker.php'; ?>

    <?php $name = 'location_id'; $label = 'Neuer Standort'; $searchUrl = '/api/locations/search'; $required = false; $placeholder = 'Gebäude, Raum, Kürzel …'; $hint = 'Leer lassen, wenn unbekannt – der Vorgang bleibt dann offen.';
        $selected = $location ? ['id' => $location['id'], 'name' => $location['name'], 'meta' => $location['full_path'] ?? ''] : null;
        include __DIR__ . '/_picker.php'; unset($hint); ?>

    <div class="form-group<?= has_error('cost_center_id') ? ' has-error' : '' ?>">
        <label for="f-cc">Kostenstelle</label>
        <select id="f-cc" name="cost_center_id">
            <option value="">Vom Mitarbeiter übernehmen</option>
            <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected($costCenterId, $cc['id']) ?>><?= e($cc['number']) ?> – <?= e($cc['description']) ?></option><?php endforeach; ?>
        </select>
        <span class="form-hint">Ohne Auswahl wird die Kostenstelle des Mitarbeiters verwendet.</span>
        <?= field_error('cost_center_id') ?>
    </div>

    <details class="m-more">
        <summary class="btn btn-ghost btn-block">Weitere Angaben</summary>
        <div class="m-form mt-3">
            <div class="form-group<?= has_error('movement_date') ? ' has-error' : '' ?>">
                <label for="f-date">Datum</label>
                <input id="f-date" type="date" name="movement_date" value="<?= e($old['movement_date'] ?? $today) ?>" max="<?= e($today) ?>">
                <?= field_error('movement_date') ?>
            </div>
            <div class="form-group<?= has_error('expected_return_at') ? ' has-error' : '' ?>">
                <label for="f-return">Rückgabe erwartet am</label>
                <input id="f-return" type="date" name="expected_return_at" value="<?= e($old['expected_return_at'] ?? '') ?>">
                <?= field_error('expected_return_at') ?>
            </div>
            <div class="form-group">
                <label for="f-note">Notiz</label>
                <textarea id="f-note" name="note" rows="2" maxlength="2000"><?= e($old['note'] ?? '') ?></textarea>
            </div>
        </div>
    </details>

    <?php if ($mailEnabled ?? false): ?>
    <label class="checkbox-field"><input type="checkbox" name="send_email" value="1"<?= !empty($old['send_email']) ? ' checked' : '' ?>> Entnahmenachweis per E-Mail an den Mitarbeiter senden</label>
    <?php endif; ?>

    <div class="m-actions">
        <button type="submit" class="btn btn-primary btn-xl btn-block"><?= icon('check') ?> Entnahme speichern</button>
        <a class="btn btn-ghost btn-block" href="/m/asset/<?= $inv ?>">Abbrechen</a>
    </div>
</form>
<?php $innerContent = ob_get_clean(); $backHref = '/m/asset/' . $inv; $scripts = ['/js/picker.js', '/js/mobile.js']; include __DIR__ . '/../partials/mobile_layout.php'; ?>
