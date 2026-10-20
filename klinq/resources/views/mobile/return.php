<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? [];
$inv = rawurlencode($asset['inventory_number']);
$condition = $old['condition_code'] ?? 'ok';
?>
<?php $compact = true; include __DIR__ . '/_asset_card.php'; ?>
<?php if ($asset['employee_name']): ?><p class="text-muted mb-0">Rückgabe von <strong><?= e($asset['employee_name']) ?></strong><?= $asset['location_path'] ? ' · bisher ' . e($asset['location_path']) : '' ?></p><?php endif; ?>
<form method="post" action="/m/return" class="m-form" id="movement-form" data-movement="return" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="inventory_number" value="<?= e($asset['inventory_number']) ?>">
    <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
    <input type="hidden" name="asset_version" value="<?= (int) $asset['version'] ?>">
    <input type="hidden" name="client_transaction_id" value="<?= e($old['client_transaction_id'] ?? bin2hex(random_bytes(16))) ?>">

    <fieldset class="form-group<?= has_error('condition_code') ? ' has-error' : '' ?>">
        <legend class="form-group label">Zustand <span class="required">*</span></legend>
        <div class="m-choice-grid" role="radiogroup">
            <?php foreach ($conditions as $code => $label): ?>
                <label class="m-choice"><input type="radio" name="condition_code" value="<?= e($code) ?>"<?= $condition === $code ? ' checked' : '' ?> data-condition><span><?= e($label) ?></span></label>
            <?php endforeach; ?>
        </div>
        <?= field_error('condition_code') ?>
    </fieldset>

    <label class="checkbox-field"><input type="checkbox" name="has_damage" value="1"<?= !empty($old['has_damage']) ? ' checked' : '' ?> data-toggle-target="#damage-fields" id="f-has-damage"> Schaden festgestellt</label>
    <div id="damage-fields" class="m-form" hidden>
        <div class="form-group<?= has_error('damage_description') ? ' has-error' : '' ?>">
            <label for="f-damage">Schadensbeschreibung <span class="required">*</span></label>
            <textarea id="f-damage" name="damage_description" rows="3" maxlength="2000" placeholder="Was ist beschädigt? Wie stark?" data-required-when-visible><?= e($old['damage_description'] ?? '') ?></textarea>
            <?= field_error('damage_description') ?>
        </div>
        <div class="form-group m-photo-input">
            <label for="f-photos">Fotos vom Schaden</label>
            <input id="f-photos" type="file" name="photos[]" accept="image/*" capture="environment" multiple data-photo-input>
            <div class="m-photo-previews" data-photo-previews></div>
        </div>
    </div>

    <label class="checkbox-field"><input type="checkbox" name="accessories_checked" value="1"<?= !empty($old['accessories_checked']) || empty($old) ? ' checked' : '' ?>> Zubehör vollständig (Netzteil, Kabel, Hülle …)</label>
    <div class="form-group">
        <label for="f-acc">Zubehör-Notiz</label>
        <input id="f-acc" type="text" name="accessories_note" value="<?= e($old['accessories_note'] ?? '') ?>" maxlength="500" placeholder="z. B. Netzteil fehlt">
    </div>

    <?php $name = 'to_location_id'; $label = 'Zurück an Standort'; $searchUrl = '/api/locations/search'; $required = false; $placeholder = 'Lager, Raum, Kürzel …'; $hint = 'Leer lassen, wenn unbekannt – der Vorgang bleibt dann offen.';
        $selected = $location ? ['id' => $location['id'], 'name' => $location['name'], 'meta' => $location['full_path'] ?? ''] : null;
        include __DIR__ . '/_picker.php'; unset($hint); ?>

    <div class="form-group<?= has_error('target_status_code') ? ' has-error' : '' ?>">
        <label for="f-target">Neuer Status</label>
        <select id="f-target" name="target_status_code" data-target-status>
            <option value="">Automatisch nach Zustand</option>
            <?php foreach ($returnTargets as $code => $label): if ($code === 'retired' && !$canRetire) { continue; } ?><option value="<?= e($code) ?>"<?= selected($old['target_status_code'] ?? '', $code) ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <span class="form-hint" data-target-hint>In Ordnung / Gebrauchsspuren → Lager, Beschädigt → Reparatur, Defekt → Defekt.</span>
        <?= field_error('target_status_code') ?>
    </div>

    <details class="m-more">
        <summary class="btn btn-ghost btn-block">Weitere Angaben</summary>
        <div class="m-form mt-3">
            <div class="form-group<?= has_error('movement_date') ? ' has-error' : '' ?>">
                <label for="f-date">Datum</label>
                <input id="f-date" type="date" name="movement_date" value="<?= e($old['movement_date'] ?? $today) ?>" max="<?= e($today) ?>">
                <?= field_error('movement_date') ?>
            </div>
            <div class="form-group">
                <label for="f-note">Notiz</label>
                <textarea id="f-note" name="note" rows="2" maxlength="2000"><?= e($old['note'] ?? '') ?></textarea>
            </div>
        </div>
    </details>

    <?php if ($children): ?>
    <div class="alert alert-info"><?= icon('info') ?> <?= count($children) ?> zugehörige Assets (z. B. Zubehör) bleiben zugeordnet – bei Bedarf separat zurücknehmen.</div>
    <?php endif; ?>

    <?php if ($mailEnabled ?? false): ?>
    <label class="checkbox-field"><input type="checkbox" name="send_email" value="1"<?= !empty($old['send_email']) ? ' checked' : '' ?>> Retourennachweis per E-Mail an den Mitarbeiter senden</label>
    <?php endif; ?>

    <div class="m-actions">
        <button type="submit" class="btn btn-primary btn-xl btn-block"><?= icon('check') ?> Rückgabe speichern</button>
        <a class="btn btn-ghost btn-block" href="/m/asset/<?= $inv ?>">Abbrechen</a>
    </div>
</form>
<?php $innerContent = ob_get_clean(); $backHref = '/m/asset/' . $inv; $scripts = ['/js/picker.js', '/js/mobile.js']; include __DIR__ . '/../partials/mobile_layout.php'; ?>
