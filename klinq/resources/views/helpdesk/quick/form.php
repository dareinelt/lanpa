<?php require_once __DIR__ . '/../../partials/helpers.php'; ob_start();
/** Öffentliches Störungsformular: nur Betreff und Beschreibung, alles Weitere wird erkannt. */
$detected = [];
if (!empty($reporter['display_name'])) {
    $detected[] = ['Gemeldet von', $reporter['display_name'] . (!empty($reporter['username']) ? ' (' . $reporter['username'] . ')' : '')];
}
if (!empty($reporter['email'])) {
    $detected[] = ['E-Mail', $reporter['email']];
}
if (!empty($reporter['phone'])) {
    $detected[] = ['Telefon', $reporter['phone']];
}
if (!empty($reporter['department'])) {
    $detected[] = ['Abteilung', $reporter['department']];
}
$detected[] = ['Rechner', $reporter['host'] ?? (($reporter['ip'] ?? '') !== '' ? $reporter['ip'] : 'unbekannt')];
?>
<div class="quick-page">
    <div class="quick-card">
        <div class="quick-brand">
            <span class="brand-logo" aria-hidden="true"><?= icon('ticket') ?></span>
            <div>
                <h1 class="mb-0 text-lg">Störung melden</h1>
                <p class="text-muted text-sm mb-0"><?= e($appName ?? 'KLINQ') ?> · Help Desk</p>
            </div>
        </div>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>
        <p class="text-muted">Zwei Angaben genügen. Wer meldet und von welchem Rechner, erkennen wir automatisch.</p>
        <form method="post" action="/stoerung" novalidate>
            <?= csrf_field() ?>
            <div class="form-group<?= has_error('subject') ? ' has-error' : '' ?>">
                <label for="q-subject">Worum geht es? <span class="required">*</span></label>
                <input id="q-subject" name="subject" value="<?= e(old('subject')) ?>" maxlength="255" required autofocus placeholder="z. B. Drucker druckt nicht">
                <?= field_error('subject') ?>
            </div>
            <div class="form-group<?= has_error('description') ? ' has-error' : '' ?>">
                <label for="q-description">Was ist passiert? <span class="required">*</span></label>
                <textarea id="q-description" name="description" rows="7" required placeholder="Beschreiben Sie die Störung: Was funktioniert nicht? Seit wann? Welche Meldung erscheint?"><?= e(old('description')) ?></textarea>
                <?= field_error('description') ?>
            </div>
            <div class="quick-detected">
                <h2 class="text-sm mb-2">Automatisch erfasst</h2>
                <dl>
                    <?php foreach ($detected as [$label, $value]): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if (empty($reporter['username'])): ?>
                    <p class="text-muted text-xs mb-0">Ihr Benutzerkonto konnte nicht ermittelt werden. Bitte nennen Sie Ihren Namen in der Beschreibung, damit wir Sie erreichen.</p>
                <?php elseif (empty($reporter['directory_match'])): ?>
                    <p class="text-muted text-xs mb-0">Kontaktdaten aus dem Verzeichnisdienst konnten nicht geladen werden; wir melden uns über Ihr Benutzerkonto.</p>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block"><?= icon('check') ?> Meldung absenden</button>
        </form>
    </div>
</div>
<?php
$content = ob_get_clean();
$moduleTitle = \App\Support\ModuleNavigation::label(\App\Support\ModuleNavigation::HELPDESK);
unset($_SESSION['_old_input'], $_SESSION['_errors']);
include __DIR__ . '/../../partials/layout.php';
