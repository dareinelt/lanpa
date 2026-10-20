<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? null;
$val = static fn (string $key): string => (string) ($old[$key] ?? $layout[$key] ?? '');
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/admin">Administration</a></p>
        <h1 class="page-title">Etikettenlayout</h1>
        <p class="text-muted mb-0">Inhalt und Maße der Inventaretiketten. Änderungen wirken sofort auf alle künftigen Ausdrucke.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="/labels?dummy=1&amp;autoprint=1"><?= icon('print') ?> Testdruck</a>
    </div>
</div>

<div class="grid label-settings-grid">
    <form method="post" action="/admin/labels" enctype="multipart/form-data" class="card" id="label-settings-form" novalidate>
        <?= csrf_field() ?>
        <h3 class="text-sm text-muted">Inhalt</h3>
        <div class="form-group <?= has_error('company_name') ?>">
            <label for="ls-company">Firmenname <span class="required">*</span></label>
            <input id="ls-company" name="company_name" type="text" maxlength="80" required value="<?= e($val('company_name')) ?>">
            <?= field_error('company_name') ?>
        </div>
        <div class="form-row">
            <div class="form-group <?= has_error('extra_field') ?>">
                <label for="ls-extra">Zusatzfeld</label>
                <select id="ls-extra" name="extra_field">
                    <?php foreach ($extraFields as $key => $label): ?><option value="<?= e($key) ?>" <?= selected($val('extra_field'), $key) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('extra_field') ?>
            </div>
            <div class="form-group <?= has_error('extra_text') ?>">
                <label for="ls-extra-text">Fester Text</label>
                <input id="ls-extra-text" name="extra_text" type="text" maxlength="80" value="<?= e($val('extra_text')) ?>" placeholder="z. B. Eigentum der Firma – bitte zurückgeben">
                <span class="form-hint">Nur bei Zusatzfeld „Fester Text“.</span>
                <?= field_error('extra_text') ?>
            </div>
        </div>

        <h3 class="text-sm text-muted">Logo</h3>
        <div class="form-row">
            <div class="form-group">
                <label class="checkbox-field"><input type="checkbox" name="show_logo" value="1"<?= form_checked(['show_logo' => $layout['show_logo']], 'show_logo', false) ?>> <span>Logo auf dem Etikett anzeigen</span></label>
                <?php if ($hasLogo): ?>
                    <div class="label-logo-current"><img src="/labels/logo" alt="Aktuelles Logo"></div>
                    <label class="checkbox-field"><input type="checkbox" name="remove_logo" value="1"> <span>Aktuelles Logo entfernen</span></label>
                <?php else: ?>
                    <span class="form-hint">Noch kein Logo hinterlegt.</span>
                <?php endif; ?>
            </div>
            <div class="form-group <?= has_error('logo') ?>">
                <label for="ls-logo">Logo hochladen</label>
                <input id="ls-logo" name="logo" type="file" accept="image/png,image/jpeg,image/svg+xml,image/webp">
                <span class="form-hint">PNG, JPEG, SVG oder WebP, max. 512 KB. Monochrome Logos drucken auf Etikettendruckern am besten.</span>
                <?= field_error('logo') ?>
            </div>
        </div>

        <h3 class="text-sm text-muted">Maße &amp; Positionen (mm)</h3>
        <div class="form-row form-row-3">
            <div class="form-group <?= has_error('width_mm') ?>"><label for="ls-w">Breite</label><input id="ls-w" name="width_mm" type="number" min="20" max="150" required value="<?= e($val('width_mm')) ?>"><?= field_error('width_mm') ?></div>
            <div class="form-group <?= has_error('height_mm') ?>"><label for="ls-h">Höhe</label><input id="ls-h" name="height_mm" type="number" min="15" max="100" required value="<?= e($val('height_mm')) ?>"><?= field_error('height_mm') ?></div>
            <div class="form-group <?= has_error('padding_mm') ?>"><label for="ls-p">Innenabstand</label><input id="ls-p" name="padding_mm" type="number" min="0" max="10" required value="<?= e($val('padding_mm')) ?>"><?= field_error('padding_mm') ?></div>
        </div>
        <div class="form-row">
            <div class="form-group <?= has_error('qr_size_mm') ?>"><label for="ls-qr">QR-Code Größe</label><input id="ls-qr" name="qr_size_mm" type="number" min="8" max="60" required value="<?= e($val('qr_size_mm')) ?>"><?= field_error('qr_size_mm') ?></div>
            <div class="form-group <?= has_error('qr_position') ?>">
                <label for="ls-qrpos">QR-Code Position</label>
                <select id="ls-qrpos" name="qr_position">
                    <?php foreach ($qrPositions as $key => $label): ?><option value="<?= e($key) ?>" <?= selected($val('qr_position'), $key) ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
                <?= field_error('qr_position') ?>
            </div>
        </div>

        <h3 class="text-sm text-muted">Schriftgrößen (pt)</h3>
        <div class="form-row form-row-3">
            <div class="form-group <?= has_error('font_size_company') ?>"><label for="ls-fs-c">Firmenname</label><input id="ls-fs-c" name="font_size_company" type="number" min="4" max="20" required value="<?= e($val('font_size_company')) ?>"><?= field_error('font_size_company') ?></div>
            <div class="form-group <?= has_error('font_size_inventory') ?>"><label for="ls-fs-i">Inventarnummer</label><input id="ls-fs-i" name="font_size_inventory" type="number" min="6" max="30" required value="<?= e($val('font_size_inventory')) ?>"><?= field_error('font_size_inventory') ?></div>
            <div class="form-group <?= has_error('font_size_extra') ?>"><label for="ls-fs-e">Zusatzfeld</label><input id="ls-fs-e" name="font_size_extra" type="number" min="4" max="20" required value="<?= e($val('font_size_extra')) ?>"><?= field_error('font_size_extra') ?></div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
            <a class="btn btn-ghost" href="/admin">Abbrechen</a>
        </div>
    </form>

    <div class="card label-preview-card">
        <div class="card-header"><h2>Vorschau</h2><span class="text-muted" id="preview-dimensions"><?= e($layout['width_mm']) ?> × <?= e($layout['height_mm']) ?> mm</span></div>
        <p class="form-hint">Live-Vorschau in Originalgröße (Bildschirmdarstellung kann geringfügig abweichen). QR-Code verweist auf <code><?= e($sample['url']) ?></code>.</p>
        <div class="label-preview" id="label-preview">
            <?php $label = $sample; include __DIR__ . '/../partials/label.php'; ?>
        </div>
    </div>
</div>
<?php
$innerContent = ob_get_clean();
$scripts = ['/js/labels.js'];
$extraStyles = ['/labels/style.css'];
include __DIR__ . '/../partials/app_layout.php';
