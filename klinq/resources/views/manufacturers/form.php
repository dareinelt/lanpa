<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start(); $isNew = $row === null; $duplicates = $duplicates ?? []; ?>
<div class="page-header">
    <div><h1><?= e($title) ?></h1></div>
    <div class="page-actions"><a class="btn btn-ghost" href="<?= e($isNew ? '/manufacturers' : '/manufacturers/' . (int) $row['id']) ?>"><?= icon('arrow-left') ?> Zurück</a></div>
</div>
<div class="card card-form">
    <?php if ($duplicates): ?>
    <div class="alert alert-warning alert-stacked" role="alert">
        <div class="flex gap-1"><?= icon('warning') ?> <strong>Mögliche Dubletten gefunden.</strong> Bitte prüfen Sie, ob der Hersteller bereits existiert.</div>
        <ul class="duplicate-hint">
            <?php foreach ($duplicates as $d): ?>
                <li><a href="/manufacturers/<?= (int) $d['id'] ?>"><?= e($d['name']) ?></a><?= $d['short_name'] ? ' (' . e($d['short_name']) . ')' : '' ?> – <?= e($d['reason']) ?><?= (int) $d['is_active'] ? '' : ' · inaktiv' ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mb-0 text-sm mt-2">Wenn es sich trotzdem um einen neuen Hersteller handelt, bestätigen Sie unten mit „Trotzdem speichern“.</p>
    </div>
    <?php endif; ?>
    <form method="post" action="<?= e($isNew ? '/manufacturers' : '/manufacturers/' . (int) $row['id']) ?>" id="manufacturer-form" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="return" value="<?= e($returnTo ?? '') ?>">
        <?php if ($duplicates): ?><input type="hidden" name="ignore_duplicates" value="1"><?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label for="f-name">Name *</label>
                <input id="f-name" name="name" value="<?= e(form_value($row, 'name')) ?>" required maxlength="150" autofocus data-duplicate-check="/api/manufacturers/check<?= $isNew ? '' : '?exclude=' . (int) $row['id'] ?>" <?= has_error('name') ? 'aria-invalid="true"' : '' ?>>
                <?= field_error('name') ?>
                <div id="duplicate-live" class="duplicate-hint" hidden></div>
            </div>
            <div class="form-group">
                <label for="f-short">Kurzname</label>
                <input id="f-short" name="short_name" value="<?= e(form_value($row, 'short_name')) ?>" maxlength="50">
                <?= field_error('short_name') ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="f-web">Webseite</label>
                <input id="f-web" name="website" value="<?= e(form_value($row, 'website')) ?>" inputmode="url" placeholder="https://…">
                <?= field_error('website') ?>
            </div>
            <div class="form-group">
                <label for="f-contact">Kontakt</label>
                <input id="f-contact" name="contact" value="<?= e(form_value($row, 'contact')) ?>" maxlength="255" placeholder="Support-Hotline, E-Mail, Ansprechpartner">
                <?= field_error('contact') ?>
            </div>
        </div>
        <div class="form-group">
            <label for="f-note">Bemerkung</label>
            <textarea id="f-note" name="note" rows="3"><?= e(form_value($row, 'note')) ?></textarea>
        </div>
        <label class="checkbox-field"><input type="checkbox" name="is_active" value="1"<?= form_checked($row, 'is_active') ?>> <span>Aktiv</span></label>
        <div class="form-actions">
            <button type="submit" class="btn <?= $duplicates ? 'btn-warning' : 'btn-primary' ?>"><?= icon('check') ?> <?= $duplicates ? 'Trotzdem speichern' : 'Speichern' ?></button>
            <a class="btn btn-ghost" href="<?= e($isNew ? '/manufacturers' : '/manufacturers/' . (int) $row['id']) ?>">Abbrechen</a>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); $scripts = ['/js/duplicate-check.js']; include __DIR__ . '/../partials/app_layout.php'; ?>
