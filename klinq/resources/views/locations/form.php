<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $back = $isNew ? '/locations' : '/locations/' . (int) $row['id']; ?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($back) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form card-form-narrow">
    <form method="post" action="<?= e($isNew ? '/locations' : '/locations/' . (int) $row['id']) ?>" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo ?? '') ?>">
        <div class="form-group">
            <label for="f-parent">Übergeordneter Standort</label>
            <select id="f-parent" name="parent_id">
                <option value="">— Oberste Ebene —</option>
                <?php foreach ($parentOptions as $l): ?><option value="<?= (int) $l['id'] ?>"<?= selected(form_value($row, 'parent_id', $presetParent ?? ''), $l['id']) ?>><?= str_repeat('  ', (int) $l['depth']) ?><?= e($l['name']) ?> (<?= e($types[$l['type']] ?? $l['type']) ?>)</option><?php endforeach; ?>
            </select>
            <?= field_error('parent_id') ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Name *</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="150" autofocus placeholder="z. B. Gebäude A, 2. OG, Raum 2.14">
                <?= field_error('name') ?>
            </div>
            <div class="form-group">
                <label for="f-type">Typ *</label>
                <select id="f-type" name="type" required>
                    <?php foreach ($types as $key => $label): ?><option value="<?= e($key) ?>"<?= selected(form_value($row, 'type', 'room'), $key) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('type') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-code">Kürzel</label>
                <input id="f-code" name="code" value="<?= e(form_value($row, 'code')) ?>" maxlength="50" placeholder="optional, z. B. PE-A-2.14">
                <?= field_error('code') ?>
            </div>
            <div class="form-group">
                <label for="f-sort">Sortierung</label>
                <input id="f-sort" name="sort_order" type="number" value="<?= e(form_value($row, 'sort_order', '0')) ?>" min="-9999" max="9999">
                <?= field_error('sort_order') ?>
            </div>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="<?= e($back) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
