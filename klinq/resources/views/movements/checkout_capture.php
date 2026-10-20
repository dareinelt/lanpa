<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? [];
$val = static fn (string $key, mixed $default = ''): string => (string) ($old[$key] ?? $default);
?>
<div class="page-header">
    <div>
        <p class="breadcrumb text-sm text-muted mb-2"><a href="/assets/<?= (int) $asset['id'] ?>"><?= icon('arrow-left', 'icon icon-sm') ?> Zurück</a></p>
        <h1><?= icon('checkout') ?> Entnahme <span class="mono"><?= e($asset['inventory_number']) ?></span></h1>
        <p class="page-subtitle text-muted mb-0"><?= e($asset['asset_type_name'] ?? '') ?><?= $asset['name'] ? ' · ' . e($asset['name']) : '' ?></p>
    </div>
</div>

<div class="alert alert-info"><?= icon('info') ?> Manuelle Erfassung am Desktop (kein Kamera-Scan – dieser ist nur auf Mobilgeräten verfügbar). Der Vorgang wird erst nach Eingabe des eigenen Passworts als elektronische Signatur gespeichert.</div>

<div class="card mt-4">
    <form method="post" action="/movements/checkout" enctype="multipart/form-data" class="card-form-wide" id="checkout-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inventory_number" value="<?= e($asset['inventory_number']) ?>">
        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
        <input type="hidden" name="asset_version" value="<?= (int) $asset['version'] ?>">
        <input type="hidden" name="client_transaction_id" value="<?= e($old['client_transaction_id'] ?? bin2hex(random_bytes(16))) ?>">

        <div class="form-row">
            <div class="form-group<?= has_error('employee_id') ? ' has-error' : '' ?>">
                <label for="f-employee">Mitarbeiter <span class="required">*</span></label>
                <select id="f-employee" name="employee_id" required>
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected($val('employee_id'), $emp['id']) ?>><?= e($emp['display_name']) ?><?= $emp['department'] ? ' · ' . e($emp['department']) : '' ?></option><?php endforeach; ?>
                </select>
                <?= field_error('employee_id') ?>
            </div>
            <div class="form-group<?= has_error('location_id') ? ' has-error' : '' ?>">
                <label for="f-location">Neuer Standort</label>
                <select id="f-location" name="location_id">
                    <option value="">– unbekannt (Vorgang bleibt offen) –</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected($val('location_id'), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('location_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group<?= has_error('cost_center_id') ? ' has-error' : '' ?>">
                <label for="f-cc">Kostenstelle</label>
                <select id="f-cc" name="cost_center_id">
                    <option value="">Vom Mitarbeiter übernehmen</option>
                    <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected($old['cost_center_id'] ?? $costCenterId, $cc['id']) ?>><?= e($cc['number']) ?> – <?= e($cc['description']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('cost_center_id') ?>
            </div>
            <div class="form-group<?= has_error('expected_return_at') ? ' has-error' : '' ?>">
                <label for="f-return">Rückgabe erwartet am</label>
                <input id="f-return" type="date" name="expected_return_at" value="<?= e($val('expected_return_at')) ?>">
                <?= field_error('expected_return_at') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group<?= has_error('movement_date') ? ' has-error' : '' ?>">
                <label for="f-date">Datum</label>
                <input id="f-date" type="date" name="movement_date" value="<?= e($val('movement_date', $today)) ?>" max="<?= e($today) ?>">
                <?= field_error('movement_date') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="f-note">Notiz</label>
            <textarea id="f-note" name="note" rows="2" maxlength="2000"><?= e($val('note')) ?></textarea>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-primary" data-dialog-open="sign-dialog" data-dialog-validate="checkout-form"><?= icon('signature') ?> Signieren &amp; Entnahme speichern</button>
            <a class="btn btn-ghost" href="/assets/<?= (int) $asset['id'] ?>">Abbrechen</a>
        </div>
    </form>
</div>

<dialog id="sign-dialog" class="dialog">
    <h2>Elektronische Signatur</h2>
    <p class="text-muted">Bitte Ihr eigenes Passwort eingeben, um die Entnahme zu bestätigen. Der Vorgang wird als „elektronisch signiert“ protokolliert.</p>
    <div class="form-group<?= has_error('signature_password') ? ' has-error' : '' ?>">
        <label for="f-sign-password">Passwort <span class="required">*</span></label>
        <input id="f-sign-password" type="password" name="signature_password" form="checkout-form" autocomplete="current-password" required>
        <?= field_error('signature_password') ?>
    </div>
    <div class="form-actions">
        <button type="submit" form="checkout-form" class="btn btn-primary"><?= icon('check') ?> Signieren &amp; speichern</button>
        <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
    </div>
</dialog>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
