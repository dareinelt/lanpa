<?php require_once __DIR__ . '/../partials/helpers.php'; ob_start();
$old = $_SESSION['_old_input'] ?? null;
$val = static fn (string $key): string => (string) ($old[$key] ?? $settings[$key] ?? '');
$actionLabel = ['created' => 'Ticket angelegt', 'comment' => 'Kommentar', 'ignored' => 'Ignoriert', 'failed' => 'Fehlgeschlagen'];
$actionColor = ['created' => 'success', 'comment' => 'info', 'ignored' => 'neutral', 'failed' => 'danger'];
?>
<div class="page-header">
    <div>
        <p class="page-subtitle text-muted mb-0"><a href="/admin">Administration</a></p>
        <h1 class="page-title">E-Mail-Postfach (Help Desk)</h1>
        <p class="text-muted mb-0">Eingehende E-Mails erzeugen ein neues Ticket. Steht am Anfang des Betreffs eine bekannte
            Ticketnummer (auch nach <code>Re:</code>, <code>AW:</code> oder <code>Fwd:</code>), wird die Nachricht stattdessen als
            Kommentar an dieses Ticket gehängt.</p>
    </div>
    <div class="page-actions">
        <form method="post" action="/admin/helpdesk-mail/test" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary"><?= icon('link') ?> Verbindung testen</button></form>
        <form method="post" action="/admin/helpdesk-mail/run" class="inline-form"><?= csrf_field() ?><button type="submit" class="btn btn-secondary"<?= $settings['enabled'] ? '' : ' disabled' ?>><?= icon('refresh') ?> Jetzt abholen</button></form>
    </div>
</div>

<?php if (!$helpdeskEnabled): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning', 'icon icon-xs') ?> Das Help-Desk-Modul ist deaktiviert (<code>HELPDESK_ENABLED=false</code>); der E-Mail-Eingang läuft erst, wenn es aktiv ist.</div>
<?php endif; ?>
<?php if (!empty($settings['password_unreadable'])): ?>
    <div class="alert alert-warning mb-4"><?= icon('warning', 'icon icon-xs') ?> Das gespeicherte Postfachpasswort kann nicht entschlüsselt werden (<code>APP_KEY</code> wurde geändert). Bitte das Passwort erneut eingeben.</div>
<?php endif; ?>

<div class="grid grid-4 mb-4">
    <div class="card kpi-card"><span class="kpi-value"><?= $settings['enabled'] ? 'Aktiv' : 'Inaktiv' ?></span><span class="kpi-label">E-Mail-Eingang</span></div>
    <div class="card kpi-card"><span class="kpi-value"><?= (int) $settings['interval_minutes'] > 0 ? (int) $settings['interval_minutes'] . ' min' : 'manuell' ?></span><span class="kpi-label">Abrufintervall</span></div>
    <div class="card kpi-card"><span class="kpi-value text-sm"><?= e($lastRun !== null ? fmt_datetime($lastRun) : '–') ?></span><span class="kpi-label">Letzter Abruf</span></div>
    <div class="card kpi-card">
        <span class="kpi-value text-sm">
            <?php if ($lastResult === null): ?>–
            <?php elseif (isset($lastResult['error'])): ?><span class="text-danger">Fehler</span>
            <?php else: ?><?= (int) ($lastResult['fetched'] ?? 0) ?> / <?= (int) ($lastResult['created'] ?? 0) ?> / <?= (int) ($lastResult['comments'] ?? 0) ?><?php endif; ?>
        </span>
        <span class="kpi-label">Abgeholt / Tickets / Kommentare</span>
    </div>
</div>
<?php if ($lastResult !== null && isset($lastResult['error'])): ?>
    <div class="alert alert-danger mb-4"><?= icon('warning', 'icon icon-xs') ?> Letzter Abruf fehlgeschlagen: <?= e((string) $lastResult['error']) ?></div>
<?php endif; ?>

<form method="post" action="/admin/helpdesk-mail" class="card" novalidate>
    <?= csrf_field() ?>

    <h3 class="text-sm text-muted">Betrieb</h3>
    <div class="form-group">
        <label class="checkbox-field"><input type="checkbox" name="enabled" value="1"<?= form_checked($settings, 'enabled', false) ?>> <span>E-Mail-Eingang aktiv</span></label>
        <span class="form-hint">Ist der Eingang aktiv, holt der Scheduler das Postfach im eingestellten Intervall ab.</span>
    </div>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('interval_minutes') ? 'has-error' : '' ?>">
            <label for="hm-interval">Abrufintervall (Minuten)</label>
            <input id="hm-interval" name="interval_minutes" type="number" min="0" max="1440" required value="<?= e($val('interval_minutes')) ?>">
            <span class="form-hint">0 = nur manuell bzw. per <code>php bin/helpdesk.php mail</code>.</span>
            <?= field_error('interval_minutes') ?>
        </div>
        <div class="form-group <?= has_error('batch_size') ? 'has-error' : '' ?>">
            <label for="hm-batch">Nachrichten je Lauf</label>
            <input id="hm-batch" name="batch_size" type="number" min="1" max="500" required value="<?= e($val('batch_size')) ?>">
            <?= field_error('batch_size') ?>
        </div>
        <div class="form-group <?= has_error('timeout') ? 'has-error' : '' ?>">
            <label for="hm-timeout">Zeitüberschreitung (Sekunden)</label>
            <input id="hm-timeout" name="timeout" type="number" min="3" max="120" required value="<?= e($val('timeout')) ?>">
            <?= field_error('timeout') ?>
        </div>
    </div>

    <h3 class="text-sm text-muted">Postfach</h3>
    <div class="form-row">
        <div class="form-group <?= has_error('driver') ? 'has-error' : '' ?>">
            <label for="hm-driver">Postfachtyp</label>
            <select id="hm-driver" name="driver">
                <?php foreach ($drivers as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($val('driver'), $key) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <span class="form-hint">„Verzeichnis mit .eml-Dateien“ dient Tests und Sonderfällen (z. B. Übergabe durch einen Mailserver).</span>
            <?= field_error('driver') ?>
        </div>
        <div class="form-group <?= has_error('file_path') ? 'has-error' : '' ?>">
            <label for="hm-file">Verzeichnis (nur bei .eml-Dateien)</label>
            <input id="hm-file" name="file_path" type="text" maxlength="255" value="<?= e($val('file_path')) ?>" placeholder="storage/mail-inbox">
            <?= field_error('file_path') ?>
        </div>
    </div>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('host') ? 'has-error' : '' ?>">
            <label for="hm-host">IMAP-Server</label>
            <input id="hm-host" name="host" type="text" maxlength="190" value="<?= e($val('host')) ?>" placeholder="imap.firma.local">
            <?= field_error('host') ?>
        </div>
        <div class="form-group <?= has_error('port') ? 'has-error' : '' ?>">
            <label for="hm-port">Port</label>
            <input id="hm-port" name="port" type="number" min="1" max="65535" value="<?= e($val('port')) ?>">
            <?= field_error('port') ?>
        </div>
        <div class="form-group <?= has_error('encryption') ? 'has-error' : '' ?>">
            <label for="hm-encryption">Verschlüsselung</label>
            <select id="hm-encryption" name="encryption">
                <?php foreach ($encryptions as $key => $label): ?><option value="<?= e($key) ?>"<?= selected($val('encryption'), $key) ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('encryption') ?>
        </div>
    </div>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('username') ? 'has-error' : '' ?>">
            <label for="hm-user">Benutzername</label>
            <input id="hm-user" name="username" type="text" maxlength="190" autocomplete="off" value="<?= e($val('username')) ?>" placeholder="helpdesk@firma.de">
            <?= field_error('username') ?>
        </div>
        <div class="form-group <?= has_error('password') ? 'has-error' : '' ?>">
            <label for="hm-password">Passwort</label>
            <input id="hm-password" name="password" type="password" maxlength="190" autocomplete="new-password" placeholder="<?= !empty($settings['password_set']) ? '•••••••• (gespeichert)' : 'Passwort eingeben' ?>">
            <span class="form-hint">Leer lassen, um das gespeicherte Passwort zu behalten. Es wird verschlüsselt abgelegt und nie angezeigt.</span>
            <?php if (!empty($settings['password_set'])): ?>
                <label class="checkbox-field"><input type="checkbox" name="remove_password" value="1"> <span>Gespeichertes Passwort löschen</span></label>
            <?php endif; ?>
            <?= field_error('password') ?>
        </div>
        <div class="form-group">
            <label class="checkbox-field"><input type="checkbox" name="verify_peer" value="1"<?= form_checked($settings, 'verify_peer', true) ?>> <span>Zertifikat prüfen</span></label>
            <span class="form-hint">Nur für interne Server mit selbstsigniertem Zertifikat abschalten.</span>
        </div>
    </div>

    <h3 class="text-sm text-muted">Verzeichnisse</h3>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('mailbox') ? 'has-error' : '' ?>">
            <label for="hm-mailbox">Abzufragendes Verzeichnis</label>
            <input id="hm-mailbox" name="mailbox" type="text" maxlength="190" list="hm-folders" value="<?= e($val('mailbox')) ?>" placeholder="INBOX">
            <?= field_error('mailbox') ?>
        </div>
        <div class="form-group">
            <label class="checkbox-field"><input type="checkbox" name="move_processed" value="1"<?= form_checked($settings, 'move_processed', false) ?>> <span>Abgearbeitete E-Mails verschieben</span></label>
            <span class="form-hint">Ohne Haken bleiben verarbeitete Nachrichten im Eingang und werden nur als gelesen markiert.</span>
        </div>
        <div class="form-group <?= has_error('processed_mailbox') ? 'has-error' : '' ?>">
            <label for="hm-processed">Zielverzeichnis für abgearbeitete E-Mails</label>
            <input id="hm-processed" name="processed_mailbox" type="text" maxlength="190" list="hm-folders" value="<?= e($val('processed_mailbox')) ?>" placeholder="INBOX/Verarbeitet">
            <span class="form-hint">Das Verzeichnis muss im Postfach existieren. „Verbindung testen“ liest die vorhandenen Verzeichnisse ein.</span>
            <?= field_error('processed_mailbox') ?>
        </div>
    </div>
    <datalist id="hm-folders">
        <?php foreach ($folders as $folder): ?><option value="<?= e($folder) ?>"></option><?php endforeach; ?>
    </datalist>

    <h3 class="text-sm text-muted">Ticketerstellung</h3>
    <div class="form-row form-row-3">
        <div class="form-group <?= has_error('system_user') ? 'has-error' : '' ?>">
            <label for="hm-system-user">Systembenutzer</label>
            <select id="hm-system-user" name="system_user">
                <?php foreach ($systemUsers as $u): ?><option value="<?= e($u['username']) ?>"<?= selected($val('system_user'), $u['username']) ?>><?= e($u['display_name'] ?? $u['username']) ?> (<?= e($u['username']) ?>)</option><?php endforeach; ?>
            </select>
            <span class="form-hint">Konto, unter dem Tickets und Kommentare aus E-Mails angelegt werden (benötigt <code>helpdesk.create</code>).</span>
            <?= field_error('system_user') ?>
        </div>
        <div class="form-group <?= has_error('default_type') ? 'has-error' : '' ?>">
            <label for="hm-type">Standard-Tickettyp</label>
            <select id="hm-type" name="default_type">
                <?php foreach ($ticketTypes as $type): ?><option value="<?= e($type['code']) ?>"<?= selected($val('default_type'), $type['code']) ?>><?= e($type['name']) ?></option><?php endforeach; ?>
            </select>
            <?= field_error('default_type') ?>
        </div>
        <div class="form-group">
            <label class="checkbox-field"><input type="checkbox" name="allow_unknown_senders" value="1"<?= form_checked($settings, 'allow_unknown_senders', true) ?>> <span>Unbekannte Absender zulassen</span></label>
            <span class="form-hint">Ohne Haken werden nur E-Mails von bekannten Mitarbeitern oder Benutzern zu Tickets.</span>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= icon('check') ?> Speichern</button>
        <a class="btn btn-ghost" href="/admin">Abbrechen</a>
    </div>
</form>

<div class="card card-flush mt-4">
    <div class="card-header">
        <h2>Zuletzt verarbeitete Nachrichten</h2>
        <a class="text-sm" href="/helpdesk/admin/mail">Vollständiges Protokoll</a>
    </div>
    <?php if ($recent === []): ?>
        <div class="table-empty">Noch keine Nachrichten verarbeitet.</div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table table-compact">
            <thead><tr><th>Verarbeitet</th><th>Absender</th><th>Betreff</th><th>Aktion</th><th>Ticket</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $r): ?>
                <tr>
                    <td class="nowrap text-sm"><?= fmt_datetime($r['created_at']) ?></td>
                    <td class="text-sm"><?= e($r['from_address'] !== '' ? $r['from_address'] : '–') ?></td>
                    <td class="text-sm"><?= e($r['subject'] !== '' ? $r['subject'] : '(ohne Betreff)') ?></td>
                    <td><?= badge($actionLabel[$r['action']] ?? $r['action'], $actionColor[$r['action']] ?? 'neutral') ?></td>
                    <td class="mono text-sm"><?php if (!empty($r['ticket_id']) && !empty($r['ticket_number'])): ?><a href="/helpdesk/tickets/<?= (int) $r['ticket_id'] ?>"><?= e($r['ticket_number']) ?></a><?php else: ?>–<?php endif; ?></td>
                    <td class="text-xs text-muted"><?= e($r['detail'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php $innerContent = ob_get_clean(); include __DIR__ . '/../partials/app_layout.php'; ?>
