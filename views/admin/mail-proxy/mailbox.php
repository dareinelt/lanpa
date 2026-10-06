<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/**
 * Bearbeitungs-Overlay eines Postfachs (neu oder bestehend) auf der
 * Uebersichtsseite. admin-mail-proxy.js oeffnet es modal; Schliessen fuehrt
 * zurueck zur Uebersicht.
 */

/** @var array<string,mixed> $dialog */
$values = $dialog['values'];
$editing = (bool) $dialog['editing'];
$serverId = (int) ($dialog['server']['id'] ?? 0);
$sourceId = (int) ($dialog['server']['identity_source_id'] ?? 0);
?>
<dialog id="mail-proxy-mailbox-dialog" class="mail-proxy-overlay" aria-labelledby="mail-proxy-mailbox-title" open data-mail-proxy-mailbox-dialog>
    <div class="mail-proxy-overlay__header">
        <h2 id="mail-proxy-mailbox-title"><?= Html::e((string) $dialog['title']) ?></h2>
        <a class="button button--ghost" href="<?= Html::e($base) ?>" aria-label="Schließen">✕</a>
    </div>

    <?php if (($dialog['error'] ?? null) !== null) { ?>
        <p class="flash flash--error"><?= Html::e((string) $dialog['error']) ?></p>
    <?php } ?>

    <form method="post" action="<?= Html::e((string) $dialog['action']) ?>" class="form form--wide" autocomplete="off">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= (int) ($values['id'] ?? 0) ?>">
        <input type="hidden" name="server_id" value="<?= $serverId ?>">
        <input type="hidden" name="quelle" value="<?= $sourceId ?>">
        <div class="field"><label for="mp-mb-username">Benutzername (Anmeldename am Mailserver)</label><input id="mp-mb-username" type="text" name="username" value="<?= Html::e((string) $values['username']) ?>" maxlength="190" required autocomplete="off" spellcheck="false"></div>
        <div class="field"><label for="mp-mb-email">E-Mail-Adresse</label><input id="mp-mb-email" name="email_address" type="email" value="<?= Html::e((string) $values['email_address']) ?>" maxlength="254" required autocomplete="off"></div>
        <div class="field"><label for="mp-mb-name">Anzeigename (optional, Absendername)</label><input id="mp-mb-name" type="text" name="display_name" value="<?= Html::e((string) $values['display_name']) ?>" maxlength="190" autocomplete="off"></div>
        <div class="field">
            <label for="mp-mb-quota">Postfachgröße (MB, 0 = ohne Grenze)</label>
            <input id="mp-mb-quota" type="number" name="quota_mb" min="0" max="10485760" step="1" value="<?= (int) $values['quota_mb'] ?>" autocomplete="off">
            <p>Feste Grenze für die Belegungsanzeige in Orvanta. Für Proxy-Postfächer werden Exchange und AD nicht abgefragt; die Belegung liefert der IMAP-Server.</p>
        </div>
        <div class="field">
            <label for="mp-mb-password"><?= $editing ? 'Neues Passwort' : 'Passwort' ?></label>
            <input id="mp-mb-password" name="password" type="password" maxlength="4096" autocomplete="new-password"<?= $editing ? '' : ' required' ?>>
            <p><?= $editing ? 'Leer lassen, um das gespeicherte Passwort beizubehalten.' : 'Pflichtfeld.' ?> Verschlüsselte Speicherung; das Passwort wird nie angezeigt, protokolliert oder exportiert.</p>
        </div>
        <label><input type="checkbox" name="active" value="1"<?= $values['active'] ? ' checked' : '' ?>> Aktiv (inaktive Postfächer werden nie verbunden)</label>
        <div class="form__actions">
            <button class="button button--primary"><?= $editing ? 'Postfach speichern' : 'Postfach anlegen' ?></button>
            <a class="button button--ghost" href="<?= Html::e($base) ?>">Abbrechen</a>
        </div>
    </form>
</dialog>
