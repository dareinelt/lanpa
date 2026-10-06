<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/**
 * Bearbeitungs-Overlay einer Identitaetsquelle (Hauptquelle, neue oder
 * weitere Quelle) auf der Uebersichtsseite. admin-ldap.js oeffnet es modal;
 * Schliessen fuehrt zurueck zur Uebersicht.
 */

/** @var array<string,mixed> $dialog */
/** @var array<string,string> $attributeKeys */
/** @var bool $ssoEnabled */
$sourceId = $dialog['sourceId'];
$primary = (bool) $dialog['primary'];
$values = $dialog['values'];
$errors = $dialog['errors'];
$secretStates = $dialog['secretStates'];
$serviceName = (string) $dialog['serviceName'];
?>
<dialog id="ad-source-dialog" class="ad-source" aria-labelledby="ad-source-title" open data-ad-source-dialog>
    <div class="ad-source__header">
        <h2 id="ad-source-title"><?= Html::e((string) $dialog['title']) ?></h2>
        <a class="button button--ghost" href="/admin/ad" aria-label="Schließen">✕</a>
    </div>

    <?php if (in_array('invalid', $secretStates, true)) { ?>
        <p class="flash flash--error">
            Mindestens ein gespeichertes Passwort dieser Quelle kann nicht entschlüsselt werden (z. B. nach einer
            Wiederherstellung auf einem anderen Server). Bitte neu eingeben.
        </p>
    <?php } ?>

<form method="post" action="<?= Html::e((string) $dialog['action']) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if ($sourceId !== null) { ?>
        <input type="hidden" name="id" value="<?= (int) $sourceId ?>">
    <?php } ?>

    <?php if (!$primary) { ?>
    <fieldset class="fieldset">
        <legend>Identitätsquelle</legend>

        <div class="field-row">
            <div class="field">
                <label for="ldap_key">Kennung <span aria-hidden="true">*</span></label>
                <input type="text" id="ldap_key" name="ldap_key" required maxlength="32" pattern="[A-Za-z][A-Za-z0-9_]{0,31}"
                       value="<?= Html::e($values['ldap_key'] ?? '') ?>" placeholder="HAMBURG"
                       <?= isset($errors['ldap_key']) ? 'aria-invalid="true" aria-describedby="ldap_key-error"' : '' ?>>
                <p class="field__hint">
                    Großbuchstaben, Ziffern und Unterstrich. Bestimmt den Namen der Anmelde-Instanz
                    (<code>auth-&lt;kennung&gt;</code>) und die Office-Benutzerkennung (<code>name@kennung</code>);
                    nachträglich möglichst nicht mehr ändern.
                </p>
                <?php if (isset($errors['ldap_key'])) { ?><p class="field__error" id="ldap_key-error"><?= Html::e($errors['ldap_key']) ?></p><?php } ?>
            </div>

            <div class="field">
                <label for="ldap_sort_order">Reihenfolge</label>
                <input type="number" id="ldap_sort_order" name="ldap_sort_order" min="0" max="9999"
                       value="<?= Html::e($values['ldap_sort_order'] ?? '0') ?>">
                <?php if (isset($errors['ldap_sort_order'])) { ?><p class="field__error"><?= Html::e($errors['ldap_sort_order']) ?></p><?php } ?>
            </div>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="ldap_active" name="ldap_active" value="1" <?= ($values['ldap_active'] ?? '1') === '1' ? 'checked' : '' ?>>
            <label for="ldap_active">Aktiv (wird synchronisiert und für die Windows-Anmeldung akzeptiert)</label>
        </div>
    </fieldset>
    <?php } ?>

    <?php require __DIR__ . '/fields.php'; ?>

    <?php require __DIR__ . '/sso.php'; ?>

    <div class="form__actions">
        <button type="submit" class="button button--primary"><?= $primary ? 'Hauptquelle speichern' : 'Identitätsquelle speichern' ?></button>
        <a class="button button--ghost" href="/admin/ad">Abbrechen</a>
    </div>
</form>
</dialog>
