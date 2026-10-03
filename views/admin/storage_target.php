<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed>|null $target */
/** @var array<string,string> $versions */
/** @var array<string,string> $kinds */
/** @var array<string,string> $errors */
/** @var array<string,mixed> $values */
/** @var array<string,mixed>|null $tierRoot Basisziel, wenn eine Erweiterung bearbeitet wird */
/** @var int $tierSize Anzahl Ziele des Cold-Tiers */

$tierRoot ??= null;
$tierSize ??= 1;
$kinds ??= \App\Services\Storage\StorageService::KINDS;
$hasPassword = $target !== null && (string) ($target['password'] ?? '') !== '';
$storedKind = $target !== null ? (string) ($target['kind'] ?? 'smb') : '';
$hasSecret = $hasPassword && $storedKind === 's3';
$hasSmbPassword = $hasPassword && $storedKind !== 's3';
if (!array_key_exists('s3_access_key', $values) && $storedKind === 's3') {
    $values['s3_access_key'] = (string) $target['username'];
}
if (!array_key_exists('capacity_gb', $values)) {
    $values['capacity_gb'] = $target !== null ? (string) intdiv((int) ($target['capacity_bytes'] ?? 0), 1073741824) : '0';
}
if ($storedKind === 's3' && !array_key_exists('unc_path', $values)) {
    // Bei S3 haelt unc_path den kanonischen Ort, nicht einen UNC-Pfad.
    $values['unc_path'] = '';
    $values['username'] = '';
}

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
$version = $value('smb_version', 'auto');
$kind = $value('kind', 'smb');
$kind = array_key_exists($kind, $kinds) ? $kind : 'smb';
if ($tierRoot !== null) {
    // Erweiterung: gleiche Art wie das Basisziel (SMB nur mit SMB, S3 nur mit S3).
    $kind = (string) ($tierRoot['kind'] ?? 'smb') === 's3' ? 's3' : 'smb';
}
$kindLocked = $tierRoot !== null || $tierSize > 1;
?>
<div class="storage-page storage-target-editor">
<p class="toolbar"><a class="button button--ghost" href="/admin/speicher-ha#ziele">Zurück zu Speicher (HA)</a></p>

<p class="card__hint">
    Ein Speicherziel im <strong>Cold-Tier (SMB-/S3-Tier)</strong> ist eine SMB-Freigabe oder ein Bucket eines
    S3-kompatiblen Objektspeichers (z. B. MinIO, Ceph, NetApp StorageGRID, AWS S3). Jedes aktive Ziel erhält eine
    vollständige, identische Kopie der Daten von Nextcloud und Euro-Office. Nach dem Speichern bindet
    <code>storage-sync</code> das Ziel ein, legt die Kennungsdatei <code>.lanpa-storage.json</code> an und gleicht alle
    Daten ab – bei einem neuen Ziel kann der erste Abgleich je nach Datenmenge längere Zeit dauern.
</p>

<?php if ($tierRoot !== null) { ?>
    <p class="flash flash--info">
        Dieses Ziel ist eine <strong>Erweiterung des Cold-Tiers „<?= Html::e((string) $tierRoot['label']) ?>“</strong>.
        Art, Status und Rolle folgen dem Basisziel; neue Dateien des Tiers landen hier, sobald die vorherigen Ziele voll sind.
    </p>
<?php } elseif ($tierSize > 1) { ?>
    <p class="flash flash--info">
        Dieser Cold-Tier ist um <?= $tierSize - 1 ?> Ziel(e) erweitert. Die Art kann nicht mehr geändert werden;
        „Aktiv“ gilt für den ganzen Tier samt Erweiterungen.
    </p>
<?php } ?>

<?php if (isset($errors['target'])) { ?>
    <p class="flash flash--error" role="alert"><?= Html::e($errors['target']) ?></p>
<?php } ?>

<form method="post" action="/admin/speicher-ha/ziel" class="form" autocomplete="off">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= $isNew ? 0 : (int) $target['id'] ?>">

    <fieldset class="storage-fieldset">
    <legend>Speicherziel</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="label">Bezeichnung</label>
        <input type="text" id="label" name="label" maxlength="100" required value="<?= Html::e($value('label')) ?>" <?= $attrs('label') ?>>
        <p class="field__hint" id="label-hint">z. B. „NAS Rechenzentrum“, „Filer Standort B“ oder „MinIO Standort C“.</p>
        <?= $error('label') ?>
    </div>

    <div class="field">
        <label for="kind">Art des Speicherziels</label>
        <?php if ($kindLocked) { ?>
            <input type="hidden" name="kind" value="<?= Html::e($kind) ?>" data-storage-kind>
            <input type="text" id="kind" value="<?= Html::e($kinds[$kind]) ?>" readonly <?= $attrs('kind') ?>>
            <p class="field__hint" id="kind-hint">Ein erweiterter Cold-Tier behält seine Art: SMB wird nur mit SMB, S3 nur mit S3 erweitert.</p>
        <?php } else { ?>
        <select id="kind" name="kind" data-storage-kind <?= $attrs('kind') ?>>
            <?php foreach ($kinds as $key => $text) { ?>
                <option value="<?= Html::e($key) ?>" <?= $kind === $key ? 'selected' : '' ?>><?= Html::e($text) ?></option>
            <?php } ?>
        </select>
        <p class="field__hint" id="kind-hint">SMB-Freigabe eines NAS oder Fileservers bzw. Bucket eines S3-kompatiblen Objektspeichers. Ohne JavaScript werden nur die Felder der gewählten Art ausgewertet.</p>
        <?php } ?>
        <?= $error('kind') ?>
    </div>
    </div>
    </fieldset>

    <div data-kind-section="smb">
    <fieldset class="storage-fieldset">
    <legend>SMB-Freigabe</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="unc_path">UNC-Pfad</label>
        <input type="text" id="unc_path" name="unc_path" maxlength="500" spellcheck="false"
               placeholder="\\server\freigabe\intranet" value="<?= Html::e($value('unc_path')) ?>" <?= $attrs('unc_path') ?>>
        <p class="field__hint" id="unc_path-hint">Freigabe und optional ein Unterordner, z. B. <code>\\nas01\backup\lanpa</code>. Der Ordner sollte ausschließlich für dieses Intranet genutzt werden.</p>
        <?= $error('unc_path') ?>
    </div>
    </div>
    </fieldset>

    <fieldset class="storage-fieldset">
    <legend>Zugangsdaten (SMB)</legend>
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
            <?= $hasSmbPassword
                ? 'Ein Kennwort ist gespeichert (verschlüsselt). Leer lassen, um es beizubehalten.'
                : 'Wird verschlüsselt gespeichert und nur an storage-sync für die Einbindung übergeben.' ?>
        </p>
        <?= $error('password') ?>
    </div>
    <?php if ($hasSmbPassword) { ?>
        <div class="field field--check">
            <input type="hidden" name="password_clear" value="0">
            <input type="checkbox" id="password_clear" name="password_clear" value="1" <?= $value('password_clear') === '1' ? 'checked' : '' ?>>
            <label for="password_clear">Gespeichertes Kennwort entfernen</label>
        </div>
    <?php } ?>
    </div>
    </fieldset>

    <fieldset class="storage-fieldset">
    <legend>Protokoll (SMB)</legend>
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
    </div>
    </fieldset>
    </div>

    <div data-kind-section="s3">
    <fieldset class="storage-fieldset">
    <legend>S3-Objektspeicher</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="s3_endpoint">Endpunkt</label>
        <input type="url" id="s3_endpoint" name="s3_endpoint" maxlength="255" spellcheck="false"
               placeholder="https://s3.example.local:9000" value="<?= Html::e($value('s3_endpoint')) ?>" <?= $attrs('s3_endpoint') ?>>
        <p class="field__hint" id="s3_endpoint-hint">Adresse des S3-API-Endpunkts ohne Pfad, z. B. <code>https://minio.firma.local:9000</code> oder <code>https://s3.eu-central-1.amazonaws.com</code>. HTTPS wird empfohlen.</p>
        <?= $error('s3_endpoint') ?>
    </div>

    <div class="field">
        <label for="s3_region">Region</label>
        <input type="text" id="s3_region" name="s3_region" maxlength="64" spellcheck="false"
               placeholder="eu-central-1" value="<?= Html::e($value('s3_region')) ?>" <?= $attrs('s3_region') ?>>
        <p class="field__hint" id="s3_region-hint">Region des Buckets für die Signatur (AWS Signature V4). Leer = automatisch bzw. <code>us-east-1</code> (Standard bei MinIO und Ceph).</p>
        <?= $error('s3_region') ?>
    </div>

    <div class="field">
        <label for="s3_bucket">Bucket</label>
        <input type="text" id="s3_bucket" name="s3_bucket" maxlength="63" spellcheck="false"
               placeholder="lanpa-cold" value="<?= Html::e($value('s3_bucket')) ?>" <?= $attrs('s3_bucket') ?>>
        <p class="field__hint" id="s3_bucket-hint">Vorhandener Bucket. Er sollte ausschließlich für dieses Intranet genutzt werden.</p>
        <?= $error('s3_bucket') ?>
    </div>

    <div class="field">
        <label for="s3_prefix">Präfix (Unterordner)</label>
        <input type="text" id="s3_prefix" name="s3_prefix" maxlength="255" spellcheck="false"
               placeholder="intranet" value="<?= Html::e($value('s3_prefix')) ?>" <?= $attrs('s3_prefix') ?>>
        <p class="field__hint" id="s3_prefix-hint">Optional, z. B. <code>lanpa/produktiv</code>. Leer = gesamter Bucket.</p>
        <?= $error('s3_prefix') ?>
    </div>

    <div class="field">
        <label for="capacity_gb">Kapazität (GB)</label>
        <input type="number" id="capacity_gb" name="capacity_gb" min="0" step="1" inputmode="numeric"
               value="<?= Html::e($value('capacity_gb', '0')) ?>" <?= $attrs('capacity_gb') ?>>
        <p class="field__hint" id="capacity_gb-hint">Kontingent (Quota) des Buckets für Füllstand und Warnschwellen. 0 = ohne Grenze (kein Füllstand).</p>
        <?= $error('capacity_gb') ?>
    </div>
    </div>
    </fieldset>

    <fieldset class="storage-fieldset">
    <legend>Zugangsdaten (S3)</legend>
    <div class="storage-fields">
    <div class="field">
        <label for="s3_access_key">Access Key ID</label>
        <input type="text" id="s3_access_key" name="s3_access_key" maxlength="128" spellcheck="false" autocomplete="off"
               value="<?= Html::e($value('s3_access_key')) ?>" <?= $attrs('s3_access_key') ?>>
        <p class="field__hint" id="s3_access_key-hint">Eigener Schlüssel nur für diesen Bucket (Lesen, Schreiben, Auflisten, Löschen).</p>
        <?= $error('s3_access_key') ?>
    </div>

    <div class="field">
        <label for="s3_secret_key">Secret Access Key</label>
        <input type="password" id="s3_secret_key" name="s3_secret_key" maxlength="256" autocomplete="new-password" value="" <?= $attrs('s3_secret_key') ?>>
        <p class="field__hint" id="s3_secret_key-hint">
            <?= $hasSecret
                ? 'Ein Secret Key ist gespeichert (verschlüsselt). Leer lassen, um ihn beizubehalten.'
                : 'Wird verschlüsselt gespeichert und nur an storage-sync für die Einbindung übergeben.' ?>
        </p>
        <?= $error('s3_secret_key') ?>
    </div>

    <div class="field field--check">
        <input type="hidden" name="s3_path_style" value="0">
        <input type="checkbox" id="s3_path_style" name="s3_path_style" value="1" <?= $value('s3_path_style', '1') === '1' ? 'checked' : '' ?>>
        <label for="s3_path_style">Pfad-Adressierung (path-style)</label>
        <p class="field__hint">Adressierung als <code>https://endpunkt/bucket</code> – für MinIO, Ceph und die meisten lokalen Objektspeicher erforderlich. Aus = virtuelle Hosts (<code>https://bucket.endpunkt</code>, z. B. AWS).</p>
    </div>

    <div class="field field--check">
        <input type="hidden" name="s3_verify_tls" value="0">
        <input type="checkbox" id="s3_verify_tls" name="s3_verify_tls" value="1" <?= $value('s3_verify_tls', '1') === '1' ? 'checked' : '' ?>>
        <label for="s3_verify_tls">TLS-Zertifikat prüfen</label>
        <p class="field__hint">Nur für Tests mit selbstsignierten Zertifikaten abschalten.</p>
    </div>
    </div>
    </fieldset>
    </div>

    <?php if ($tierRoot === null) { ?>
    <fieldset class="storage-fieldset">
    <legend>Verwendung</legend>
    <div class="storage-fields">

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
        <p class="field__hint">Deaktivierte Ziele werden nicht mehr beschrieben und zählen nicht zum HA-Status. Die Daten auf der Freigabe bzw. im Bucket bleiben erhalten.<?= $tierSize > 1 ? ' Gilt für alle Erweiterungen dieses Cold-Tiers.' : '' ?></p>
    </div>
    </div>
    </fieldset>
    <?php } ?>

    <div class="form__actions">
        <button type="submit" class="button button--primary"><?= $isNew ? 'Speicherziel hinzufügen' : 'Speichern' ?></button>
        <a class="button button--ghost" href="/admin/speicher-ha#ziele">Abbrechen</a>
    </div>
</form>
</div>
