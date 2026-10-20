<?php /** Filterfelder für Bewegungen. Erwartet: $filters, $employees, $locationOptions, $costCenters, $types; optional $withType, $withStatus, $withMissing */ ?>
<?php if ($withType ?? true): ?>
<div class="form-group">
    <label for="filter-type">Vorgang</label>
    <select id="filter-type" name="type" data-autosubmit>
        <option value="">Alle</option>
        <option value="checkout"<?= selected($filters['type'], 'checkout') ?>>Entnahmen</option>
        <option value="return"<?= selected($filters['type'], 'return') ?>>Retouren</option>
    </select>
</div>
<?php endif; ?>
<div class="form-group">
    <label for="filter-employee">Mitarbeiter</label>
    <select id="filter-employee" name="employee_id" data-autosubmit>
        <option value="">Alle</option>
        <?php foreach ($employees as $emp): ?><option value="<?= (int) $emp['id'] ?>"<?= selected($filters['employee_id'], $emp['id']) ?>><?= e($emp['display_name']) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="form-group">
    <label for="filter-location">Standort</label>
    <select id="filter-location" name="location_id" data-autosubmit>
        <option value="">Alle</option>
        <?php foreach ($locationOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected($filters['location_id'], $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="form-group">
    <label for="filter-cc">Kostenstelle</label>
    <select id="filter-cc" name="cost_center_id" data-autosubmit>
        <option value="">Alle</option>
        <?php foreach ($costCenters as $cc): ?><option value="<?= (int) $cc['id'] ?>"<?= selected($filters['cost_center_id'], $cc['id']) ?>><?= e($cc['number']) ?> – <?= e($cc['description']) ?></option><?php endforeach; ?>
    </select>
</div>
<div class="form-group">
    <label for="filter-asset-type">Assettyp</label>
    <select id="filter-asset-type" name="asset_type_id" data-autosubmit>
        <option value="">Alle</option>
        <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($filters['asset_type_id'], $t['id']) ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select>
</div>
<?php if ($withMissing ?? false): ?>
<div class="form-group">
    <label for="filter-missing">Fehlende Angabe</label>
    <select id="filter-missing" name="missing" data-autosubmit>
        <option value="">Alle</option>
        <?php foreach (App\Services\MovementService::MISSING_LABELS as $k => $label): ?><option value="<?= e($k) ?>"<?= selected($filters['missing'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
<?php if ($withStatus ?? false): ?>
<div class="form-group">
    <label for="filter-status">Status</label>
    <select id="filter-status" name="status" data-autosubmit>
        <option value="">Offen + abgeschlossen</option>
        <option value="open"<?= selected($filters['status'], 'open') ?>>Offen</option>
        <option value="completed"<?= selected($filters['status'], 'completed') ?>>Abgeschlossen</option>
        <option value="cancelled"<?= selected($filters['status'], 'cancelled') ?>>Storniert</option>
        <option value="all"<?= selected($filters['status'], 'all') ?>>Alle</option>
    </select>
</div>
<?php endif; ?>
