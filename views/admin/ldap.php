<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,string> $attributeKeys */
/** @var bool $hasBindPassword */
/** @var bool $ldapExtensionAvailable */
/** @var list<array<string,mixed>> $syncRuns */
$attributeLabels = [
    'ldap_attr_display_name' => 'Anzeigename',
    'ldap_attr_first_name' => 'Vorname',
    'ldap_attr_last_name' => 'Nachname',
    'ldap_attr_phone' => 'Telefon',
    'ldap_attr_mobile' => 'Mobil',
    'ldap_attr_email' => 'E-Mail',
    'ldap_attr_department' => 'Abteilung',
    'ldap_attr_modified' => 'Zuletzt geändert',
    'ldap_attr_unique_id' => 'Eindeutige ID',
];
?>
<?php if (!$ldapExtensionAvailable) { ?>
    <p class="flash flash--error">Die PHP-Erweiterung <code>ldap</code> ist nicht installiert. Eine Synchronisation ist nicht möglich.</p>
<?php } ?>

<?php if (!$hasBindPassword) { ?>
    <p class="flash flash--info">
        Es ist kein Bind-Passwort gesetzt. Das Passwort wird ausschließlich über die Umgebungsvariable
        <code>LDAP_PASSWORD</code> bzw. <code>LDAP_PASSWORD_FILE</code> konfiguriert und niemals in der Datenbank gespeichert.
    </p>
<?php } ?>

<form method="post" action="/admin/ad" class="form form--wide">
    <?= Csrf::field() ?>

    <fieldset class="fieldset">
        <legend>Verbindung</legend>

        <div class="field-row">
            <div class="field">
                <label for="ldap_host">LDAP-Server</label>
                <input type="text" id="ldap_host" name="ldap_host" maxlength="253" value="<?= Html::e($values['ldap_host']) ?>"
                       placeholder="dc01.example.internal">
                <?php if (isset($errors['ldap_host'])) { ?><p class="field__error"><?= Html::e($errors['ldap_host']) ?></p><?php } ?>
            </div>

            <div class="field">
                <label for="ldap_port">Port</label>
                <input type="number" id="ldap_port" name="ldap_port" min="1" max="65535" value="<?= Html::e($values['ldap_port']) ?>">
                <?php if (isset($errors['ldap_port'])) { ?><p class="field__error"><?= Html::e($errors['ldap_port']) ?></p><?php } ?>
            </div>

            <div class="field">
                <label for="ldap_timeout">Timeout (Sekunden)</label>
                <input type="number" id="ldap_timeout" name="ldap_timeout" min="1" max="120" value="<?= Html::e($values['ldap_timeout']) ?>">
                <?php if (isset($errors['ldap_timeout'])) { ?><p class="field__error"><?= Html::e($errors['ldap_timeout']) ?></p><?php } ?>
            </div>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="ldap_use_tls" name="ldap_use_tls" value="1" <?= $values['ldap_use_tls'] === '1' ? 'checked' : '' ?>>
            <label for="ldap_use_tls">Verschlüsselte Verbindung verwenden (LDAPS auf Port 636, sonst StartTLS)</label>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="ldap_verify_cert" name="ldap_verify_cert" value="1" <?= $values['ldap_verify_cert'] === '1' ? 'checked' : '' ?>>
            <label for="ldap_verify_cert">Zertifikat prüfen (dringend empfohlen)</label>
        </div>

        <div class="field">
            <label for="ldap_base_dn">Base DN</label>
            <input type="text" id="ldap_base_dn" name="ldap_base_dn" maxlength="255" value="<?= Html::e($values['ldap_base_dn']) ?>"
                   placeholder="OU=Benutzer,DC=example,DC=internal">
        </div>

        <div class="field">
            <label for="ldap_bind_dn">Bind DN</label>
            <input type="text" id="ldap_bind_dn" name="ldap_bind_dn" maxlength="255" value="<?= Html::e($values['ldap_bind_dn']) ?>"
                   placeholder="CN=svc-intranet,OU=Dienstkonten,DC=example,DC=internal">
            <p class="field__hint">Das zugehörige Passwort wird über die Umgebung bereitgestellt.</p>
        </div>

        <div class="field">
            <label for="ldap_filter">Suchfilter</label>
            <input type="text" id="ldap_filter" name="ldap_filter" maxlength="512" value="<?= Html::e($values['ldap_filter']) ?>">
            <?php if (isset($errors['ldap_filter'])) { ?><p class="field__error"><?= Html::e($errors['ldap_filter']) ?></p><?php } ?>
        </div>

        <div class="field">
            <label for="ldap_sync_interval">Synchronisationsintervall (Sekunden)</label>
            <input type="number" id="ldap_sync_interval" name="ldap_sync_interval" min="60" max="86400"
                   value="<?= Html::e($values['ldap_sync_interval']) ?>">
            <p class="field__hint">Wird vom Synchronisationsdienst (Container <code>sync</code>) ausgewertet.</p>
            <?php if (isset($errors['ldap_sync_interval'])) { ?><p class="field__error"><?= Html::e($errors['ldap_sync_interval']) ?></p><?php } ?>
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Attributmapping</legend>
        <div class="field-grid">
            <?php foreach ($attributeKeys as $key => $internal) { ?>
                <div class="field">
                    <label for="<?= Html::e($key) ?>"><?= Html::e($attributeLabels[$key] ?? $internal) ?> (<code><?= Html::e($internal) ?></code>)</label>
                    <input type="text" id="<?= Html::e($key) ?>" name="<?= Html::e($key) ?>" maxlength="64"
                           value="<?= Html::e($values[$key] ?? '') ?>">
                    <?php if (isset($errors[$key])) { ?><p class="field__error"><?= Html::e($errors[$key]) ?></p><?php } ?>
                </div>
            <?php } ?>
        </div>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Einstellungen speichern</button>
    </div>
</form>

<section class="card">
    <h2 class="card__title">Synchronisation</h2>
    <p class="card__hint">Aktive Telefonbucheinträge: <?= (int) $phonebookCount ?></p>

    <form method="post" action="/admin/ad/sync" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="button button--primary" <?= $ldapExtensionAvailable ? '' : 'disabled' ?>>
            Manuelle Synchronisation starten
        </button>
    </form>

    <?php if ($syncRuns === []) { ?>
        <p class="card__hint">Es wurde noch keine Synchronisation ausgeführt.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Start</th>
                    <th scope="col">Ende</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktualisiert</th>
                    <th scope="col">Deaktiviert</th>
                    <th scope="col">Meldung</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($syncRuns as $run) { ?>
                    <tr>
                        <td><?= Html::e(Dates::formatDateTime((string) $run['started_at'])) ?></td>
                        <td><?= $run['finished_at'] === null ? '–' : Html::e(Dates::formatDateTime((string) $run['finished_at'])) ?></td>
                        <td>
                            <span class="badge <?= (string) $run['status'] === 'success' ? 'badge--ok' : 'badge--warn' ?>">
                                <?= Html::e((string) $run['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $run['processed'] ?></td>
                        <td><?= (int) $run['deactivated'] ?></td>
                        <td class="table__hint"><?= Html::e((string) ($run['message'] ?? '')) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
