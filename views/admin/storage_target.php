<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed>|null $target */
/** @var array<string,string> $versions */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $values */

$isNew = $target === null;
$value = static function (string $key, string $default = '') use ($values, $target): string {
    if (array_key_exists($key, $values)) {
        return is_bool($values[$key]) ? ($values[$key] ? '1' : '0') : (string) $values[$key];
    }

    return $target !== null && isset($target[$key]) ? (string) $target[$key] : $default;
};
$attrs = static function (string $key) use ($errors): string {
    return isset($errors[$key])
        ? 'aria-invalid="true" aria-describedby="' . $key . '-error"'
        : 'aria-describedby="' . $key . '-hint"';
};
$error = static function (string $key) use ($errors): string {
    return isset($errors[$key]) ? '<p class="field__error" id="' . $key . '-error">' . Html::e($errors[$key]) . '</p>' : '';
};
$hasPassword = $target !== null && (string) ($target['password'] ?? '') !== '';
$version = $value('smb_version', 'auto');
?>
<div class="storage-page storage-target-editor">
<p class="toolbar"><a class="button button--ghost" href="/admin/speicher-ha#ziele">Zurück zu Speicher (HA)</a></p>

<p class="card__hint">
    Ein Speicherziel ist eine SMB-Freigabe im <strong>Cold-Tier (SMB-Tier)</strong>. Jedes aktive Ziel erhält eine
    vollständige, identische Kopie der Daten von Nextcloud und Euro-Office. Nach dem Speichern bindet
    <code>storage-sync</code> die Freigabe ein, legt die Kennungsdatei <code>.lanpa-storage.json</code> an und gleicht alle
    Daten ab – bei einem neuen Ziel kann der erste Abgleich je nach Datenmenge längere Zeit dauern.
</p>

<?php if (isset($errors['target'])) { ?>
    <p class="flash flash--error" role="alert"><?= Html::e($errors['target']) ?></p>
<?php } ?>

<form method="post" action="/admin/speicher-ha/ziel" class="form" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= $isNew ? 0 : (int) $target['id'] ?>">

    <fieldset class="storage-fieldset">
    <legend>Speicherziel und Verbindung</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="label">Bezeichnung</label>
        <input type="text" id="label" name="label" maxlength="100" required value="<?= Html::e($value('label')) ?>" <?= $attrs('label') ?>>
        <p class="field__hint" id="label-hint">z. B. „NAS Rechenzentrum“ oder „Filer Standort B“.</p>
        <?= $error('label') ?>
    </div>

    <div class="field">
        <label for="unc_path">UNC-Pfad</label>
        <input type="text" id="unc_path" name="unc_path" maxlength="500" required spellcheck="false"
               placeholder="\\server\freigabe\intranet" value="<?= Html::e($value('unc_path')) ?>" <?= $attrs('unc_path') ?>>
        <p class="field__hint" id="unc_path-hint">Freigabe und optional ein Unterordner, z. B. <code>\\nas01\backup\lanpa</code>. Der Ordner sollte ausschließlich für dieses Intranet genutzt werden.</p>
        <?= $error('unc_path') ?>
    </div>
    </div>
    </fieldset>

    <fieldset class="storage-fieldset">
    <legend>Zugangsdaten</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="username">Benutzername</label>
        <input type="text" id="username" name="username" maxlength="128" spellcheck="false" autocomplete="off"
               value="<?= Html::e($value('username')) ?>" <?= $attrs('username') ?>>
        <p class="field__hint" id="username-hint">Dienstkonto mit Lese- und Schreibrechten auf der Freigabe, ohne Domäne. Leer = Gastzugriff.</p>
        <?= $error('username') ?>
    </div>

    <div class="field">
        <label for="domain">Domäne</label>
        <input type="text" id="domain" name="domain" maxlength="128" spellcheck="false"
               value="<?= Html::e($value('domain')) ?>" <?= $attrs('domain') ?>>
        <p class="field__hint" id="domain-hint">z. B. <code>FIRMA</code> oder <code>firma.local</code>. Leer bei lokalen Konten des Servers.</p>
        <?= $error('domain') ?>
    </div>

    <div class="field">
        <label for="password">Kennwort</label>
        <input type="password" id="password" name="password" maxlength="256" autocomplete="new-password" value="" <?= $attrs('password') ?>>
        <p class="field__hint" id="password-hint">
            <?= $hasPassword
                ? 'Ein Kennwort ist gespeichert (verschlüsselt). Leer lassen, um es beizubehalten.'
                : 'Wird verschlüsselt gespeichert und nur an storage-sync für die Einbindung übergeben.' ?>
        </p>
        <?= $error('password') ?>
    </div>
    <?php if ($hasPassword) { ?>
        <div class="field field--check">
            <input type="hidden" name="password_clear" value="0">
            <input type="checkbox" id="password_clear" name="password_clear" value="1" <?= $value('password_clear') === '1' ? 'checked' : '' ?>>
            <label for="password_clear">Gespeichertes Kennwort entfernen</label>
        </div>
    <?php } ?>
    </div>
    </fieldset>

    <fieldset class="storage-fieldset">
    <legend>Protokoll und Verwendung</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="smb_version">SMB-Version</label>
        <select id="smb_version" name="smb_version" <?= $attrs('smb_version') ?>>
            <?php foreach ($versions as $key => $text) { ?>
                <option value="<?= Html::e($key) ?>" <?= $version === $key ? 'selected' : '' ?>><?= Html::e($text) ?></option>
            <?php } ?>
        </select>
        <p class="field__hint" id="smb_version-hint">Nur ändern, wenn der Server die automatische Aushandlung nicht unterstützt. SMB 1 wird nicht unterstützt.</p>
        <?= $error('smb_version') ?>
    </div>

    <div class="field field--check">
        <input type="hidden" name="is_primary" value="0">
        <input type="checkbox" id="is_primary" name="is_primary" value="1" <?= $value('is_primary', '0') === '1' ? 'checked' : '' ?>>
        <label for="is_primary">Primäres Ziel</label>
        <p class="field__hint">Ausgelagerte Dateien werden bevorzugt von diesem Ziel in den Hot-Tier zurückgeholt. Das erste Ziel wird automatisch primär.</p>
    </div>

    <div class="field field--check">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" id="active" name="active" value="1" <?= $value('active', '1') === '1' ? 'checked' : '' ?>>
        <label for="active">Aktiv</label>
        <p class="field__hint">Deaktivierte Ziele werden nicht mehr beschrieben und zählen nicht zum HA-Status. Die Daten auf der Freigabe bleiben erhalten.</p>
    </div>
    </div>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary"><?= $isNew ? 'Speicherziel hinzufügen' : 'Speichern' ?></button>
        <a class="button button--ghost" href="/admin/speicher-ha#ziele">Abbrechen</a>
    </div>
</form>
</div>
