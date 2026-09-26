<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Gemeinsame Formularfelder einer Identitaetsquelle (Verbindung, Gruppen,
 * Attributmapping) fuer Hauptquelle und weitere Quellen.
 */

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,string> $attributeKeys */
/** @var array<string,string> $secretStates */
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
    'ldap_attr_samaccount_name' => 'Windows-Anmeldename',
];
?>
    <fieldset class="fieldset">
        <legend>Verbindung</legend>

        <div class="field">
            <label for="ldap_label">Beschriftung <span aria-hidden="true">*</span></label>
            <input type="text" id="ldap_label" name="ldap_label" required maxlength="100" value="<?= Html::e($values['ldap_label'] ?? '') ?>"
                   placeholder="z. B. Zentrale, Zweigstelle Hamburg, Tochter GmbH"
                   <?= isset($errors['ldap_label']) ? 'aria-invalid="true" aria-describedby="ldap_label-error"' : '' ?>>
            <p class="field__hint">Wird in der Verwaltung, im Telefonbuch und in Protokollen zur Unterscheidung der Verzeichnisse angezeigt.</p>
            <?php if (isset($errors['ldap_label'])) { ?><p class="field__error" id="ldap_label-error"><?= Html::e($errors['ldap_label']) ?></p><?php } ?>
        </div>

        <div class="field">
            <label for="ldap_host">LDAP-Server (ein Server je Zeile)</label>
            <textarea id="ldap_host" name="ldap_host" rows="3" maxlength="2600"
                      placeholder="dc01.example.internal&#10;dc02.example.internal&#10;10.0.0.12"
                      <?= isset($errors['ldap_host']) ? 'aria-invalid="true" aria-describedby="ldap_host-error"' : '' ?>><?= Html::e($values['ldap_host'] ?? '') ?></textarea>
            <p class="field__hint">
                Hostnamen oder IP-Adressen, höchstens <?= (int) \App\Services\IdentitySourceService::MAX_HOSTS ?>.
                Die Server werden der Reihe nach verwendet; ist einer nicht erreichbar, wird automatisch der nächste versucht.
            </p>
            <?php if (isset($errors['ldap_host'])) { ?><p class="field__error" id="ldap_host-error"><?= Html::e($errors['ldap_host']) ?></p><?php } ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="ldap_port">Port</label>
                <input type="number" id="ldap_port" name="ldap_port" min="1" max="65535" value="<?= Html::e($values['ldap_port'] ?? '') ?>">
                <?php if (isset($errors['ldap_port'])) { ?><p class="field__error"><?= Html::e($errors['ldap_port']) ?></p><?php } ?>
            </div>

            <div class="field">
                <label for="ldap_timeout">Timeout (Sekunden)</label>
                <input type="number" id="ldap_timeout" name="ldap_timeout" min="1" max="120" value="<?= Html::e($values['ldap_timeout'] ?? '') ?>">
                <?php if (isset($errors['ldap_timeout'])) { ?><p class="field__error"><?= Html::e($errors['ldap_timeout']) ?></p><?php } ?>
            </div>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="ldap_use_tls" name="ldap_use_tls" value="1" <?= ($values['ldap_use_tls'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label for="ldap_use_tls">Verschlüsselte Verbindung verwenden (LDAPS auf Port 636, sonst StartTLS)</label>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="ldap_verify_cert" name="ldap_verify_cert" value="1" <?= ($values['ldap_verify_cert'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label for="ldap_verify_cert">Zertifikat prüfen (dringend empfohlen)</label>
        </div>

        <div class="field">
            <label for="ldap_base_dn">Base DN</label>
            <input type="text" id="ldap_base_dn" name="ldap_base_dn" maxlength="255" value="<?= Html::e($values['ldap_base_dn'] ?? '') ?>"
                   placeholder="OU=Benutzer,DC=example,DC=internal">
            <?php if (isset($errors['ldap_base_dn'])) { ?><p class="field__error"><?= Html::e($errors['ldap_base_dn']) ?></p><?php } ?>
        </div>

        <div class="field">
            <label for="ldap_bind_dn">Bind DN</label>
            <input type="text" id="ldap_bind_dn" name="ldap_bind_dn" maxlength="255" value="<?= Html::e($values['ldap_bind_dn'] ?? '') ?>"
                   placeholder="CN=svc-intranet,OU=Dienstkonten,DC=example,DC=internal">
            <?php if (isset($errors['ldap_bind_dn'])) { ?><p class="field__error"><?= Html::e($errors['ldap_bind_dn']) ?></p><?php } ?>
        </div>

        <?php
        $secretField = 'ldap_bind_password';
        $secretLabel = 'Passwort des Dienstkontos';
        $secretState = $secretStates['ldap_bind_password'] ?? 'missing';
        require __DIR__ . '/secret.php';
        ?>

        <div class="field">
            <label for="ldap_filter">Suchfilter</label>
            <input type="text" id="ldap_filter" name="ldap_filter" maxlength="512" value="<?= Html::e($values['ldap_filter'] ?? '') ?>">
            <?php if (isset($errors['ldap_filter'])) { ?><p class="field__error"><?= Html::e($errors['ldap_filter']) ?></p><?php } ?>
        </div>

    </fieldset>

    <fieldset class="fieldset">
        <legend>Gruppen für die Rechtevergabe</legend>
        <p class="field__hint">
            Die Synchronisation übernimmt alle Gruppen unterhalb dieser Pfade samt ihrer – auch verschachtelten –
            Mitglieder. Die Gruppen stehen danach bei den Kachel-Berechtigungen als Vorschläge zur Verfügung und
            gelten für per Windows-Anmeldung erkannte Benutzer. Ohne Pfad werden keine Gruppen übernommen.
        </p>

        <div class="field">
            <label for="ldap_group_base_dn">Gruppen-Pfade (Base DN, ein Pfad je Zeile)</label>
            <textarea id="ldap_group_base_dn" name="ldap_group_base_dn" rows="3" maxlength="5200"
                      placeholder="OU=Gruppen,OU=Intranet,DC=example,DC=internal"<?= isset($errors['ldap_group_base_dn']) ? ' aria-invalid="true"' : '' ?>><?= Html::e($values['ldap_group_base_dn'] ?? '') ?></textarea>
            <?php if (isset($errors['ldap_group_base_dn'])) { ?><p class="field__error"><?= Html::e($errors['ldap_group_base_dn']) ?></p><?php } ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="ldap_group_filter">Gruppenfilter</label>
                <input type="text" id="ldap_group_filter" name="ldap_group_filter" maxlength="512"
                       value="<?= Html::e($values['ldap_group_filter'] ?? '') ?>" placeholder="(objectClass=group)">
                <?php if (isset($errors['ldap_group_filter'])) { ?><p class="field__error"><?= Html::e($errors['ldap_group_filter']) ?></p><?php } ?>
            </div>
            <div class="field">
                <label for="ldap_group_name_attribute">Attribut für den Gruppennamen</label>
                <input type="text" id="ldap_group_name_attribute" name="ldap_group_name_attribute" maxlength="64"
                       value="<?= Html::e($values['ldap_group_name_attribute'] ?? '') ?>" placeholder="cn">
                <?php if (isset($errors['ldap_group_name_attribute'])) { ?><p class="field__error"><?= Html::e($errors['ldap_group_name_attribute']) ?></p><?php } ?>
            </div>
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

