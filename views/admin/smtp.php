<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;
?>
<p>Automatische Benachrichtigungen gehen an aktive KAEP-Mitglieder aus den zugeordneten AD-Gruppen (AD-E-Mail-Adresse) und lokale KAEP-Konten (E-Mail unter Benutzer). Doppelte Adressen werden zusammengefasst. Administratoren sind nicht automatisch Empfänger.</p>
<form action="/admin/smtp" method="post" class="form form--wide">
    <?= Csrf::field() ?>
    <label><input type="checkbox" name="smtp_enabled" value="1" <?= $config['smtp_enabled'] === '1' ? 'checked' : '' ?>> SMTP-Versand aktivieren</label>
    <?php foreach (['smtp_host' => 'SMTP-Host', 'smtp_port' => 'Port', 'smtp_from' => 'Absenderadresse', 'smtp_name' => 'Absendername', 'smtp_username' => 'Benutzername (leer = keine Anmeldung)', 'smtp_timeout' => 'Zeitlimit pro Schritt (1–30 Sekunden)'] as $key => $label) { ?>
        <div class="field"><label for="<?= $key ?>"><?= Html::e($label) ?></label><input id="<?= $key ?>" name="<?= $key ?>" value="<?= Html::e($config[$key]) ?>" <?= $key === 'smtp_from' ? 'type="email"' : '' ?> maxlength="254"></div>
    <?php } ?>
    <div class="field"><label for="smtp-security">Transportverschlüsselung</label><select id="smtp-security" name="smtp_security">
        <?php foreach (['starttls' => 'STARTTLS (empfohlen, meist Port 587)', 'tls' => 'TLS direkt (meist Port 465)', 'none' => 'Keine (nur internes Relay ohne Anmeldung)'] as $value => $label) { ?><option value="<?= $value ?>" <?= $config['smtp_security'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php } ?>
    </select></div>
    <div class="field"><label for="smtp-password">Neues SMTP-Passwort</label><input id="smtp-password" name="smtp_password" type="password" autocomplete="new-password" maxlength="4096"><p><?= $hasPassword ? 'Passwort gespeichert. Leer lassen zum Beibehalten.' : 'Noch kein Passwort gespeichert.' ?> Verschlüsselte Speicherung; keine Ausgabe oder Protokollierung.</p></div>
    <label><input type="checkbox" name="smtp_clear_password" value="1">Gespeichertes Passwort löschen (nur ohne Anmeldung)</label>
    <p>Zertifikate werden immer geprüft. Interne Zertifizierungsstellen müssen im Vertrauensspeicher des App-/Mail-Containers hinterlegt sein.</p>
    <button class="button button--primary">SMTP speichern</button>
</form>
<h2>Testversand</h2>
<form action="/admin/smtp/test" method="post" class="form">
    <?= Csrf::field() ?><label>Testempfänger <input name="recipient" type="email" required maxlength="254"></label><button class="button button--ghost">Testnachricht einplanen</button>
</form>
<h2>Letzte 50 Nachrichten</h2>
<p>Der Dienst <code>mail</code> versendet automatisch, mit maximal drei Versuchen. „sent“ bedeutet SMTP-Annahme, nicht Zustellung oder Lesen. Dauerhaft „queued“: Mail-Dienst prüfen. Bei unklarer Annahme sind Doppelzustellungen möglich.</p>
<a href="/admin/smtp">Versandstatus aktualisieren</a>
<div class="table-wrapper"><table class="table"><thead><tr><th>Zeit (UTC)</th><th>Empfänger</th><th>Ereignis</th><th>Status</th><th>Versuche</th><th>Ergebnis</th></tr></thead><tbody>
<?php foreach ($messages as $message) { ?><tr><td><?= Html::e($message['created_at']) ?></td><td><?= Html::e($message['recipient']) ?></td><td><?= $message['event_id'] === null ? 'Test' : '#' . (int) $message['event_id'] ?></td><td><?= Html::e($message['status']) ?></td><td><?= (int) $message['attempts'] ?>/3</td><td><?= Html::e($message['message']) ?></td></tr><?php } ?>
</tbody></table></div>
