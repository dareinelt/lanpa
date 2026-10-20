<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$isCheckout = $row['type'] === 'checkout';
$isOpen = $row['status'] === 'open';
$editable = $isOpen || $row['status'] === 'completed';
$canEdit = $can('movements.complete') && $editable;
$old = $_SESSION['_old_input'] ?? null;
$val = static fn (string $key, mixed $default = null): string => $old !== null ? (string) ($old[$key] ?? '') : (string) ($row[$key] ?? $default ?? '');
$statusBadge = match ($row['status']) { 'open' => badge('Offen', 'warning'), 'completed' => badge('Abgeschlossen', 'success'), default => badge('Storniert', 'neutral') };
?>
<div class="page-header">
    <div>
        <p class="breadcrumb text-sm text-muted mb-2"><a href="<?= e($back) ?>"><?= icon('arrow-left', 'icon icon-sm') ?> Zurück</a></p>
        <h1><?= icon($isCheckout ? 'checkout' : 'return') ?> <?= $isCheckout ? 'Entnahme' : 'Retoure' ?> <a class="mono" href="/assets/<?= (int) $row['asset_id'] ?>"><?= e($row['inventory_number']) ?></a> <?= $statusBadge ?></h1>
        <p class="page-subtitle text-muted mb-0"><?= fmt_datetime($row['movement_at']) ?> · erfasst von <?= e($row['created_by_name'] ?? '?') ?> (<?= e(App\Services\MovementService::SOURCES[$row['source']] ?? $row['source']) ?>)<?php if ($row['completed_at']): ?> · abgeschlossen <?= fmt_datetime($row['completed_at']) ?> von <?= e($row['completed_by_name'] ?? '?') ?><?php endif; ?><?php if ((int) ($row['signed_electronically'] ?? 0) === 1): ?> · <?= badge('Elektronische Signatur verwendet', 'success') ?><?php endif; ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/assets/<?= (int) $row['asset_id'] ?>"><?= icon('box') ?> Asset</a>
        <?php if ($canEdit): ?><button type="button" class="btn btn-danger btn-outline" data-dialog-open="cancel-dialog"><?= icon('x') ?> Stornieren</button><?php endif; ?>
    </div>
</div>

<?php if ($isOpen && $missing): ?>
<div class="alert alert-warning"><?= icon('warning') ?> Fehlende Angaben: <strong><?= e(implode(', ', $missing)) ?></strong>. Bitte ergänzen und den Vorgang abschließen.</div>
<?php elseif ($isOpen): ?>
<div class="alert alert-info"><?= icon('info') ?> Der Vorgang wurde vollständig erfasst, wartet aber noch auf die Prüfung und den Abschluss.</div>
<?php endif; ?>
<?php if ($row['status'] === 'cancelled'): ?>
<div class="alert alert-info"><?= icon('info') ?> Dieser Vorgang wurde storniert<?= $row['note'] ? ': ' . e($row['note']) : '.' ?></div>
<?php endif; ?>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header"><h2>Asset</h2></div>
        <dl class="detail-list">
            <dt>Inventarnummer</dt><dd><a class="mono font-semibold" href="/assets/<?= (int) $row['asset_id'] ?>"><?= e($row['inventory_number']) ?></a></dd>
            <dt>Typ</dt><dd><?= icon($row['asset_type_icon'] ?: 'box', 'icon icon-muted') ?> <?= e($row['asset_type_name']) ?></dd>
            <dt>Bezeichnung</dt><dd><?= e($row['asset_name'] ?: ($row['article_name'] ?? '–')) ?><?= $row['manufacturer_name'] ? ' <span class="text-muted">(' . e($row['manufacturer_name']) . ')</span>' : '' ?></dd>
            <dt>Seriennummer</dt><dd class="mono"><?= e($row['serial_number'] ?? '–') ?></dd>
            <dt>Aktueller Status</dt><dd><?= badge($row['asset_status_name'], $row['asset_status_color']) ?></dd>
        </dl>
    </div>
    <div class="card">
        <div class="card-header"><h2>Erfasst</h2></div>
        <dl class="detail-list">
            <dt>Mitarbeiter</dt><dd><?= $row['employee_name'] ? '<a href="/employees/' . (int) $row['employee_id'] . '">' . e($row['employee_name']) . '</a>' . ($row['employee_department'] ? ' <span class="text-muted text-sm">' . e($row['employee_department']) . '</span>' : '') : '<span class="text-warning">fehlt</span>' ?></dd>
            <?php if ($isCheckout): ?>
            <dt>Neuer Standort</dt><dd><?= $row['to_location_path'] ? e($row['to_location_path']) : '<span class="text-warning">fehlt</span>' ?></dd>
            <dt>Vorheriger Standort</dt><dd><?= e($row['from_location_path'] ?? '–') ?></dd>
            <dt>Kostenstelle</dt><dd><?= $row['cost_center_number'] ? '<span class="mono">' . e($row['cost_center_number']) . '</span> ' . e($row['cost_center_name']) : '<span class="text-warning">fehlt</span>' ?></dd>
            <dt>Rückgabe erwartet</dt><dd><?= fmt_date($row['expected_return_at']) ?></dd>
            <?php else: ?>
            <dt>Zustand</dt><dd><?= $row['condition_code'] ? e(App\Services\MovementService::CONDITIONS[$row['condition_code']] ?? $row['condition_code']) : '<span class="text-warning">fehlt</span>' ?><?= (int) $row['has_damage'] ? ' ' . badge('Schaden', 'danger') : '' ?></dd>
            <?php if ((int) $row['has_damage']): ?><dt>Schadensbeschreibung</dt><dd><?= nl2br_e($row['damage_description']) ?></dd><?php endif; ?>
            <dt>Zubehör</dt><dd><?= (int) $row['accessories_checked'] ? 'Vollständig geprüft' : '<span class="text-muted">Nicht geprüft</span>' ?><?= $row['accessories_note'] ? '<br><span class="text-sm">' . nl2br_e($row['accessories_note']) . '</span>' : '' ?></dd>
            <dt>Von</dt><dd><?= e($row['from_location_path'] ?? '–') ?></dd>
            <dt>Zurück an Standort</dt><dd><?= $row['to_location_path'] ? e($row['to_location_path']) : '<span class="text-warning">fehlt</span>' ?></dd>
            <dt>Zielstatus</dt><dd><?= e(App\Services\MovementService::RETURN_TARGETS[$row['target_status_code']] ?? $row['target_status_code'] ?? '–') ?></dd>
            <?php endif; ?>
            <?php if ($row['note'] && $row['status'] !== 'cancelled'): ?><dt>Notiz</dt><dd><?= nl2br_e($row['note']) ?></dd><?php endif; ?>
        </dl>
    </div>
</div>

<?php if ($documents || $canEdit && !$isCheckout): ?>
<div class="card mt-4">
    <div class="card-header"><h2>Fotos & Dokumente</h2><span class="text-muted text-sm"><?= count($documents) ?></span></div>
    <?php if ($documents): ?>
    <ul class="document-list">
        <?php foreach ($documents as $d): ?>
            <li class="document-item">
                <?php if (in_array(strtolower(pathinfo($d['original_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)): ?>
                    <a href="/documents/<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><img class="document-thumb" src="/documents/<?= (int) $d['id'] ?>" alt="<?= e($d['original_name']) ?>" loading="lazy"></a>
                <?php else: ?>
                    <a class="document-thumb document-thumb-file" href="/documents/<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= icon('document') ?></a>
                <?php endif; ?>
                <div class="document-meta">
                    <a href="/documents/<?= (int) $d['id'] ?>?download=1"><?= e($d['original_name']) ?></a>
                    <span class="text-muted text-xs"><?= fmt_bytes((int) $d['size_bytes']) ?> · <?= fmt_datetime($d['created_at']) ?> · <?= e($d['uploaded_by_name'] ?? '') ?></span>
                </div>
                <?php if ($canEdit): ?>
                <form method="post" action="/documents/<?= (int) $d['id'] ?>/delete" data-confirm="Dokument wirklich löschen?"><?= csrf_field() ?><button type="submit" class="btn btn-ghost btn-sm" title="Löschen"><?= icon('trash') ?></button></form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?><p class="text-muted mb-0">Keine Fotos hochgeladen.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="card mt-4" id="edit">
    <div class="card-header"><h2><?= $isOpen ? 'Vervollständigen' : 'Korrigieren' ?></h2></div>
    <form method="post" action="/movements/<?= (int) $row['id'] ?>" enctype="multipart/form-data" class="card-form-wide">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group<?= has_error('movement_date') ? ' has-error' : '' ?>">
                <label for="f-date">Datum <span class="required">*</span></label>
                <input id="f-date" type="date" name="movement_date" value="<?= e($val('movement_date')) ?>" required>
                <?= field_error('movement_date') ?>
            </div>
            <div class="form-group<?= has_error('employee_id') ? ' has-error' : '' ?>">
                <label for="f-employee">Mitarbeiter<?= $isCheckout ? ' <span class="required">*</span>' : '' ?></label>
                <select id="f-employee" name="employee_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected($val('employee_id'), $emp['id']) ?>><?= e($emp['display_name']) ?><?= $emp['department'] ? ' · ' . e($emp['department']) : '' ?></option><?php endforeach; ?>
                </select>
                <?= field_error('employee_id') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group<?= has_error('to_location_id') ? ' has-error' : '' ?>">
                <label for="f-location"><?= $isCheckout ? 'Neuer Standort' : 'Zurück an Standort' ?> <span class="required">*</span></label>
                <select id="f-location" name="to_location_id">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected($val('to_location_id'), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('to_location_id') ?>
            </div>
            <?php if ($isCheckout): ?>
            <div class="form-group<?= has_error('cost_center_id') ? ' has-error' : '' ?>">
                <label for="f-cc">Kostenstelle <span class="required">*</span></label>
                <select id="f-cc" name="cost_center_id">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected($val('cost_center_id'), $cc['id']) ?>><?= e($cc['number']) ?> – <?= e($cc['description']) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('cost_center_id') ?>
            </div>
            <div class="form-group<?= has_error('expected_return_at') ? ' has-error' : '' ?>">
                <label for="f-return">Rückgabe erwartet</label>
                <input id="f-return" type="date" name="expected_return_at" value="<?= e($val('expected_return_at')) ?>">
                <?= field_error('expected_return_at') ?>
            </div>
            <?php else: ?>
            <div class="form-group<?= has_error('condition_code') ? ' has-error' : '' ?>">
                <label for="f-condition">Zustand <span class="required">*</span></label>
                <select id="f-condition" name="condition_code">
                    <option value="">– bitte wählen –</option>
                    <?php foreach ($conditions as $code => $label): ?><option value="<?= e($code) ?>"<?= selected($val('condition_code'), $code) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('condition_code') ?>
            </div>
            <div class="form-group<?= has_error('target_status_code') ? ' has-error' : '' ?>">
                <label for="f-target">Zielstatus <span class="required">*</span></label>
                <select id="f-target" name="target_status_code">
                    <?php foreach ($returnTargets as $code => $label): if ($code === 'retired' && !$can('assets.retire')) { continue; } ?><option value="<?= e($code) ?>"<?= selected($val('target_status_code', 'in_stock'), $code) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('target_status_code') ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$isCheckout): ?>
        <label class="checkbox-field"><input type="checkbox" name="has_damage" value="1"<?= ($old !== null ? !empty($old['has_damage']) : (int) $row['has_damage']) ? ' checked' : '' ?> data-toggle-target="#damage-fields"> Schaden festgestellt</label>
        <div id="damage-fields" class="form-group<?= has_error('damage_description') ? ' has-error' : '' ?>">
            <label for="f-damage">Schadensbeschreibung</label>
            <textarea id="f-damage" name="damage_description" rows="2" maxlength="2000"><?= e($val('damage_description')) ?></textarea>
            <?= field_error('damage_description') ?>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="accessories_checked" value="1"<?= ($old !== null ? !empty($old['accessories_checked']) : (int) $row['accessories_checked']) ? ' checked' : '' ?>> Zubehör vollständig geprüft</label>
        <div class="form-group">
            <label for="f-acc">Zubehör-Notiz</label>
            <input id="f-acc" type="text" name="accessories_note" value="<?= e($val('accessories_note')) ?>" maxlength="500" placeholder="z. B. Netzteil fehlt">
        </div>
        <div class="form-group">
            <label for="f-photos">Fotos hinzufügen</label>
            <input id="f-photos" type="file" name="photos[]" accept="image/*" multiple>
            <span class="form-hint">JPG, PNG, WEBP oder HEIC – mehrere Dateien möglich.</span>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label for="f-note">Notiz</label>
            <textarea id="f-note" name="note" rows="2" maxlength="2000"><?= e($val('note')) ?></textarea>
        </div>
        <div class="form-actions">
            <?php if ($isOpen): ?>
                <button type="submit" name="action" value="complete" class="btn btn-primary"><?= icon('check') ?> Abschließen</button>
                <button type="submit" name="action" value="save" class="btn btn-secondary">Nur speichern</button>
            <?php else: ?>
                <button type="submit" name="action" value="save" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <?php endif; ?>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>

<dialog id="cancel-dialog" class="dialog">
    <form method="post" action="/movements/<?= (int) $row['id'] ?>/cancel">
        <?= csrf_field() ?>
        <h2>Vorgang stornieren</h2>
        <p class="text-muted">Die <?= $isCheckout ? 'Entnahme' : 'Retoure' ?> wird als storniert markiert und die Änderungen am Asset (Mitarbeiter, Standort, Kostenstelle, Status) werden zurückgesetzt. Das ist nur für den jeweils letzten Vorgang des Assets möglich.</p>
        <div class="form-group">
            <label for="c-reason">Grund <span class="required">*</span></label>
            <input id="c-reason" type="text" name="reason" maxlength="500" required placeholder="z. B. versehentlich erfasst">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger"><?= icon('x') ?> Stornieren</button>
            <button type="button" class="btn btn-ghost" data-dialog-close>Abbrechen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
