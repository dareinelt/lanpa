<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var bool $enabled */
/** @var bool $jwtConfigured */
/** @var string $publicPath */
/** @var string $euroOfficePath */
/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,mixed>|null $health */
/** @var array<string,mixed> $backup */
/** @var array<string,mixed>|null $tile */
/** @var array<string,mixed>|null $tileValues */
/** @var array<string,string> $tileErrors */
/** @var array<string,string> $tileStatusModes */
/** @var list<string> $tileIcons */
/** @var array<string,mixed> $previewConfig */
/** @var array<string,string> $aiValues */
/** @var array<string,string> $aiErrors */
/** @var bool $aiHasKey */
/** @var bool $aiKeyFromSecret */

$badge = static function (string $status): string {
    return match ($status) {
        'ok' => '<span class="badge badge--ok">in Ordnung</span>',
        'warn', 'degraded' => '<span class="badge badge--warn">Warnung</span>',
        'disabled' => '<span class="badge badge--muted">nicht aktiviert</span>',
        default => '<span class="badge badge--error">Fehler</span>',
    };
};

$stateLabels = [
    'ok' => 'Office ist verfügbar.',
    'degraded' => 'Office ist eingeschränkt verfügbar.',
    'down' => 'Office ist nicht verfügbar.',
    'disabled' => 'Office ist nicht aktiviert.',
];

$field = static function (string $name) use ($errors): string {
    return isset($errors[$name]) ? 'aria-invalid="true" aria-describedby="' . Html::e($name) . '-error"' : '';
};

$fieldError = static function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? '<p class="field__error" id="' . Html::e($name) . '-error">' . Html::e($errors[$name]) . '</p>'
        : '';
};

$formatBytes = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $i === 0 ? 0 : 1, ',', '.') . ' ' . $units[$i];
};

$diagnostics = is_array($health['diagnostics'] ?? null) ? $health['diagnostics'] : [];
$connector = is_array($diagnostics['connector'] ?? null) ? $diagnostics['connector'] : [];
$apps = is_array($diagnostics['apps'] ?? null) ? $diagnostics['apps'] : [];
$previewJson = json_encode($previewConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<?php if (!$enabled) { ?>
    <section class="card">
        <h2 class="card__title">Office ist nicht aktiviert</h2>
        <p class="card__hint">
            Die Office-Dienste (Nextcloud, Euro-Office DocumentServer, PostgreSQL, Redis, Sicherung)
            werden mit einem Befehl eingerichtet und gestartet:
        </p>
        <pre class="code-block"><code>./scripts/office-setup.sh</code></pre>
        <p class="card__hint">
            Mit AD-Anbindung: <code>./scripts/office-setup.sh --with-ad</code>, zusätzlich mit
            automatischer Windows-Anmeldung: <code>./scripts/office-setup.sh --with-ad --with-sso</code>.
            Details siehe <code>docs/office.md</code>.
        </p>
    </section>
<?php } ?>

<section class="card" aria-labelledby="office-status-title">
    <h2 class="card__title" id="office-status-title">Status</h2>
    <?php if ($health === null) { ?>
        <p class="card__hint">Keine Prüfung möglich, solange Office nicht aktiviert ist.</p>
    <?php } else { ?>
        <p>
            <?= $badge((string) $health['state']) ?>
            <?= Html::e($stateLabels[(string) $health['state']] ?? '') ?>
            <span class="card__hint">Stand: <time datetime="<?= Html::e((string) $health['checked_at']) ?>" data-local-time><?= Html::e((string) $health['checked_at']) ?></time></span>
        </p>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Prüfergebnisse der Office-Komponenten</caption>
                <thead>
                <tr>
                    <th scope="col">Komponente</th>
                    <th scope="col">Status</th>
                    <th scope="col">Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ((array) $health['components'] as $component) { ?>
                    <tr>
                        <td><?= Html::e((string) $component['label']) ?></td>
                        <td><?= $badge((string) $component['status']) ?></td>
                        <td><?= Html::e((string) $component['message']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="/admin/office/pruefen" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--primary">Vollständig prüfen</button>
        </form>
        <p class="card__hint">
            Die vollständige Prüfung lässt zusätzlich den Connector in Nextcloud eine Testkonvertierung
            über den DocumentServer ausführen (DocumentServer → Nextcloud, dauert einige Sekunden).
        </p>
    <?php } ?>
</section>

<section class="card" aria-labelledby="office-diag-title">
    <h2 class="card__title" id="office-diag-title">Diagnose</h2>
    <ul class="status-list">
        <li><span>Öffentlicher Pfad Nextcloud</span><code><?= Html::e($publicPath) ?></code></li>
        <li><span>Öffentlicher Pfad DocumentServer</span><code><?= Html::e($euroOfficePath) ?></code></li>
        <li><span>JWT-Secret (Docker-Secret)</span><?= $jwtConfigured ? '<span class="badge badge--ok">vorhanden</span>' : '<span class="badge badge--error">fehlt</span>' ?></li>
        <?php if (($diagnostics['nextcloud_version'] ?? '') !== '') { ?>
            <li><span>Nextcloud</span><span><?= Html::e((string) $diagnostics['nextcloud_version']) ?></span></li>
        <?php } ?>
        <?php if (($diagnostics['eurooffice_version'] ?? '') !== '') { ?>
            <li><span>Euro-Office DocumentServer</span><span><?= Html::e((string) $diagnostics['eurooffice_version']) ?></span></li>
        <?php } ?>
        <?php if ($connector !== []) { ?>
            <li><span>Connector eurooffice</span><span><?= Html::e((string) ($connector['version'] ?? '')) ?></span></li>
            <li><span>DocumentServer-Adresse (Browser)</span><code><?= Html::e((string) ($connector['document_server_url'] ?? '')) ?></code></li>
            <li><span>DocumentServer-Adresse (intern)</span><code><?= Html::e((string) ($connector['document_server_internal_url'] ?? '')) ?></code></li>
            <li><span>Nextcloud-Adresse für DocumentServer</span><code><?= Html::e((string) ($connector['storage_url'] ?? '')) ?></code></li>
            <li><span>Office-Freigabe auf AD-Gruppen</span><span><?= ($connector['groups'] ?? []) === [] ? 'alle Nextcloud-Benutzer' : Html::e(implode(', ', array_map('strval', (array) $connector['groups']))) ?></span></li>
        <?php } ?>
        <?php if ($apps !== []) { ?>
            <li><span>AD-Anbindung (user_ldap)</span><?= !empty($apps['user_ldap']) ? '<span class="badge badge--ok">aktiv</span>' : '<span class="badge badge--muted">inaktiv</span>' ?></li>
            <li><span>Windows-Anmeldung (user_saml, SSO)</span><?= !empty($apps['user_saml']) ? '<span class="badge badge--ok">aktiv</span>' : '<span class="badge badge--muted">inaktiv</span>' ?></li>
            <li><span>KI-Anbindung (integration_openai)</span><?= !empty($apps['integration_openai']) ? '<span class="badge badge--ok">aktiv</span>' : '<span class="badge badge--muted">inaktiv</span>' ?></li>
            <li><span>KI-Assistent (assistant)</span><?= !empty($apps['assistant']) ? '<span class="badge badge--ok">aktiv</span>' : '<span class="badge badge--muted">inaktiv</span>' ?></li>
            <?php if (is_array($diagnostics['ai'] ?? null) && !empty($apps['assistant'])) { ?>
                <li><span>Assistent: Mit Audio arbeiten</span><?= !empty($diagnostics['ai']['audio']) ? '<span class="badge badge--ok">angeboten</span>' : '<span class="badge badge--muted">ausgeblendet</span>' ?></li>
                <li><span>Assistent: Mit Bildern arbeiten</span><?= !empty($diagnostics['ai']['images']) ? '<span class="badge badge--ok">angeboten</span>' : '<span class="badge badge--muted">ausgeblendet</span>' ?></li>
            <?php } ?>
        <?php } ?>
    </ul>
    <p class="card__hint">
        Anmeldung und Rechte in Nextcloud kommen aus dem Active Directory: Zugelassene Gruppen
        (<code>NEXTCLOUD_LDAP_ALLOWED_GROUPS</code>), Office-Freigabe (<code>NEXTCLOUD_OFFICE_GROUPS</code>)
        und Nextcloud-Administratoren (<code>NEXTCLOUD_LDAP_ADMIN_GROUP</code>) werden in der
        <code>.env</code> gepflegt und beim Start übernommen. Die Sichtbarkeit der Kachel im Intranet
        steuern Sie über die Berechtigungen der Navigation.
    </p>
</section>

<section class="card" aria-labelledby="office-config-title">
    <h2 class="card__title" id="office-config-title">Fußzeile und Einstieg</h2>
    <form method="post" action="/admin/office" class="form form--wide" data-office-form>
        <?= Csrf::field() ?>

        <div class="field field--check">
            <input type="checkbox" id="office_footer_enabled" name="office_footer_enabled" value="1" <?= $values['office_footer_enabled'] === '1' ? 'checked' : '' ?>>
            <label for="office_footer_enabled">Intranet-Fußzeile in Nextcloud und Euro-Office anzeigen</label>
        </div>

        <div class="field">
            <label for="office_footer_text">Text der Fußzeile</label>
            <input type="text" id="office_footer_text" name="office_footer_text" maxlength="120"
                   value="<?= Html::e($values['office_footer_text']) ?>"
                   placeholder="Leer = Seitentitel des Intranets" <?= $field('office_footer_text') ?>>
            <?= $fieldError('office_footer_text') ?>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="office_footer_transparency">Transparenz im Ruhezustand (%)</label>
                <input type="number" id="office_footer_transparency" name="office_footer_transparency" min="0" max="90" step="5"
                       value="<?= Html::e($values['office_footer_transparency']) ?>" <?= $field('office_footer_transparency') ?>>
                <p class="field__hint">Bei Mauszeiger, Tastaturfokus oder Antippen wird die Fußzeile deckend.</p>
                <?= $fieldError('office_footer_transparency') ?>
            </div>

            <div class="field">
                <label for="office_footer_home_url">Ziel „Zum Intranet“</label>
                <input type="text" id="office_footer_home_url" name="office_footer_home_url" maxlength="2048"
                       value="<?= Html::e($values['office_footer_home_url']) ?>" placeholder="/" <?= $field('office_footer_home_url') ?>>
                <?= $fieldError('office_footer_home_url') ?>
            </div>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="office_footer_show_logo" name="office_footer_show_logo" value="1" <?= $values['office_footer_show_logo'] === '1' ? 'checked' : '' ?>>
            <label for="office_footer_show_logo">Logo anzeigen (sofern im Design hinterlegt)</label>
        </div>

        <div class="field field--check">
            <input type="checkbox" id="office_footer_show_back" name="office_footer_show_back" value="1" <?= $values['office_footer_show_back'] === '1' ? 'checked' : '' ?>>
            <label for="office_footer_show_back">Schaltfläche „Zurück“ anzeigen</label>
        </div>

        <div class="field">
            <label for="office_direct_access">Direkter Aufruf von <?= Html::e($publicPath) ?> (ohne Intranet)</label>
            <select id="office_direct_access" name="office_direct_access" <?= $field('office_direct_access') ?>>
                <option value="footer" <?= $values['office_direct_access'] === 'footer' ? 'selected' : '' ?>>Erlauben, Fußzeile anzeigen</option>
                <option value="redirect" <?= $values['office_direct_access'] === 'redirect' ? 'selected' : '' ?>>Einmalig über den Intranet-Einstieg leiten</option>
            </select>
            <?= $fieldError('office_direct_access') ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>
</section>

<?php
$aiField = static function (string $name) use ($aiErrors): string {
    return isset($aiErrors[$name]) ? 'aria-invalid="true" aria-describedby="' . Html::e($name) . '-error"' : '';
};
$aiFieldError = static function (string $name) use ($aiErrors): string {
    return isset($aiErrors[$name])
        ? '<p class="field__error" id="' . Html::e($name) . '-error">' . Html::e($aiErrors[$name]) . '</p>'
        : '';
};
?>
<section class="card" id="ki" aria-labelledby="office-ai-title">
    <h2 class="card__title" id="office-ai-title">Lokale KI</h2>
    <p class="card__hint">
        Ein lokaler, OpenAI-kompatibler KI-Endpunkt (z.&nbsp;B. Ollama, vLLM, LocalAI, LM Studio) wird
        allen Benutzern bereitgestellt: in Nextcloud über den Assistenten (Text, Zusammenfassung,
        Übersetzung) und in Euro-Office über das KI-Plugin der Editoren. Anfragen aus den Editoren
        laufen über den DocumentServer; der API-Schlüssel verlässt den Server nicht.
    </p>
    <form method="post" action="/admin/office/ki" class="form form--wide">
        <?= Csrf::field() ?>

        <div class="field field--check">
            <input type="checkbox" id="office_ai_enabled" name="office_ai_enabled" value="1" <?= $aiValues['office_ai_enabled'] === '1' ? 'checked' : '' ?>>
            <label for="office_ai_enabled">KI für alle Benutzer in Nextcloud und Euro-Office bereitstellen</label>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="office_ai_name">Anzeigename</label>
                <input type="text" id="office_ai_name" name="office_ai_name" maxlength="60"
                       value="<?= Html::e($aiValues['office_ai_name']) ?>" placeholder="Lokale KI" <?= $aiField('office_ai_name') ?>>
                <?= $aiFieldError('office_ai_name') ?>
            </div>

            <div class="field">
                <label for="office_ai_timeout">Zeitlimit je Anfrage (Sekunden)</label>
                <input type="number" id="office_ai_timeout" name="office_ai_timeout" min="10" max="900" step="1"
                       value="<?= Html::e($aiValues['office_ai_timeout']) ?>" <?= $aiField('office_ai_timeout') ?>>
                <?= $aiFieldError('office_ai_timeout') ?>
            </div>
        </div>

        <div class="field">
            <label for="office_ai_url">Adresse des Endpunkts (inkl. <code>/v1</code>)</label>
            <input type="url" id="office_ai_url" name="office_ai_url" maxlength="2048"
                   value="<?= Html::e($aiValues['office_ai_url']) ?>" placeholder="http://ki-server:11434/v1" <?= $aiField('office_ai_url') ?>>
            <p class="field__hint">Muss von den Containern Nextcloud und Euro-Office aus erreichbar sein.</p>
            <?= $aiFieldError('office_ai_url') ?>
        </div>

        <div class="field">
            <label for="office_ai_model">Modell</label>
            <input type="text" id="office_ai_model" name="office_ai_model" maxlength="200"
                   value="<?= Html::e($aiValues['office_ai_model']) ?>" placeholder="z. B. llama3.1:8b" <?= $aiField('office_ai_model') ?>>
            <p class="field__hint">Name, wie ihn der Endpunkt unter <code>/v1/models</code> meldet.</p>
            <?= $aiFieldError('office_ai_model') ?>
        </div>

        <div class="field">
            <label for="office_ai_api_key">API-Schlüssel (optional)</label>
            <?php if ($aiKeyFromSecret) { ?>
                <p class="field__hint">Der Schlüssel wird aus dem Secret <code>OFFICE_AI_API_KEY</code> gelesen und kann hier nicht geändert werden.</p>
            <?php } else { ?>
                <input type="password" id="office_ai_api_key" name="office_ai_api_key" maxlength="500" autocomplete="new-password"
                       placeholder="<?= $aiHasKey ? 'gespeichert – leer lassen, um ihn zu behalten' : 'leer = ohne Schlüssel' ?>" <?= $aiField('office_ai_api_key') ?>>
                <?= $aiFieldError('office_ai_api_key') ?>
                <?php if ($aiHasKey) { ?>
                    <div class="field field--check">
                        <input type="checkbox" id="office_ai_api_key_clear" name="office_ai_api_key_clear" value="1">
                        <label for="office_ai_api_key_clear">Gespeicherten Schlüssel entfernen</label>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>

        <fieldset class="fieldset">
            <legend>Funktionen im Nextcloud-Assistenten</legend>
            <p class="field__hint">
                Text, Zusammenfassung und Übersetzung sind immer verfügbar. Audio- und Bildfunktionen nur
                aktivieren, wenn der Endpunkt sie unterstützt; sonst werden die Schaltflächen
                „Mit Audio arbeiten“ und „Mit Bildern arbeiten“ für alle Benutzer ausgeblendet.
            </p>
            <div class="field field--check">
                <input type="checkbox" id="office_ai_audio" name="office_ai_audio" value="1" <?= ($aiValues['office_ai_audio'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label for="office_ai_audio">„Mit Audio arbeiten“ anbieten (Transkription, Sprachausgabe, Audio-Chat)</label>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="office_ai_images" name="office_ai_images" value="1" <?= ($aiValues['office_ai_images'] ?? '0') === '1' ? 'checked' : '' ?>>
                <label for="office_ai_images">„Mit Bildern arbeiten“ anbieten (Bilderzeugung, Bildanalyse, Sticker)</label>
            </div>
        </fieldset>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern und übertragen</button>
        </div>
    </form>
</section>

<section class="card" aria-labelledby="office-preview-title">
    <h2 class="card__title" id="office-preview-title">Vorschau der Fußzeile</h2>
    <p class="card__hint">Die Vorschau verwendet dasselbe Stylesheet und Skript wie Nextcloud. Änderungen im Formular werden sofort angezeigt (gespeichert wird erst mit „Speichern“).</p>
    <div class="office-preview" data-office-preview data-office-config="<?= Html::e((string) $previewJson) ?>">
        <div class="office-preview__app" aria-hidden="true">
            <div class="office-preview__bar"></div>
            <div class="office-preview__doc"></div>
        </div>
    </div>
    <link rel="stylesheet" href="<?= Html::e((string) $previewConfig['assets']['css']) ?>">
    <script src="<?= Html::e((string) $previewConfig['assets']['js']) ?>" defer></script>
</section>

<section class="card" id="apps" aria-labelledby="office-apps-title">
    <h2 class="card__title" id="office-apps-title">Office-Apps und Berechtigungen</h2>
    <p class="card__hint">
        Ein Klick auf die Office-Kachel zeigt die einzelnen Apps: die Euro-Office-Webapps (Text, Tabelle,
        Präsentation, PDF), „Dateien“ (eigene Dateien in Nextcloud) und die „Outlook Web App“.
        Welche Apps ein Benutzer sieht, wird über AD-Gruppen je App oder App-Paket geregelt; nicht
        angemeldete Nutzer erhalten keine Office-Apps. Dort wird auch der Link zur Outlook Web App hinterlegt.
    </p>
    <div class="form__actions">
        <a class="button button--primary" href="/admin/office/apps">Apps und Berechtigungen verwalten</a>
    </div>
</section>

<section class="card" id="kachel" aria-labelledby="office-tile-title">
    <h2 class="card__title" id="office-tile-title">Kachel im Intranet</h2>
    <?php if ($tile !== null && $tileValues !== null) {
        $tv = static fn (string $key): string => (string) ($tileValues[$key] ?? '');
        $tileError = static fn (string $key): string => isset($tileErrors[$key])
            ? '<p class="field__error" id="tile-' . Html::e($key) . '-error">' . Html::e($tileErrors[$key]) . '</p>'
            : '';
        $tileField = static fn (string $key): string => isset($tileErrors[$key])
            ? 'aria-invalid="true" aria-describedby="tile-' . Html::e($key) . '-error"'
            : '';
        $tileColor = $tv('background_color');
        $override = !empty($tileValues['override_background']);
        ?>
        <p class="card__hint">
            Die Kachel „<?= Html::e((string) $tile['title']) ?>“ führt über <code>/office-starten</code> zur Übersicht der Office-Apps.
            Änderungen werden rechts sofort in der Vorschau angezeigt und erst mit „Kachel speichern“ übernommen.
        </p>
        <div class="office-tile-design">
            <form method="post" action="/admin/office/kachel/gestaltung" class="form" data-office-tile-form novalidate>
                <?= Csrf::field() ?>
                <div class="field">
                    <label for="tile_title">Titel</label>
                    <input type="text" id="tile_title" name="title" required maxlength="120" value="<?= Html::e($tv('title')) ?>" <?= $tileField('title') ?>>
                    <?= $tileError('title') ?>
                </div>
                <div class="field">
                    <label for="tile_short_description">Kurzbeschreibung</label>
                    <input type="text" id="tile_short_description" name="short_description" maxlength="255" value="<?= Html::e($tv('short_description')) ?>">
                </div>
                <div class="field">
                    <label for="tile_description">Detailbeschreibung</label>
                    <textarea id="tile_description" name="description" rows="3" maxlength="5000"><?= Html::e($tv('description')) ?></textarea>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="tile_icon">Icon</label>
                        <select id="tile_icon" name="icon" <?= $tileField('icon') ?>>
                            <?php foreach ($tileIcons as $iconKey) { ?>
                                <option value="<?= Html::e($iconKey) ?>" <?= ($tv('icon') !== '' ? $tv('icon') : 'document') === $iconKey ? 'selected' : '' ?>><?= Html::e($iconKey) ?></option>
                            <?php } ?>
                        </select>
                        <?= $tileError('icon') ?>
                    </div>
                    <div class="field">
                        <label for="office_tile_status">Verfügbarkeitsstatus</label>
                        <select id="office_tile_status" name="office_tile_status" <?= $tileField('office_tile_status') ?>>
                            <?php foreach ($tileStatusModes as $modeKey => $modeLabel) { ?>
                                <option value="<?= Html::e($modeKey) ?>" <?= $tv('office_tile_status') === $modeKey ? 'selected' : '' ?>><?= Html::e($modeLabel) ?></option>
                            <?php } ?>
                        </select>
                        <?= $tileError('office_tile_status') ?>
                    </div>
                </div>
                <div class="field field--check">
                    <input type="checkbox" id="tile_override_background" name="override_background" value="1" <?= $override ? 'checked' : '' ?>>
                    <label for="tile_override_background">Eigene Hintergrundfarbe/Deckkraft verwenden</label>
                    <p class="field__hint">Ohne Haken gelten die zentralen Standardwerte unter „Navigation“.</p>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label for="tile_background_color">Hintergrundfarbe</label>
                        <div class="color-input">
                            <input type="color" id="tile_background_color" value="<?= Html::e($tileColor !== '' ? $tileColor : '#1f4e79') ?>" data-color-sync="tile_background_color-text">
                            <input type="text" id="tile_background_color-text" name="background_color" value="<?= Html::e($tileColor) ?>"
                                   pattern="#[0-9a-fA-F]{6}" maxlength="7" aria-label="Hintergrundfarbe als Hex-Wert" data-color-mirror="tile_background_color" <?= $tileField('background_color') ?>>
                        </div>
                        <?= $tileError('background_color') ?>
                    </div>
                    <div class="field">
                        <label for="tile_background_opacity">Deckkraft (in %)</label>
                        <input type="number" id="tile_background_opacity" name="background_opacity" min="0" max="100" step="1" value="<?= Html::e($tv('background_opacity')) ?>" <?= $tileField('background_opacity') ?>>
                        <?= $tileError('background_opacity') ?>
                    </div>
                </div>
                <div class="form__actions">
                    <button type="submit" class="button button--primary">Kachel speichern</button>
                    <a class="button" href="/admin/navigation/berechtigungen?id=<?= (int) $tile['id'] ?>">Kachel-Berechtigungen (Benutzer/AD-Gruppen)</a>
                    <a class="button" href="/admin/navigation/bearbeiten?id=<?= (int) $tile['id'] ?>">Weitere Optionen</a>
                </div>
            </form>
            <div class="office-tile-preview">
                <div class="office-tile-preview__head">
                    <h3 class="office-tile-preview__title">Vorschau</h3>
                    <label class="office-tile-preview__state">
                        Beispielzustand
                        <select data-office-tile-state>
                            <option value="ok">Verfügbar</option>
                            <option value="degraded">Eingeschränkt</option>
                            <option value="down">Nicht verfügbar</option>
                        </select>
                    </label>
                </div>
                <iframe class="office-tile-preview__frame" title="Vorschau der Office-Kachel"
                        src="/admin/office/kachel/vorschau" data-office-tile-preview loading="lazy"></iframe>
                <p class="card__hint">Die Vorschau nutzt das Design der Landingpage. Im Intranet wird der Status live abgefragt.</p>
            </div>
        </div>
    <?php } else { ?>
        <p class="card__hint">Legt eine interne Kachel „Office“ mit dem Ziel <code>/office-starten</code> an.</p>
        <form method="post" action="/admin/office/kachel" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--primary">Office-Kachel anlegen</button>
        </form>
    <?php } ?>
</section>

<section class="card" id="sicherung" aria-labelledby="office-backup-title">
    <h2 class="card__title" id="office-backup-title">Sicherung der Office-Daten</h2>
    <?php if (!$backup['available']) { ?>
        <p class="card__hint">
            Der Sicherungsdienst <code>office-backup</code> meldet sich nicht. Er startet zusammen mit
            den Office-Diensten; alternativ auf dem Server: <code>./scripts/office-backup.sh</code>.
        </p>
    <?php } else { ?>
        <ul class="status-list">
            <li><span>Zustand</span><span>
                <?php if ($backup['state'] === 'running') { ?>
                    <span class="badge badge--warn">läuft</span>
                <?php } elseif ($backup['state'] === 'failed') { ?>
                    <span class="badge badge--error">fehlgeschlagen</span>
                <?php } elseif ($backup['state'] === 'done') { ?>
                    <span class="badge badge--ok">erfolgreich</span>
                <?php } else { ?>
                    <span class="badge badge--muted">bereit</span>
                <?php } ?>
                <?= Html::e((string) $backup['message']) ?>
            </span></li>
            <li><span>Aufbewahrung</span><span><?= (int) $backup['retention'] ?> Sicherungen</span></li>
            <li><span>Verschlüsselung</span><?= $backup['encryption'] ? '<span class="badge badge--ok">aktiv (AES-256)</span>' : '<span class="badge badge--warn">nicht aktiv</span>' ?></li>
            <?php if ($backup['pending']) { ?>
                <li><span>Auftrag</span><span><?= $backup['pending_stale'] ? 'wartet seit über einer Minute – läuft der Dienst?' : 'wird in Kürze bearbeitet' ?></span></li>
            <?php } ?>
        </ul>

        <?php if ($backup['backups'] !== []) { ?>
            <div class="table-wrapper">
                <table class="table">
                    <caption class="visually-hidden">Vorhandene Office-Sicherungen</caption>
                    <thead>
                    <tr><th scope="col">Sicherung</th><th scope="col">Erstellt</th><th scope="col">Größe</th><th scope="col">Verschlüsselt</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($backup['backups'] as $item) { ?>
                        <tr>
                            <td><code><?= Html::e($item['name']) ?></code></td>
                            <td><time datetime="<?= Html::e($item['created']) ?>" data-local-time><?= Html::e($item['created']) ?></time></td>
                            <td><?= Html::e($formatBytes($item['size'])) ?></td>
                            <td><?= $item['encrypted'] ? 'ja' : 'nein' ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <div class="form__actions office-actions">
            <form method="post" action="/admin/office/sicherung" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="button button--primary" <?= $backup['state'] === 'running' ? 'disabled' : '' ?>
                        data-confirm="Jetzt sichern? Nextcloud ist währenddessen für kurze Zeit im Wartungsmodus.">Jetzt sichern</button>
            </form>
            <form method="post" action="/admin/office/sicherung" class="inline-form">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="refresh">
                <button type="submit" class="button">Übersicht aktualisieren</button>
            </form>
        </div>
        <p class="card__hint">
            Wiederherstellen ausschließlich auf dem Server: <code>./scripts/office-restore.sh &lt;Sicherung&gt;</code>.
            Die Sicherungen liegen im Verzeichnis <code>OFFICE_BACKUP_DIR</code> (Standard <code>./backups</code>).
        </p>
    <?php } ?>
</section>

<section class="card" aria-labelledby="office-update-title">
    <h2 class="card__title" id="office-update-title">Aktualisierung</h2>
    <p class="card__hint">
        Aktualisierungen stammen ausschließlich aus den offiziellen Quellen (Container-Images von
        Nextcloud und Euro-Office, Connector aus dem Nextcloud-App-Store). Vor dem Update wird
        automatisch gesichert:
    </p>
    <pre class="code-block"><code>./scripts/office-update.sh --check
./scripts/office-update.sh --eurooffice &lt;Version&gt; --nextcloud &lt;Version&gt;</code></pre>
</section>
