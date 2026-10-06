<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/**
 * Bearbeitungs-Overlay einer Proxy-Konfiguration (neu oder bestehend) auf der
 * Uebersichtsseite. admin-mail-proxy.js oeffnet es modal; Schliessen fuehrt
 * zurueck zur Uebersicht.
 */

/** @var array<string,mixed> $dialog */
$values = $dialog['values'];
$editing = (bool) $dialog['editing'];
$editSource = null;
foreach ($overview['sources'] as $source) {
    if ((int) $source['id'] === (int) $values['identity_source_id']) {
        $editSource = $source;
    }
}
?>
<dialog id="mail-proxy-server-dialog" class="mail-proxy-overlay" aria-labelledby="mail-proxy-server-title" open data-mail-proxy-server-dialog>
    <div class="mail-proxy-overlay__header">
        <h2 id="mail-proxy-server-title"><?= Html::e((string) $dialog['title']) ?></h2>
        <a class="button button--ghost" href="<?= Html::e($base) ?>" aria-label="Schließen">✕</a>
    </div>

    <?php if (($dialog['error'] ?? null) !== null) { ?>
        <p class="flash flash--error"><?= Html::e((string) $dialog['error']) ?></p>
    <?php } ?>

    <form method="post" action="<?= Html::e((string) $dialog['action']) ?>" class="form form--wide" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) $values['id'] ?>">
        <div class="field">
            <label for="mp-source">Identitätsquelle</label>
            <?php if ($editing) { ?>
                <input id="mp-source" type="text" value="<?= Html::e($sourceName($editSource)) ?>" disabled>
                <p>Die Identitätsquelle ist nach dem Anlegen fest, damit Zuordnungen nie auf eine andere Quelle zeigen.</p>
            <?php } else { ?>
                <select id="mp-source" name="identity_source_id" required>
                    <?php foreach ($dialog['freeSources'] as $source) { ?>
                        <option value="<?= (int) $source['id'] ?>"<?= (int) $source['id'] === (int) $values['identity_source_id'] ? ' selected' : '' ?>><?= Html::e($sourceName($source) . ($source['base_dn'] !== '' ? ' – ' . $source['base_dn'] : '') . (!$source['active'] ? ' (deaktiviert)' : '')) ?></option>
                    <?php } ?>
                </select>
            <?php } ?>
        </div>
        <div class="field"><label for="mp-name">Bezeichnung</label><input id="mp-name" type="text" name="name" value="<?= Html::e((string) $values['name']) ?>" maxlength="100" required></div>
        <fieldset>
            <legend>SMTP (Versand)</legend>
            <div class="field"><label for="mp-smtp-host">SMTP-Host</label><input id="mp-smtp-host" type="text" name="smtp_host" value="<?= Html::e((string) $values['smtp_host']) ?>" maxlength="253" required placeholder="smtp.firma.local" spellcheck="false"></div>
            <div class="field"><label for="mp-smtp-port">SMTP-Port</label><select id="mp-smtp-port" name="smtp_port">
                <?php foreach ($smtpPorts as $port) { ?><option value="<?= $port ?>"<?= (int) $values['smtp_port'] === $port ? ' selected' : '' ?>><?= $port ?></option><?php } ?>
            </select></div>
            <div class="field"><label for="mp-smtp-security">Verschlüsselung</label><select id="mp-smtp-security" name="smtp_security">
                <?php foreach (['starttls' => 'STARTTLS (empfohlen, meist Port 587)', 'tls' => 'TLS direkt (meist Port 465)', 'none' => 'Keine (nur internes Relay ohne Anmeldung)'] as $value => $label) { ?><option value="<?= $value ?>"<?= $values['smtp_security'] === $value ? ' selected' : '' ?>><?= Html::e($label) ?></option><?php } ?>
            </select></div>
            <label><input type="checkbox" name="smtp_auth" value="1"<?= $values['smtp_auth'] ? ' checked' : '' ?>> SMTP-Anmeldung mit den Postfach-Zugangsdaten</label>
        </fieldset>
        <fieldset>
            <legend>IMAP (Postfachzugriff)</legend>
            <div class="field"><label for="mp-imap-host">IMAP-Host</label><input id="mp-imap-host" type="text" name="imap_host" value="<?= Html::e((string) $values['imap_host']) ?>" maxlength="253" required placeholder="imap.firma.local" spellcheck="false"></div>
            <div class="field"><label for="mp-imap-port">IMAP-Port</label><select id="mp-imap-port" name="imap_port">
                <?php foreach ($imapPorts as $port) { ?><option value="<?= $port ?>"<?= (int) $values['imap_port'] === $port ? ' selected' : '' ?>><?= $port ?></option><?php } ?>
            </select></div>
            <div class="field"><label for="mp-imap-security">Verschlüsselung</label><select id="mp-imap-security" name="imap_security">
                <?php foreach (['tls' => 'TLS direkt (meist Port 993)', 'starttls' => 'STARTTLS (meist Port 143)'] as $value => $label) { ?><option value="<?= $value ?>"<?= $values['imap_security'] === $value ? ' selected' : '' ?>><?= Html::e($label) ?></option><?php } ?>
            </select></div>
        </fieldset>
        <div class="field"><label for="mp-timeout">Zeitlimit je Verbindung (5–60 Sekunden)</label><input id="mp-timeout" name="timeout_seconds" type="number" min="5" max="60" value="<?= (int) $values['timeout_seconds'] ?>"></div>
        <label><input type="checkbox" name="verify_tls" value="1"<?= $values['verify_tls'] ? ' checked' : '' ?>> TLS-Zertifikate prüfen (dringend empfohlen; interne Zertifizierungsstellen im Container <code>mail-proxy</code> hinterlegen)</label>
        <label><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktiv</label>
        <p class="card__hint">Erlaubt sind vollqualifizierte Hostnamen oder IP-Adressen (keine Loopback-/Link-Local-Adressen, keine internen Docker-Dienstnamen) und die Standard-Mailports.</p>
        <div class="form__actions">
            <button class="button button--primary"><?= $editing ? 'Konfiguration speichern' : 'Konfiguration anlegen' ?></button>
            <a class="button button--ghost" href="<?= Html::e($base) ?>">Abbrechen</a>
        </div>
    </form>
</dialog>
