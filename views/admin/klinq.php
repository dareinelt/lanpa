<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var string $section Unterseite: status|access|ai|tile */
/** @var bool $enabled */
/** @var string $publicUrl */
/** @var string $publicHost */
/** @var string $upstream */
/** @var bool $secretConfigured */
/** @var array{state:string,available:bool,checked_at:string,latency_ms:int,message:string,url:string}|null $health */
/** @var array<string,array{label:string,role:string,permission:string,area:string}> $groupKeys */
/** @var array<string,string> $roleLabels */
/** @var array<string,string> $areas */
/** @var string $defaultRole */
/** @var bool $quickReportOpen */
/** @var array<string,list<string>> $groups */
/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,mixed>|null $tile */
/** @var list<string> $tileIcons */
/** @var array<string,mixed> $exampleAccess */
/** @var string $entryPath */
/** @var string $reportPath */
/** @var array<string,array{0:string,1:string,2:string}> $pages */
/** @var array<string,string> $aiValues */
/** @var array<string,string> $aiErrors */
/** @var bool $aiConfigured */
/** @var bool $aiActive */
/** @var bool $aiKeyFromSecret */
/** @var bool $aiHasKey */
/** @var string $assetVersion */

$badge = static function (string $state): string {
    return match ($state) {
        'ok' => '<span class="badge badge--ok">erreichbar</span>',
        'disabled' => '<span class="badge badge--muted">nicht aktiviert</span>',
        default => '<span class="badge badge--error">nicht erreichbar</span>',
    };
};

$field = static function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? 'aria-invalid="true" aria-describedby="' . Html::e($name) . '-error"'
        : '';
};

$fieldError = static function (string $name) use ($errors): string {
    return isset($errors[$name])
        ? '<p class="field__error" id="' . Html::e($name) . '-error">' . Html::e($errors[$name]) . '</p>'
        : '';
};

$groupValue = static function (string $key) use ($values, $groups): string {
    if (isset($values[$key])) {
        return (string) $values[$key];
    }

    return implode(', ', $groups[$key] ?? []);
};

$roleValue = isset($values['klinq_default_role']) ? (string) $values['klinq_default_role'] : $defaultRole;
$quickReportChecked = isset($values['klinq_quick_report_open'])
    ? $values['klinq_quick_report_open'] === '1'
    : $quickReportOpen;

$tileValues = $tile !== null ? array_merge($tile, $values) : [];
$tileValue = static fn (string $key): string => (string) ($tileValues[$key] ?? '');
?>
<?php if ($section === 'status') { ?>
<section class="card" aria-labelledby="klinq-connection-title">
    <h2 class="card__title" id="klinq-connection-title">Verbindung zu KLINQ</h2>
    <?php if (!$enabled) { ?>
        <p class="card__hint">
            KLINQ ist nicht aktiviert. Starten Sie die Container mit
            <code>docker compose --profile klinq up -d --build</code> und setzen Sie
            <code>KLINQ_ENABLED=true</code> in der <code>.env</code>. Der Einstieg erfolgt danach
            über den auth-Container, der die Windows-Anmeldung und SSO übernimmt.
        </p>
    <?php } elseif ($health !== null) { ?>
        <ul class="status-list">
            <li><span>Erreichbarkeit</span><span><?= $badge((string) $health['state']) ?> <?= Html::e((string) $health['message']) ?></span></li>
            <li><span>Antwortzeit</span><span><?= (int) $health['latency_ms'] ?> ms</span></li>
            <li><span>Prüfadresse</span><span><code><?= Html::e((string) $health['url']) ?></code></span></li>
            <li><span>Stand</span><span><time datetime="<?= Html::e((string) $health['checked_at']) ?>" data-local-time><?= Html::e((string) $health['checked_at']) ?></time></span></li>
        </ul>
    <?php } ?>
    <form method="post" action="/admin/klinq/pruefen" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="button button--primary">Erreichbarkeit prüfen</button>
    </form>
</section>

<section class="card" aria-labelledby="klinq-address-title">
    <h2 class="card__title" id="klinq-address-title">Adressen und Anmeldung</h2>
    <ul class="status-list">
        <li><span>Öffentliche Adresse</span><span><code><?= Html::e($publicUrl) ?></code></span></li>
        <?php if ($publicHost !== '') { ?>
            <li><span>Eigener Hostname</span><span><code><?= Html::e($publicHost) ?></code></span></li>
        <?php } ?>
        <li><span>Einstieg im Intranet</span><span><code><?= Html::e($entryPath) ?></code></span></li>
        <li><span>Störmeldung IT</span><span><code><?= Html::e($reportPath) ?></code></span></li>
        <li><span>KLINQ-Container</span><span><code><?= Html::e($upstream) ?></code></span></li>
        <li><span>Anmelde-Token (SSO)</span><?= $secretConfigured
            ? '<span class="badge badge--ok">vorhanden</span>'
            : '<span class="badge badge--error">fehlt</span>' ?></li>
        <li><span>Störmeldung ohne Gruppenzuordnung</span><?= $quickReportChecked
            ? '<span class="badge badge--ok">offen</span>'
            : '<span class="badge badge--muted">gesperrt</span>' ?></li>
        <li><span>Rolle ohne Gruppenzuordnung</span><span><?= Html::e($roleLabels[$roleValue] ?? $roleValue) ?></span></li>
    </ul>
    <?php if (!$secretConfigured) { ?>
        <p class="card__hint">
            Ohne Anmelde-Token ist kein Einstieg möglich. Setzen Sie <code>KLINQ_SSO_SECRET</code>
            (mindestens 32 Zeichen) in der <code>.env</code> und starten Sie den auth-Container neu.
        </p>
    <?php } ?>
</section>

<section class="card" aria-labelledby="klinq-paths-title">
    <h2 class="card__title" id="klinq-paths-title">Weitere Schritte</h2>
    <p class="card__hint">
        Die Anmeldung übernimmt der auth-Container: Er stellt KLINQ hinter den Reverse-Proxy,
        ermittelt die Windows-Anmeldung und übergibt Identität, AD-Gruppen und Kontaktdaten
        signiert an KLINQ. Die AD-Join- und SSO-Funktionen von KLINQ selbst werden dafür nicht
        benötigt.
    </p>
    <div class="form__actions">
        <a class="button" href="<?= Html::e($pages['access'][0]) ?>">Berechtigungen festlegen</a>
        <a class="button" href="<?= Html::e($pages['tile'][0]) ?>">Kachel im Intranet</a>
    </div>
</section>
<?php } ?>

<?php if ($section === 'access') { ?>
<section class="card" aria-labelledby="klinq-access-title">
    <h2 class="card__title" id="klinq-access-title">Berechtigungen über AD-Gruppen</h2>
    <p class="card__hint">
        Mitglieder der hier hinterlegten Active-Directory-Gruppen erhalten die jeweilige Rolle bzw.
        Berechtigung in KLINQ. Verschachtelte Mitgliedschaften zählen mit. Die höchste zutreffende
        Rolle gewinnt; ohne Treffer gilt die Standardrolle.
    </p>
    <p class="card__hint">
        Die Störmeldung IT (<code><?= Html::e($reportPath) ?></code>) ist bewusst für <strong>jede</strong>
        über SSO angemeldete Person geöffnet – ohne Gruppenzuordnung. Die Angaben zu „Gemeldet von“,
        Rechner und Kontaktdaten ermittelt der auth-Container und übergibt sie an KLINQ.
    </p>
    <form method="post" action="<?= Html::e($pages['access'][0]) ?>" class="form form--wide">
        <?= Csrf::field() ?>
        <?php foreach ($groupKeys as $key => $definition) {
            $inputId = str_replace('_', '-', $key);
            $scope = $definition['role'] !== ''
                ? 'Rolle: ' . ($roleLabels[$definition['role']] ?? $definition['role'])
                : 'Berechtigung: ' . ($areas[$definition['area']] ?? $definition['area']);
            ?>
            <div class="field group-suggest">
                <label for="<?= Html::e($inputId) ?>"><?= Html::e($definition['label']) ?></label>
                <input type="text" id="<?= Html::e($inputId) ?>" name="<?= Html::e($key) ?>" maxlength="2000"
                       value="<?= Html::e($groupValue($key)) ?>" autocomplete="off" spellcheck="false"
                       aria-describedby="<?= Html::e($inputId) ?>-hint" <?= $field($key) ?>
                       data-group-suggest="/admin/ad/gruppen">
                <p class="field__hint" id="<?= Html::e($inputId) ?>-hint">
                    <?= Html::e($scope) ?>. Gruppennamen kommagetrennt, ohne Domänenpräfix.
                </p>
                <?= $fieldError($key) ?>
            </div>
        <?php } ?>
        <div class="field">
            <label for="klinq_default_role">Standardrolle ohne Gruppenzuordnung</label>
            <select id="klinq_default_role" name="klinq_default_role" <?= $field('klinq_default_role') ?>>
                <?php foreach ($roleLabels as $roleKey => $roleLabel) { ?>
                    <option value="<?= Html::e($roleKey) ?>" <?= $roleKey === $roleValue ? 'selected' : '' ?>><?= Html::e($roleLabel) ?></option>
                <?php } ?>
            </select>
            <p class="field__hint">
                Gilt für alle angemeldeten Personen ohne passende Gruppe. „Nur Lesen“ empfiehlt sich,
                damit ausschließlich die Störmeldung nutzbar ist.
            </p>
            <?= $fieldError('klinq_default_role') ?>
        </div>
        <div class="field field--check">
            <input type="checkbox" id="klinq_quick_report_open" name="klinq_quick_report_open" value="1" <?= $quickReportChecked ? 'checked' : '' ?>>
            <label for="klinq_quick_report_open">Störmeldung IT für alle angemeldeten Personen öffnen</label>
            <p class="field__hint">
                Ohne Haken ist die Meldeseite gesperrt. Die Meldung selbst landet als Ticket im
                Help Desk von KLINQ.
            </p>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Berechtigungen speichern</button>
        </div>
    </form>
</section>

<section class="card" aria-labelledby="klinq-access-example-title">
    <h2 class="card__title" id="klinq-access-example-title">Ergebnis ohne Gruppenzuordnung</h2>
    <ul class="status-list">
        <li><span>Rolle in KLINQ</span><span><?= Html::e((string) $exampleAccess['role_label']) ?></span></li>
        <li><span>Bereiche</span><span><?= $exampleAccess['area_labels'] === []
            ? '<span class="badge badge--muted">keine</span>'
            : Html::e(implode(', ', $exampleAccess['area_labels'])) ?></span></li>
        <li><span>Berechtigungsgruppen</span><span><?= $exampleAccess['permission_groups'] === []
            ? '<span class="badge badge--muted">keine</span>'
            : Html::e(implode(', ', $exampleAccess['permission_groups'])) ?></span></li>
        <li><span>Störmeldung IT</span><span><?= $quickReportChecked
            ? '<span class="badge badge--ok">nutzbar</span>'
            : '<span class="badge badge--muted">gesperrt</span>' ?></span></li>
    </ul>
    <p class="card__hint">
        Die Rollen und Berechtigungsgruppen werden bei jedem Einstieg neu aus den AD-Gruppen
        abgeleitet und signiert an KLINQ übergeben.
    </p>
</section>
<script src="/assets/js/admin-group-autocomplete.js?v=<?= Html::e((string) ($assetVersion ?? '1')) ?>" defer></script>
<?php } ?>

<?php if ($section === 'ai') { ?>
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
<section class="card" id="ki" aria-labelledby="klinq-ai-title">
    <h2 class="card__title" id="klinq-ai-title">KI-Endpunkt für KLINQ</h2>
    <p class="card__hint">
        KLINQ nutzt die KI im Help Desk (Textvorschläge, Zusammenfassungen, Auswertung von Tickets).
        Angesprochen wird ein OpenAI-kompatibler Endpunkt (z.&nbsp;B. Ollama, vLLM, LocalAI, LM Studio),
        angegeben mit Basisadresse inklusive <code>/v1</code>.
    </p>
    <p class="card__hint">
        Die Einstellungen werden ausschließlich hier gepflegt. KLINQ ruft sie über die interne
        Schnittstelle <code>/internal/klinq/ki</code> ab; der Bereich „KI“ in KLINQ zeigt sie nur an.
        Der API-Schlüssel bleibt damit zentral im Intranet.
    </p>

    <ul class="status-list">
        <li><span>Zustand</span><span><?= $aiActive
            ? '<span class="badge badge--ok">aktiv</span>'
            : ($aiConfigured ? '<span class="badge badge--muted">deaktiviert</span>' : '<span class="badge badge--muted">nicht konfiguriert</span>') ?></span></li>
        <li><span>Adresse</span><span><?= $aiValues['klinq_ai_url'] !== '' ? Html::e($aiValues['klinq_ai_url']) : '<span class="badge badge--muted">nicht gesetzt</span>' ?></span></li>
        <li><span>Modell</span><span><?= $aiValues['klinq_ai_model'] !== '' ? Html::e($aiValues['klinq_ai_model']) : '<span class="badge badge--muted">nicht gesetzt</span>' ?></span></li>
        <li><span>API-Schlüssel</span><span><?= $aiKeyFromSecret
            ? 'aus Secret KLINQ_AI_API_KEY'
            : ($aiHasKey ? 'hinterlegt' : 'ohne Schlüssel') ?></span></li>
    </ul>

    <form method="post" action="<?= Html::e($pages['ai'][0]) ?>" class="form form--wide"
          data-ai-form data-ai-test-endpoint="/admin/klinq/ki/testen">
        <?= Csrf::field() ?>

        <div class="field field--check">
            <input type="checkbox" id="klinq_ai_enabled" name="klinq_ai_enabled" value="1" <?= $aiValues['klinq_ai_enabled'] === '1' ? 'checked' : '' ?>>
            <label for="klinq_ai_enabled">KI-Unterstützung in KLINQ bereitstellen</label>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="klinq_ai_name">Anzeigename</label>
                <input type="text" id="klinq_ai_name" name="klinq_ai_name" maxlength="60"
                       value="<?= Html::e($aiValues['klinq_ai_name']) ?>" placeholder="KLINQ-KI" <?= $aiField('klinq_ai_name') ?>>
                <?= $aiFieldError('klinq_ai_name') ?>
            </div>

            <div class="field">
                <label for="klinq_ai_timeout">Zeitlimit je Anfrage (Sekunden)</label>
                <input type="number" id="klinq_ai_timeout" name="klinq_ai_timeout" min="5" max="900" step="1"
                       value="<?= Html::e($aiValues['klinq_ai_timeout']) ?>" <?= $aiField('klinq_ai_timeout') ?>>
                <?= $aiFieldError('klinq_ai_timeout') ?>
            </div>
        </div>

        <div class="field">
            <label for="klinq_ai_url">Adresse des Endpunkts (inkl. <code>/v1</code>)</label>
            <input type="url" id="klinq_ai_url" name="klinq_ai_url" maxlength="2048"
                   value="<?= Html::e($aiValues['klinq_ai_url']) ?>" placeholder="http://ki-server:11434/v1" <?= $aiField('klinq_ai_url') ?>>
            <p class="field__hint">Muss vom KLINQ-Container aus erreichbar sein.</p>
            <?= $aiFieldError('klinq_ai_url') ?>
        </div>

        <div class="field">
            <label for="klinq_ai_model">Modell</label>
            <input type="text" id="klinq_ai_model" name="klinq_ai_model" maxlength="200"
                   value="<?= Html::e($aiValues['klinq_ai_model']) ?>" placeholder="z. B. llama3.1:8b" <?= $aiField('klinq_ai_model') ?>>
            <p class="field__hint">Name, wie ihn der Endpunkt unter <code>/v1/models</code> meldet.</p>
            <?= $aiFieldError('klinq_ai_model') ?>
        </div>

        <div class="field">
            <label for="klinq_ai_api_key">API-Schlüssel (optional)</label>
            <?php if ($aiKeyFromSecret) { ?>
                <p class="field__hint">Der Schlüssel wird aus dem Secret <code>KLINQ_AI_API_KEY</code> gelesen und kann hier nicht geändert werden.</p>
            <?php } else { ?>
                <input type="password" id="klinq_ai_api_key" name="klinq_ai_api_key" maxlength="500" autocomplete="new-password"
                       placeholder="<?= $aiHasKey ? 'gespeichert – leer lassen, um ihn zu behalten' : 'leer = ohne Schlüssel' ?>" <?= $aiField('klinq_ai_api_key') ?>>
                <?= $aiFieldError('klinq_ai_api_key') ?>
                <?php if ($aiHasKey) { ?>
                    <div class="field field--check">
                        <input type="checkbox" id="klinq_ai_api_key_clear" name="klinq_ai_api_key_clear" value="1">
                        <label for="klinq_ai_api_key_clear">Gespeicherten Schlüssel entfernen</label>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>

        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
            <button type="button" class="button button--ghost" data-ai-test hidden>Verbindung testen</button>
        </div>
    </form>

    <dialog id="ai-test-dialog" class="ai-test" aria-labelledby="ai-test-title">
        <h2 id="ai-test-title">KI-Verbindung testen</h2>
        <p class="card__hint">Getestet werden die aktuellen Formularwerte – auch wenn sie noch nicht gespeichert sind.</p>
        <ol class="ai-test__steps" id="ai-test-steps" aria-live="polite"></ol>
        <div class="ai-test__chat" id="ai-test-chat" hidden>
            <p class="ai-test__label">Frage</p>
            <p class="ai-test__bubble ai-test__bubble--question"><?= Html::e(\App\Services\Klinq\KlinqAiService::TEST_PROMPT) ?></p>
            <p class="ai-test__label">Antwort <span id="ai-test-model"></span></p>
            <div class="ai-test__bubble ai-test__bubble--answer" id="ai-test-answer" aria-live="polite"></div>
            <p class="ai-test__meta" id="ai-test-meta"></p>
        </div>
        <div class="form__actions">
            <button type="button" class="button button--primary" id="ai-test-again">Erneut testen</button>
            <button type="button" class="button button--ghost" id="ai-test-close">Schließen</button>
        </div>
    </dialog>
</section>
<script src="/assets/js/admin-office.js?v=<?= Html::e((string) ($assetVersion ?? '1')) ?>" defer></script>
<?php } ?>

<?php if ($section === 'tile') { ?>
<section class="card" aria-labelledby="klinq-tile-title">
    <h2 class="card__title" id="klinq-tile-title">Kachel im Intranet</h2>
    <?php if ($tile !== null) {
        $tileError = static fn (string $key): string => isset($errors[$key])
            ? '<p class="field__error" id="tile-' . Html::e($key) . '-error">' . Html::e($errors[$key]) . '</p>'
            : '';
        $tileField = static fn (string $key): string => isset($errors[$key])
            ? 'aria-invalid="true" aria-describedby="tile-' . Html::e($key) . '-error"'
            : '';
        $tileColor = $tileValue('background_color');
        ?>
        <p class="card__hint">
            Die Kachel „<?= Html::e((string) $tileValue('title')) ?>“ führt über
            <code><?= Html::e($entryPath) ?></code> zu KLINQ. Wer die Kachel sieht, steuern Sie über die
            Kachel-Berechtigungen (AD-Gruppen).
        </p>
        <form method="post" action="/admin/klinq/kachel/gestaltung" class="form">
            <?= Csrf::field() ?>
            <div class="field">
                <label for="tile_title">Titel</label>
                <input type="text" id="tile_title" name="title" required maxlength="120" value="<?= Html::e($tileValue('title')) ?>" <?= $tileField('title') ?>>
                <?= $tileError('title') ?>
            </div>
            <div class="field">
                <label for="tile_short_description">Kurzbeschreibung</label>
                <input type="text" id="tile_short_description" name="short_description" maxlength="255" value="<?= Html::e($tileValue('short_description')) ?>" <?= $tileField('short_description') ?>>
                <?= $tileError('short_description') ?>
            </div>
            <div class="field">
                <label for="tile_description">Detailbeschreibung</label>
                <textarea id="tile_description" name="description" rows="3" maxlength="5000" <?= $tileField('description') ?>><?= Html::e($tileValue('description')) ?></textarea>
                <?= $tileError('description') ?>
            </div>
            <div class="field">
                <label for="tile_icon">Icon</label>
                <select id="tile_icon" name="icon" <?= $tileField('icon') ?>>
                    <?php foreach ($tileIcons as $iconKey) { ?>
                        <option value="<?= Html::e($iconKey) ?>" <?= ($tileValue('icon') !== '' ? $tileValue('icon') : 'wrench') === $iconKey ? 'selected' : '' ?>><?= Html::e($iconKey) ?></option>
                    <?php } ?>
                </select>
                <?= $tileError('icon') ?>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="tile_override_background" name="override_background" value="1" <?= !empty($tileValues['override_background']) ? 'checked' : '' ?>>
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
                    <input type="number" id="tile_background_opacity" name="background_opacity" min="0" max="100" step="1" value="<?= Html::e($tileValue('background_opacity')) ?>" <?= $tileField('background_opacity') ?>>
                    <?= $tileError('background_opacity') ?>
                </div>
            </div>
            <div class="form__actions">
                <button type="submit" class="button button--primary">Kachel speichern</button>
                <a class="button" href="/admin/navigation/berechtigungen?id=<?= (int) $tile['id'] ?>">Kachel-Berechtigungen (Benutzer/AD-Gruppen)</a>
                <a class="button" href="/admin/navigation/bearbeiten?id=<?= (int) $tile['id'] ?>">Weitere Optionen</a>
            </div>
        </form>
    <?php } else { ?>
        <p class="card__hint">
            Legt eine interne Kachel „KLINQ“ mit dem Ziel <code><?= Html::e($entryPath) ?></code> an.
            Anschließend legen Sie unter „Berechtigungen“ fest, welche AD-Gruppen die Kachel sehen.
        </p>
        <form method="post" action="/admin/klinq/kachel" class="inline-form">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--primary">KLINQ-Kachel anlegen</button>
        </form>
    <?php } ?>
</section>

<section class="card" aria-labelledby="klinq-report-nav-title">
    <h2 class="card__title" id="klinq-report-nav-title">Störmeldung im Navigationspunkt</h2>
    <p class="card__hint">
        Der Navigationspunkt „Störmeldung IT“ kann direkt auf die Meldeseite von KLINQ zeigen. Legen Sie
        dazu unter <a href="/admin/navigation">Navigation</a> ein internes Element an und wählen Sie im
        Feld „Ziel“ die Option <strong>KLINQ-Meldeseite</strong> (<code><?= Html::e($reportPath) ?></code>).
        Die Seite ist für alle angemeldeten Personen ohne Gruppenzuordnung erreichbar.
    </p>
    <div class="form__actions">
        <a class="button" href="/admin/navigation">Zur Navigation</a>
        <a class="button" href="<?= Html::e($pages['access'][0]) ?>">Berechtigungen</a>
    </div>
</section>
<?php } ?>
