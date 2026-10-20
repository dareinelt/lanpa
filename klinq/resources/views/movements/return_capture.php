<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? [];
$val = static fn (string $key, mixed $default = ''): string => (string) ($old[$key] ?? $default);
$condition = $val('condition_code', 'ok');
?>
<div class="page-header">
    <div>
        <p class="breadcrumb text-sm text-muted mb-2"><a href="/assets/<?= (int) $asset['id'] ?>"><?= icon('arrow-left', 'icon icon-sm') ?> Zurück</a></p>
        <h1><?= icon('return') ?> Rückgabe <span class="mono"><?= e($asset['inventory_number']) ?></span></h1>
        <p class="page-subtitle text-muted mb-0"><?= $asset['employee_name'] ? 'von ' . e($asset['employee_name']) : '' ?><?= $asset['location_path'] ? ' · bisher ' . e($asset['location_path']) : '' ?></p>
    </div>
</div>

<div class="alert alert-info"><?= icon('info') ?> Manuelle Erfassung am Desktop (kein Kamera-Scan – dieser ist nur auf Mobilgeräten verfügbar). Der Vorgang wird erst nach Eingabe des eigenen Passworts als elektronische Signatur gespeichert.</div>

<div class="card mt-4">
    <form method="post" action="/movements/return" enctype="multipart/form-data" class="card-form-wide" id="return-form">
        <?= csrf_field() ?>
        <input type="hidden" name="inventory_number" value="<?= e($asset['inventory_number']) ?>">
        <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
        <input type="hidden" name="asset_version" value="<?= (int) $asset['version'] ?>">
        <input type="hidden" name="client_transaction_id" value="<?= e($old['client_transaction_id'] ?? bin2hex(random_bytes(16))) ?>">

        <div class="form-group<?= has_error('condition_code') ? ' has-error' : '' ?>">
            <label for="f-condition">Zustand <span class="required">*</span></label>
            <select id="f-condition" name="condition_code" required>
                <?php foreach ($conditions as $code => $label): ?><option value="<?= e($code) ?>"<?= $condition === $code ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('condition_code') ?>
        </div>

        <label class="checkbox-field"><input type="checkbox" name="has_damage" value="1"<?= !empty($old['has_damage']) ? ' checked' : '' ?> data-toggle-target="#damage-fields"> Schaden festgestellt</label>
        <div id="damage-fields" class="form-group<?= has_error('damage_description') ? ' has-error' : '' ?>">
            <label for="f-damage">Schadensbeschreibung</label>
            <textarea id="f-damage" name="damage_description" rows="2" maxlength="2000"><?= e($val('damage_description')) ?></textarea>
            <?= field_error('damage_description') ?>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="accessories_checked" value="1"<?= !empty($old['accessories_checked']) || empty($old) ? ' checked' : '' ?>> Zubehör vollständig geprüft</label>
        <div class="form-group">
            <label for="f-acc">Zubehör-Notiz</label>
            <input id="f-acc" type="text" name="accessories_note" value="<?= e($val('accessories_note')) ?>" maxlength="500" placeholder="z. B. Netzteil fehlt">
        </div>
        <div class="form-group">
            <label for="f-photos">Fotos hinzufügen</label>
            <input id="f-photos" type="file" name="photos[]" accept="image/*" multiple>
        </div>

        <div class="form-row">
            <div class="form-group<?= has_error('to_location_id') ? ' has-error' : '' ?>">
                <label for="f-location">Zurück an Standort</label>
                <select id="f-location" name="to_location_id">
                    <option value="">– unbekannt (Vorgang bleibt offen) –</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected($old['to_location_id'] ?? $locationId, $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('to_location_id') ?>
            </div>
            <div class="form-group<?= has_error('target_status_code') ? ' has-error' : '' ?>">
                <label for="f-target">Neuer Status</label>
                <select id="f-target" name="target_status_code">
                    <option value="">Automatisch nach Zustand</option>
                    <?php foreach ($returnTargets as $code => $label): if ($code === 'retired' && !$canRetire) { continue; } ?><option value="<?= e($code) ?>"<?= selected($val('target_status_code'), $code) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <span class="form-hint">In Ordnung / Gebrauchsspuren → Lager, Beschädigt → Reparatur, Defekt → Defekt.</span>
                <?= field_error('target_status_code') ?>
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
            <button type="button" class="btn btn-primary" data-dialog-open="sign-dialog" data-dialog-validate="return-form"><?= icon('signature') ?> Signieren &amp; Rückgabe speichern</button>
            <a class="btn btn-ghost" href="/assets/<?= (int) $asset['id'] ?>">Abbrechen</a>
        </div>
    </form>
</div>

<dialog id="sign-dialog" class="dialog">
    <h2>Elektronische Signatur</h2>
    <p class="text-muted">Bitte Ihr eigenes Passwort eingeben, um die Rückgabe zu bestätigen. Der Vorgang wird als „elektronisch signiert“ protokolliert.</p>
    <div class="form-group<?= has_error('signature_password') ? ' has-error' : '' ?>">
        <label for="f-sign-password">Passwort <span class="required">*</span></label>
        <input id="f-sign-password" type="password" name="signature_password" form="return-form" autocomplete="current-password" required>
        <?= field_error('signature_password') ?>
    </div>
    <div class="form-actions">
        <button type="submit" form="return-form" class="btn btn-primary"><?= icon('check') ?> Signieren &amp; speichern</button>
        <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
    </div>
</dialog>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
