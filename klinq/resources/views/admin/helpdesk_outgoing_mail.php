<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? null;
$val = static fn (string $key): string => (string) ($old[$key] ?? $settings[$key] ?? '');
$replyTo = $val('reply_to') !== '' ? $val('reply_to') : $val('from_address');
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/admin">Administration</a></p>
        <h1 class="page-title">E-Mail-Versand (Help Desk)</h1>
        <p class="text-muted mb-0">Konto samt Servereinstellungen, von dem aus der Help Desk E-Mails versendet
            (Ticketantworten, Benachrichtigungen, Testnachrichten). Ohne aktives Konto läuft der Versand wie bisher
            über den Mail-Container.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="/admin/helpdesk-outgoing-mail/test" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary"><?= icon('link') ?> Verbindung testen</button></form>
    </div>
</div>

<?php if (!empty($settings['password_unreadable'])): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning', 'icon icon-xs') ?> Das gespeicherte Passwort kann nicht entschlüsselt werden (<code>APP_KEY</code> wurde geändert). Bitte das Passwort erneut eingeben.</div>
<?php endif; ?>
<?php if (!$usesAccount && $containerUrl !== ''): ?>
    <div class="alert alert-info mb-4"><?= icon('info', 'icon icon-xs') ?> Es ist kein eigenes Versandkonto aktiv. Der Help Desk versendet derzeit über den Mail-Container (<code><?= e($containerUrl) ?></code>).</div>
<?php elseif (!$usesAccount): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning', 'icon icon-xs') ?> Es ist kein Versandkonto aktiv und auch kein Mail-Container konfiguriert (<code>MAIL_SERVICE_URL</code>). Ausgehende Help-Desk-E-Mails werden nicht zugestellt.</div>
<?php endif; ?>

<div class="grid grid-4 mb-4">
    <div class="card kpi-card"><span class="kpi-value"><?= $usesAccount ? 'Aktiv' : 'Inaktiv' ?></span><span class="kpi-label">Versandkonto</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm"><?= e($val('from_address') !== '' ? $val('from_address') : '–') ?></span><span class="kpi-label">Absenderadresse</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm"><?= e($replyTo !== '' ? $replyTo : '–') ?></span><span class="kpi-label">Antwort-an</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm"><?= e($val('host') !== '' ? $val('host') . ':' . $val('port') : '–') ?></span><span class="kpi-label">Server</span></div>
</div>

<form method="post" action="/admin/helpdesk-outgoing-mail" class="card" novalidate>
    <?= csrf_field() ?>

    <h3 class="text-sm text-muted">Betrieb</h3>
    <div class="form-group">
        <label class="checkbox-field"><input type="checkbox" name="enabled" value="1"<?= form_checked($settings, 'enabled', false) ?>> <span>Über dieses Konto versenden</span></label>
        <span class="form-hint">Ist das Konto aktiv, nutzen alle Help-Desk-E-Mails diese Servereinstellungen. Ohne Haken bleibt der Mail-Container zuständig.</span>
    </div>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('timeout') ? 'has-error' : '' ?>">
            <label for="ho-timeout">Zeitüberschreitung (Sekunden)</label>
            <input id="ho-timeout" name="timeout" type="number" min="3" max="120" required value="<?= e($val('timeout')) ?>">
            <?= field_error('timeout') ?>
        </div>
        <div class="form-group">
            <label class="checkbox-field"><input type="checkbox" name="verify_peer" value="1"<?= form_checked($settings, 'verify_peer', true) ?>> <span>Zertifikat prüfen</span></label>
            <span class="form-hint">Nur für interne Server mit selbstsigniertem Zertifikat abschalten.</span>
        </div>
    </div>

    <h3 class="text-sm text-muted">Server</h3>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('host') ? 'has-error' : '' ?>">
            <label for="ho-host">SMTP-Server</label>
            <input id="ho-host" name="host" type="text" maxlength="190" value="<?= e($val('host')) ?>" placeholder="smtp.firma.local">
            <?= field_error('host') ?>
        </div>
        <div class="form-group <?= has_error('port') ? 'has-error' : '' ?>">
            <label for="ho-port">Port</label>
            <input id="ho-port" name="port" type="number" min="1" max="65535" required value="<?= e($val('port')) ?>">
            <span class="form-hint">Üblich: 465 bei SSL/TLS, 587 bei STARTTLS.</span>
            <?= field_error('port') ?>
        </div>
        <div class="form-group <?= has_error('encryption') ? 'has-error' : '' ?>">
            <label for="ho-encryption">Verschlüsselung</label>
            <select id="ho-encryption" name="encryption">
                <?php foreach ($encryptions as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($val('encryption'), $key) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('encryption') ?>
        </div>
    </div>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('username') ? 'has-error' : '' ?>">
            <label for="ho-user">Benutzername</label>
            <input id="ho-user" name="username" type="text" maxlength="190" autocomplete="off" value="<?= e($val('username')) ?>" placeholder="helpdesk@firma.de">
            <span class="form-hint">Leer lassen, wenn das Relay ohne Anmeldung auskommt (nur im internen Netz).</span>
            <?= field_error('username') ?>
        </div>
        <div class="form-group <?= has_error('password') ? 'has-error' : '' ?>">
            <label for="ho-password">Passwort</label>
            <input id="ho-password" name="password" type="password" maxlength="190" autocomplete="new-password" placeholder="<?= !empty($settings['password_set']) ? '•••••••• (gespeichert)' : 'Passwort eingeben' ?>">
            <span class="form-hint">Leer lassen, um das gespeicherte Passwort zu behalten. Es wird verschlüsselt abgelegt und nie angezeigt.</span>
            <?php if (!empty($settings['password_set'])): ?>
                <label class="checkbox-field"><input type="checkbox" name="remove_password" value="1"> <span>Gespeichertes Passwort löschen</span></label>
            <?php endif; ?>
            <?= field_error('password') ?>
        </div>
    </div>

    <h3 class="text-sm text-muted">Absender</h3>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('from_address') ? 'has-error' : '' ?>">
            <label for="ho-from">Absenderadresse</label>
            <input id="ho-from" name="from_address" type="email" maxlength="255" value="<?= e($val('from_address')) ?>" placeholder="helpdesk@firma.de">
            <span class="form-hint">Adresse, die Empfänger als Absender sehen (muss der Server zulassen).</span>
            <?= field_error('from_address') ?>
        </div>
        <div class="form-group <?= has_error('from_name') ? 'has-error' : '' ?>">
            <label for="ho-from-name">Absendername</label>
            <input id="ho-from-name" name="from_name" type="text" maxlength="190" value="<?= e($val('from_name')) ?>" placeholder="Help Desk">
            <?= field_error('from_name') ?>
        </div>
        <div class="form-group <?= has_error('reply_to') ? 'has-error' : '' ?>">
            <label for="ho-reply-to">Antwort-an (Reply-To)</label>
            <input id="ho-reply-to" name="reply_to" type="email" maxlength="255" value="<?= e($val('reply_to')) ?>" placeholder="z. B. itsupport@firma.de">
            <span class="form-hint">Adresse, an die Antworten gehen sollen. Leer lassen, um Antworten an die Absenderadresse zu schicken.</span>
            <?= field_error('reply_to') ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
        <a class="btn btn-ghost" href="/admin">Abbrechen</a>
    </div>
</form>

<div class="card mt-4">
    <h3 class="text-sm text-muted">Testnachricht</h3>
    <form method="post" action="/admin/helpdesk-outgoing-mail/send-test" class="form-row" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="ho-test-to">Empfänger</label>
            <input id="ho-test-to" name="test_recipient" type="email" maxlength="255" value="<?= e($val('from_address')) ?>" placeholder="empfaenger@firma.de">
            <span class="form-hint">Verwendet die gespeicherten Einstellungen – bitte vorher speichern.</span>
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <button type="submit" class="btn btn-secondary"><?= icon('mail') ?> Test-E-Mail senden</button>
        </div>
    </form>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
