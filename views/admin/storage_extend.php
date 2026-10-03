<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Storage\StorageHealth;
use App\Support\Html;

/** @var list<array<string,mixed>> $tiers Cold-Tiers aus StorageService::overview() */
/** @var array<string,string> $versions */
/** @var array<string,string> $errors */
/** @var array<int,array<string,mixed>> $values */

?>
<div class="storage-page storage-target-editor">
<p class="toolbar"><a class="button button--ghost" href="/admin/speicher-ha#ziele">Zurück zu Speicher (HA)</a></p>

<p class="card__hint">
    Jeder Cold-Tier hält eine vollständige Kopie aller Daten. Um die Kapazität zu erhöhen, werden <strong>alle
    Cold-Tiers gleichzeitig</strong> um je ein weiteres Ziel erweitert – so bleiben sie im Gleichgewicht und jeder Tier
    kann weiterhin den gesamten Datenbestand aufnehmen. Ein SMB-Tier wird nur mit einer SMB-Freigabe, ein S3-Tier nur
    mit einem S3-Bucket erweitert. Die bisherigen Daten bleiben, wo sie sind; <code>storage-sync</code> legt neue und
    geänderte Dateien auf der Erweiterung ab, sobald die vorhandenen Ziele des Tiers voll sind. Ein volles Ziel wird
    weiterhin als voll angezeigt, die Warnung „Speicherplatz unzureichend“ entfällt aber, solange der Tier insgesamt
    Platz hat.
</p>

<ol class="tier-plan" aria-label="Geplante Erweiterung">
    <?php foreach ($tiers as $tier) { ?>
        <?php $members = $tier['members'] ?? [$tier]; ?>
        <li class="tier-plan__tier">
            <div class="tier-plan__head">
                <strong><?= Html::e((string) $tier['label']) ?></strong>
                <span class="badge badge--muted"><?= ($tier['kind'] ?? 'smb') === 's3' ? 'S3' : 'SMB' ?></span>
            </div>
            <ol class="tier-plan__members">
                <?php foreach ($members as $index => $member) { ?>
                    <li class="tier-plan__member tier-plan__member--<?= Html::e((string) ($member['fill']['state'] ?? 'disabled')) ?>">
                        <span class="tier-plan__role"><?= $index === 0 ? 'Basisziel' : 'Erweiterung ' . (int) $index ?></span>
                        <?= Html::e((string) $member['label']) ?>
                        <span class="card__hint"><?= ($member['fill']['percent'] ?? null) === null
                            ? 'ohne Füllstand'
                            : Html::e(number_format((float) $member['fill']['percent'], 1, ',', '.')) . ' % von ' . Html::e(StorageHealth::formatBytes((int) $member['total_bytes'])) ?></span>
                    </li>
                <?php } ?>
                <li class="tier-plan__member tier-plan__member--new">
                    <span class="tier-plan__role">Erweiterung <?= count($members) ?> (neu)</span>
                    <span data-plan-label="<?= (int) $tier['id'] ?>"><?= Html::e((string) ($values[(int) $tier['id']]['label'] ?? '')) ?: '…' ?></span>
                </li>
            </ol>
        </li>
    <?php } ?>
</ol>

<?php if (isset($errors['tiers'])) { ?>
    <p class="flash flash--error" role="alert"><?= Html::e($errors['tiers']) ?></p>
<?php } ?>

<form method="post" action="/admin/speicher-ha/erweitern" class="form" autocomplete="off">
    <?= Csrf::field() ?>

    <?php foreach ($tiers as $tier) { ?>
        <?php
        $id = (int) $tier['id'];
        $kind = ($tier['kind'] ?? 'smb') === 's3' ? 's3' : 'smb';
        $level = count($tier['members'] ?? [$tier]);
        $input = $values[$id] ?? [];
        // Vorbelegung aus dem Basisziel (Endpunkt/Region bei S3).
        $defaults = ['capacity_gb' => '0', 's3_endpoint' => (string) ($tier['s3_endpoint'] ?? ''), 's3_region' => (string) ($tier['s3_region'] ?? '')];
        $name = static fn (string $key): string => 'tiers[' . $id . '][' . $key . ']';
        $fid = static fn (string $key): string => 'tier-' . $id . '-' . $key;
        $val = static function (string $key, string $default = '') use ($input): string {
            if (!array_key_exists($key, $input)) {
                return $default;
            }

            return is_bool($input[$key]) ? ($input[$key] ? '1' : '0') : (string) $input[$key];
        };
        $attrs = static function (string $key) use ($errors, $id, $fid): string {
            $errorKey = 'tier_' . $id . '_' . $key;

            return isset($errors[$errorKey])
                ? 'aria-invalid="true" aria-describedby="' . $fid($key) . '-error"'
                : 'aria-describedby="' . $fid($key) . '-hint"';
        };
        $error = static function (string $key) use ($errors, $id, $fid): string {
            $errorKey = 'tier_' . $id . '_' . $key;

            return isset($errors[$errorKey]) ? '<p class="field__error" id="' . $fid($key) . '-error">' . Html::e($errors[$errorKey]) . '</p>' : '';
        };
        $text = static function (string $key, string $label, string $hint, string $type = 'text', string $extra = '') use ($name, $fid, $val, $attrs, $error, $defaults): string {
            return '<div class="field"><label for="' . $fid($key) . '">' . Html::e($label) . '</label>'
                . '<input type="' . $type . '" id="' . $fid($key) . '" name="' . Html::e($name($key)) . '" value="'
                . ($type === 'password' ? '' : Html::e($val($key, $defaults[$key] ?? ''))) . '" ' . $extra . ' ' . $attrs($key) . '>'
                . '<p class="field__hint" id="' . $fid($key) . '-hint">' . $hint . '</p>' . $error($key) . '</div>';
        };
        $flag = static function (string $key, string $label, string $hint, string $default) use ($name, $fid, $val): string {
            return '<div class="field field--check"><input type="hidden" name="' . Html::e($name($key)) . '" value="0">'
                . '<input type="checkbox" id="' . $fid($key) . '" name="' . Html::e($name($key)) . '" value="1" ' . ($val($key, $default) === '1' ? 'checked' : '') . '>'
                . '<label for="' . $fid($key) . '">' . Html::e($label) . '</label><p class="field__hint">' . $hint . '</p></div>';
        };
        ?>
        <fieldset class="storage-fieldset tier-extend" data-tier="<?= $id ?>">
            <legend>
                Cold-Tier „<?= Html::e((string) $tier['label']) ?>“ · Erweiterung <?= $level ?>
                (<?= $kind === 's3' ? 'S3-Bucket' : 'SMB-Freigabe' ?>)
            </legend>
            <input type="hidden" name="<?= Html::e($name('kind')) ?>" value="<?= $kind ?>">
            <?= $error('kind') ?>
            <div class="storage-fields">
                <?= $text('label', 'Bezeichnung', 'z. B. „' . Html::e((string) $tier['label']) . ' – Erweiterung ' . $level . '“.', 'text', 'maxlength="100" required data-plan-source="' . $id . '"') ?>
                <?php if ($kind === 'smb') { ?>
                    <?= $text('unc_path', 'UNC-Pfad', 'Weitere Freigabe (oder anderer Ordner auf einem anderen Volume), z. B. <code>\\\\nas02\\backup\\lanpa</code>. Nicht dieselbe Freigabe wie ein vorhandenes Ziel.', 'text', 'maxlength="500" spellcheck="false" required placeholder="\\\\server\\freigabe\\intranet"') ?>
                    <?= $flag('reuse_credentials', 'Zugangsdaten des Basisziels übernehmen', 'Benutzername, Domäne und gespeichertes Kennwort von „' . Html::e((string) $tier['label']) . '“ verwenden – die Felder darunter werden dann ignoriert.', '0') ?>
                    <?= $text('username', 'Benutzername', 'Dienstkonto mit Lese- und Schreibrechten, ohne Domäne. Leer = Gastzugriff.', 'text', 'maxlength="128" spellcheck="false" autocomplete="off"') ?>
                    <?= $text('domain', 'Domäne', 'z. B. <code>FIRMA</code>. Leer bei lokalen Konten des Servers.', 'text', 'maxlength="128" spellcheck="false"') ?>
                    <?= $text('password', 'Kennwort', 'Wird verschlüsselt gespeichert.', 'password', 'maxlength="256" autocomplete="new-password"') ?>
                    <div class="field">
                        <label for="<?= $fid('smb_version') ?>">SMB-Version</label>
                        <select id="<?= $fid('smb_version') ?>" name="<?= Html::e($name('smb_version')) ?>" <?= $attrs('smb_version') ?>>
                            <?php foreach ($versions as $key => $versionText) { ?>
                                <option value="<?= Html::e($key) ?>" <?= $val('smb_version', (string) ($tier['smb_version'] ?? 'auto')) === $key ? 'selected' : '' ?>><?= Html::e($versionText) ?></option>
                            <?php } ?>
                        </select>
                        <p class="field__hint" id="<?= $fid('smb_version') ?>-hint">Vorbelegt mit der Einstellung des Basisziels.</p>
                        <?= $error('smb_version') ?>
                    </div>
                <?php } else { ?>
                    <?= $text('s3_endpoint', 'Endpunkt', 'Adresse des S3-API-Endpunkts ohne Pfad. Vorbelegt mit dem Endpunkt des Basisziels.', 'url', 'maxlength="255" spellcheck="false" required') ?>
                    <?= $text('s3_region', 'Region', 'Leer = automatisch bzw. <code>us-east-1</code>.', 'text', 'maxlength="64" spellcheck="false"') ?>
                    <?= $text('s3_bucket', 'Bucket', 'Weiterer, vorhandener Bucket (oder anderes Präfix) ausschließlich für dieses Intranet.', 'text', 'maxlength="63" spellcheck="false" required') ?>
                    <?= $text('s3_prefix', 'Präfix (Unterordner)', 'Optional. Endpunkt, Bucket und Präfix dürfen nicht mit einem vorhandenen Ziel übereinstimmen.', 'text', 'maxlength="255" spellcheck="false"') ?>
                    <?= $text('capacity_gb', 'Kapazität (GB)', 'Kontingent des Buckets; erst bei einer Grenze kann storage-sync erkennen, wann das Ziel voll ist. 0 = ohne Grenze.', 'number', 'min="0" step="1" inputmode="numeric"') ?>
                    <?= $flag('reuse_credentials', 'Zugangsdaten des Basisziels übernehmen', 'Access Key und gespeicherten Secret Key von „' . Html::e((string) $tier['label']) . '“ verwenden – die Felder darunter werden dann ignoriert.', '0') ?>
                    <?= $text('s3_access_key', 'Access Key ID', 'Schlüssel mit Lese-, Schreib-, Auflisten- und Löschrechten auf dem Bucket.', 'text', 'maxlength="128" spellcheck="false" autocomplete="off"') ?>
                    <?= $text('s3_secret_key', 'Secret Access Key', 'Wird verschlüsselt gespeichert.', 'password', 'maxlength="256" autocomplete="new-password"') ?>
                    <?= $flag('s3_path_style', 'Pfad-Adressierung (path-style)', 'Für MinIO, Ceph und die meisten lokalen Objektspeicher erforderlich.', '1') ?>
                    <?= $flag('s3_verify_tls', 'TLS-Zertifikat prüfen', 'Nur für Tests mit selbstsignierten Zertifikaten abschalten.', '1') ?>
                <?php } ?>
            </div>
        </fieldset>
    <?php } ?>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Alle <?= count($tiers) ?> Cold-Tiers erweitern</button>
        <a class="button button--ghost" href="/admin/speicher-ha#ziele">Abbrechen</a>
    </div>
</form>
</div>
