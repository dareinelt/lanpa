<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Windows-Anmeldung (NTLM) einer Identitaetsquelle: Domaene, Domaenen-
 * controller und Konto fuer den Domaenenbeitritt des auth-Containers.
 */

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,string> $secretStates */
/** @var bool $primary */
/** @var bool $ssoEnabled */
/** @var string $serviceName */
$ssoError = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? '<p class="field__error" id="' . Html::e($field) . '-error">' . Html::e($errors[$field]) . '</p>' : '';
};
$ssoInvalid = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? 'aria-invalid="true" aria-describedby="' . Html::e($field) . '-error"' : '';
};
?>
<fieldset class="fieldset">
    <legend>Windows-Anmeldung (SSO)</legend>
    <p class="field__hint">
        <?php if ($primary) { ?>
            Domäne, der der Container <code>auth</code> beitritt. Die Zugangsdaten werden verschlüsselt gespeichert und vom
            Container beim Start abgerufen – ein Eintrag in der <code>.env</code> ist nicht nötig.
            Leer lassen, wenn keine Windows-Anmeldung genutzt wird.
        <?php } else { ?>
            Domänen ohne Vertrauensstellung erhalten eine eigene Anmelde-Instanz
            (<code><?= Html::e($serviceName !== '' ? $serviceName : 'auth-<kennung>') ?></code>). Clients werden anhand ihres Netzes
            bzw. des aufgerufenen Hostnamens dieser Domäne zugeordnet.
        <?php } ?>
        <?php if (!$ssoEnabled) { ?>
            <br><strong>Hinweis:</strong> Die Windows-Anmeldung ist derzeit global ausgeschaltet (<code>SSO_ENABLED</code>).
        <?php } ?>
    </p>

    <?php if (!$primary) { ?>
        <div class="field field--check">
            <input type="checkbox" id="sso_enabled" name="sso_enabled" value="1" <?= ($values['sso_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <label for="sso_enabled">Windows-Anmeldung für diese Domäne aktivieren</label>
        </div>
    <?php } ?>

    <div class="field">
        <label for="sso_domain">Domäne (NetBIOS-Name)</label>
        <input type="text" id="sso_domain" name="sso_domain" maxlength="15" value="<?= Html::e($values['sso_domain'] ?? '') ?>"
               placeholder="<?= $primary ? 'FIRMA' : 'HAMBURG' ?>" autocomplete="off" <?= $ssoInvalid('sso_domain') ?>>
        <?= $ssoError('sso_domain') ?>
    </div>

    <div class="field">
        <label for="sso_dcs">Domänencontroller</label>
        <textarea id="sso_dcs" name="sso_dcs" rows="3" <?= $ssoInvalid('sso_dcs') ?>
                  placeholder="dc01.example.internal 10.0.0.10&#10;dc02.example.internal 10.0.0.11"><?= Html::e($values['sso_dcs'] ?? '') ?></textarea>
        <p class="field__hint">Je Zeile ein Domänencontroller (Hostname, optional mit IP-Adresse, falls kein DNS verfügbar ist). Ist einer nicht erreichbar, wird der nächste verwendet.</p>
        <?= $ssoError('sso_dcs') ?>
    </div>

    <div class="field">
        <label for="sso_join_user">Konto für den Domänenbeitritt</label>
        <input type="text" id="sso_join_user" name="sso_join_user" maxlength="255" value="<?= Html::e($values['sso_join_user'] ?? '') ?>"
               placeholder="svc-intranet-join" autocomplete="off" <?= $ssoInvalid('sso_join_user') ?>>
        <?= $ssoError('sso_join_user') ?>
    </div>

    <?php
    $secretField = 'sso_join_password';
    $secretLabel = 'Passwort für den Domänenbeitritt';
    $secretState = $secretStates['sso_join_password'] ?? 'missing';
    require __DIR__ . '/secret.php';
    ?>

    <?php if (!$primary) { ?>
        <div class="field-row">
            <div class="field">
                <label for="sso_networks">Client-Netze</label>
                <textarea id="sso_networks" name="sso_networks" rows="3" <?= $ssoInvalid('sso_networks') ?>
                          placeholder="10.20.0.0/16"><?= Html::e($values['sso_networks'] ?? '') ?></textarea>
                <p class="field__hint">CIDR, je Zeile ein Netz.</p>
                <?= $ssoError('sso_networks') ?>
            </div>
            <div class="field">
                <label for="sso_hostnames">Hostnamen (optional)</label>
                <textarea id="sso_hostnames" name="sso_hostnames" rows="3" <?= $ssoInvalid('sso_hostnames') ?>
                          placeholder="intranet-hh.example.internal"><?= Html::e($values['sso_hostnames'] ?? '') ?></textarea>
                <p class="field__hint">Aufgerufene Hostnamen haben Vorrang vor den Netzen.</p>
                <?= $ssoError('sso_hostnames') ?>
            </div>
        </div>
        <p class="field__hint">
            Nach dem Aktivieren bzw. Entfernen einer Domäne auf dem Server <code>./scripts/sso-domains.sh</code> ausführen;
            bei geänderten Zugangsdaten genügt <code>docker compose restart auth <?= Html::e($serviceName !== '' ? $serviceName : '') ?></code>.
        </p>
    <?php } else { ?>
        <p class="field__hint">Nach Änderungen: <code>docker compose restart auth</code>.</p>
    <?php } ?>
</fieldset>
