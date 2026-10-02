<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Office\NetworkDriveService;
use App\Support\Dates;
use App\Support\Html;

/** @var bool $officeEnabled */
/** @var bool $ssoEnabled */
/** @var bool $enabled */
/** @var list<string> $excluded */
/** @var array{users:int,drives:int,passed:int} $summary */
/** @var list<array{user_uid:string,display_name:string,drive_letter:string,unc_path:string,domain:string,computer_name:string,reported_at:string,excluded:bool}> $rows */
/** @var array{ok:bool,message:string,at:string,fingerprint:string,in_sync:bool}|null $lastPush */
/** @var string $reportUrl */
/** @var array<string,string> $errors */
/** @var array<string,string> $values */

$excludedValue = $values['excluded'] ?? NetworkDriveService::formatLetters($excluded);
$enabledValue = isset($values['enabled']) ? $values['enabled'] === '1' : $enabled;

$users = [];
foreach ($rows as $row) {
    $users[$row['user_uid']][] = $row;
}
?>
<p class="card__hint">
    Die auf den Windows-Clients gemappten Netzlaufwerke (z. B. <code>H:</code> → <code>\\server\freigabe</code>) lassen
    sich in Nextcloud unter „Dateien“ öffnen. Ein Anmeldeskript meldet die Laufwerke bei jeder Windows-Anmeldung an das
    Intranet; Nextcloud bindet sie für Benutzer ein, die in „Dateien“ → Einstellungen die Option
    <strong>„Netzlaufwerke anzeigen“</strong> aktiviert haben. Beim ersten Öffnen fragt Nextcloud einmalig nach dem
    Windows-Kennwort; die Rechte auf den Freigaben bleiben unverändert. Netzlaufwerke zählen <strong>nicht</strong> zum
    <a href="/admin/speicherplatz">Speicherplatz-Kontingent</a>.
</p>
<?php if (!$officeEnabled) { ?>
    <p class="flash flash--info">Office ist nicht aktiviert (<code>OFFICE_ENABLED</code>). Gemeldete Laufwerke werden gespeichert und übertragen, sobald Office aktiv ist.</p>
<?php } ?>
<?php if (!$ssoEnabled) { ?>
    <p class="flash flash--info">Die Windows-Anmeldung (<code>SSO_ENABLED</code>) ist nicht aktiv. Ohne sie können Clients keine Laufwerke melden.</p>
<?php } ?>

<div class="cards">
    <section class="card">
        <h2 class="card__title">Benutzer</h2>
        <p class="metric"><?= (int) $summary['users'] ?></p>
        <p class="card__hint">mit gemeldeten Netzlaufwerken</p>
    </section>
    <section class="card">
        <h2 class="card__title">Laufwerke</h2>
        <p class="metric"><?= (int) $summary['passed'] ?></p>
        <p class="card__hint">werden weitergereicht (<?= (int) $summary['drives'] ?> gemeldet)</p>
    </section>
    <section class="card">
        <h2 class="card__title">Nie weitergereicht</h2>
        <p class="metric"><?= $excluded === [] ? '–' : Html::e(NetworkDriveService::formatLetters($excluded)) ?></p>
        <p class="card__hint">ausgeschlossene Laufwerke</p>
    </section>
</div>

<section class="card" id="einstellungen" aria-labelledby="settings-title">
    <h2 class="card__title" id="settings-title">Einstellungen und Übertragung an Nextcloud</h2>
    <form method="post" action="/admin/netzlaufwerke/einstellungen" class="form">
        <?= Csrf::field() ?>
        <div class="field field--check">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" id="enabled" name="enabled" value="1" <?= $enabledValue ? 'checked' : '' ?>>
            <label for="enabled">Netzlaufwerke an Nextcloud weiterreichen</label>
            <p class="field__hint">Ohne Haken entfernt Nextcloud alle eingebundenen Netzlaufwerke; die Option „Netzlaufwerke anzeigen“ erscheint dann nicht.</p>
        </div>
        <div class="field">
            <label for="excluded">Diese Netzlaufwerke niemals weiterreichen</label>
            <input type="text" id="excluded" name="excluded" maxlength="200" value="<?= Html::e($excludedValue) ?>"
                   <?= isset($errors['excluded']) ? 'aria-invalid="true" aria-describedby="excluded-error"' : 'aria-describedby="excluded-hint"' ?>>
            <p class="field__hint" id="excluded-hint">Laufwerksbuchstaben, getrennt durch Komma oder Leerzeichen, z. B. „B:/, G:/“ (Vorgabe). Leer = alle Laufwerke weiterreichen.</p>
            <?php if (isset($errors['excluded'])) { ?>
                <p class="field__error" id="excluded-error"><?= Html::e($errors['excluded']) ?></p>
            <?php } ?>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Speichern</button>
        </div>
    </form>

    <h3>Stand in Nextcloud</h3>
    <?php if ($lastPush === null) { ?>
        <p class="card__hint">Noch nicht übertragen.</p>
    <?php } else { ?>
        <p>
            <span class="badge <?= $lastPush['ok'] ? ($lastPush['in_sync'] ? 'badge--ok' : 'badge--warn') : 'badge--error' ?>">
                <?= $lastPush['ok'] ? ($lastPush['in_sync'] ? 'aktuell' : 'Änderungen ausstehend') : 'fehlgeschlagen' ?>
            </span>
            <span class="table__hint">Letzte Übertragung: <?= Html::e(Dates::formatDateTime($lastPush['at'])) ?></span>
        </p>
        <p class="card__hint"><?= Html::e($lastPush['message']) ?></p>
    <?php } ?>
    <p class="card__hint">Geänderte Meldungen werden sofort übertragen; die Office-Gesundheitsprüfung gleicht zusätzlich laufend ab.</p>
    <form method="post" action="/admin/netzlaufwerke/uebertragen" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="button button--ghost"<?= $officeEnabled ? '' : ' disabled' ?>>Jetzt an Nextcloud übertragen</button>
    </form>
</section>

<section class="card" id="skript" aria-labelledby="script-title">
    <h2 class="card__title" id="script-title">Anmeldeskript für die Clients</h2>
    <p class="card__hint">
        Der Browser darf die Laufwerkszuordnungen eines Clients nicht auslesen. Deshalb meldet ein PowerShell-Skript im
        Benutzerkontext die gemappten Netzlaufwerke per Windows-Anmeldung (ohne Kennwort) an
        <code><?= Html::e($reportUrl) ?></code>. Jede Meldung ersetzt den bisherigen Stand des Benutzers.
    </p>
    <ol class="card__hint">
        <li>Skript herunterladen und z. B. unter <code>\\&lt;domäne&gt;\NETLOGON\netzlaufwerke-melden.ps1</code> ablegen.</li>
        <li>Gruppenrichtlinie: <em>Benutzerkonfiguration → Richtlinien → Windows-Einstellungen → Skripts → Anmelden</em>
            → Registerkarte „PowerShell-Skripts“ → Skript hinzufügen. Alternativ als geplante Aufgabe „Bei Anmeldung“ im Benutzerkontext.</li>
        <li>Das Skript wartet kurz, bis die Laufwerke per Gruppenrichtlinie verbunden sind, und protokolliert nach
            <code>%LOCALAPPDATA%\Intranet\netzlaufwerke.log</code>.</li>
    </ol>
    <div class="form__actions">
        <a class="button button--primary" href="/admin/netzlaufwerke/skript">Anmeldeskript herunterladen</a>
    </div>
</section>

<section class="card" id="gemeldet" aria-labelledby="reported-title">
    <h2 class="card__title" id="reported-title">Gemeldete Netzlaufwerke</h2>
    <?php if ($users === []) { ?>
        <p class="empty-state">Bisher hat kein Client Netzlaufwerke gemeldet.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Gemeldete Netzlaufwerke je Benutzer</caption>
                <thead>
                <tr>
                    <th scope="col">Benutzer</th>
                    <th scope="col">Laufwerke</th>
                    <th scope="col">Gemeldet</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $uid => $drives) {
                    $first = $drives[0]; ?>
                    <tr>
                        <td>
                            <?= Html::e($first['display_name'] !== '' ? $first['display_name'] : (string) $uid) ?>
                            <div class="table__hint"><?= Html::e((string) $uid) ?></div>
                        </td>
                        <td>
                            <ul class="plain-list">
                                <?php foreach ($drives as $drive) { ?>
                                    <li>
                                        <strong><?= Html::e($drive['drive_letter']) ?>:</strong>
                                        <code><?= Html::e($drive['unc_path']) ?></code>
                                        <?php if ($drive['excluded']) { ?>
                                            <span class="badge badge--warn">nie weitergereicht</span>
                                        <?php } ?>
                                    </li>
                                <?php } ?>
                            </ul>
                        </td>
                        <td>
                            <span class="table__hint">
                                <?= Html::e(Dates::formatDateTime($first['reported_at'])) ?>
                                <?= $first['computer_name'] !== '' ? ' · ' . Html::e($first['computer_name']) : '' ?>
                                <?= $first['domain'] !== '' ? ' · ' . Html::e($first['domain']) : '' ?>
                            </span>
                        </td>
                        <td>
                            <form method="post" action="/admin/netzlaufwerke/benutzer/entfernen" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="uid" value="<?= Html::e((string) $uid) ?>">
                                <button type="submit" class="button button--danger"
                                        data-confirm="Gemeldete Netzlaufwerke von „<?= Html::e((string) $uid) ?>“ entfernen? Bei der nächsten Windows-Anmeldung meldet der Client sie erneut.">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
