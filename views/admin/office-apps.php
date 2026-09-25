<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Services\Office\OfficeAppService;
use App\Support\Html;

/** @var bool $enabled */
/** @var bool $ssoEnabled */
/** @var list<array<string,mixed>> $apps */
/** @var array<string,list<string>> $directGroups */
/** @var array<string,list<string>> $effectiveGroups */
/** @var list<array{id:int,name:string,description:string,apps:list<string>,groups:list<string>}> $packages */
/** @var string $owaUrl */
/** @var array<string,string> $errors */

$appTitles = [];
foreach ($apps as $app) {
    $appTitles[(string) $app['key']] = (string) $app['title'];
}
$owaKey = OfficeAppService::OWA_SETTING;
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office">Zurück zu Office</a>
</div>

<p class="card__hint">
    Nach einem Klick auf die Office-Kachel sehen angemeldete Benutzer die für sie freigegebenen Apps:
    die Euro-Office-Webapps (Text, Tabelle, Präsentation, PDF), ihre Dateien in Nextcloud und – sofern
    hinterlegt – die Outlook Web App. Freigaben erfolgen ausschließlich über AD-Gruppen, entweder für
    einzelne Apps oder über App-Pakete. <strong>Eine App ohne Freigabe sieht niemand</strong>;
    nicht angemeldete Nutzer erhalten nie Office-Apps und sehen die Office-Kachel nicht.
</p>
<?php if (!$enabled) { ?>
    <p class="flash flash--info">Office ist nicht aktiviert (<code>OFFICE_ENABLED</code>). Die Einstellungen werden gespeichert, aber erst mit aktivem Office wirksam.</p>
<?php } ?>
<?php if (!$ssoEnabled) { ?>
    <p class="flash flash--info">Die Windows-Anmeldung (<code>SSO_ENABLED</code>) ist nicht aktiv. Ohne Anmeldung werden keine Office-Apps angezeigt.</p>
<?php } ?>

<section class="card" id="owa" aria-labelledby="owa-title">
    <h2 class="card__title" id="owa-title">Outlook Web App</h2>
    <form method="post" action="/admin/office/apps/owa" class="form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="<?= Html::e($owaKey) ?>">Link zur Outlook Web App (OWA)</label>
            <input type="url" id="<?= Html::e($owaKey) ?>" name="<?= Html::e($owaKey) ?>" maxlength="2048"
                   placeholder="https://mail.example.com/owa/" value="<?= Html::e($owaUrl) ?>"
                   aria-describedby="<?= Html::e($owaKey) ?>-hint"
                   <?= isset($errors[$owaKey]) ? 'aria-invalid="true"' : '' ?>>
            <p class="field__hint" id="<?= Html::e($owaKey) ?>-hint">
                Die Kachel „Outlook Web App“ erscheint unter „Office“, sobald ein Link hinterlegt und die App
                freigegeben ist. Sie öffnet sich in einem neuen Tab. Leer lassen, um die Kachel zu entfernen.
            </p>
            <?php if (isset($errors[$owaKey])) { ?>
                <p class="field__error"><?= Html::e($errors[$owaKey]) ?></p>
            <?php } ?>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Link speichern</button>
        </div>
    </form>
</section>

<section class="card" id="freigaben" aria-labelledby="apps-title">
    <h2 class="card__title" id="apps-title">Freigaben je App</h2>
    <p class="card__hint">
        AD-Gruppen kommagetrennt eintragen. Vorschläge stammen aus der AD-Synchronisation
        (Pfeiltasten und Eingabetaste zur Auswahl). Freigaben über Pakete werden zusätzlich angezeigt.
    </p>
    <form method="post" action="/admin/office/apps/freigaben" class="form">
        <?= Csrf::field() ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Office-Apps und ihre AD-Gruppen</caption>
                <thead>
                <tr>
                    <th scope="col">App</th>
                    <th scope="col">AD-Gruppen (direkt)</th>
                    <th scope="col">Wirksam für</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($apps as $app) {
                    $key = (string) $app['key'];
                    $inputId = 'groups-' . $key;
                    $effective = $effectiveGroups[$key] ?? [];
                    ?>
                    <tr>
                        <th scope="row">
                            <?= Html::e((string) $app['title']) ?>
                            <div class="table__hint">
                                <?php if ((string) $app['webapp'] !== '') { ?>
                                    Euro-Office-Webapp <code><?= Html::e((string) $app['webapp']) ?></code>
                                <?php } elseif ($key === 'files') { ?>
                                    Eigene Dateien in Nextcloud
                                <?php } else { ?>
                                    Externer Link
                                <?php } ?>
                            </div>
                            <?php if (!$app['configured']) { ?>
                                <span class="badge badge--muted">kein Link hinterlegt</span>
                            <?php } ?>
                        </th>
                        <td>
                            <div class="field group-suggest group-suggest--cell">
                                <label class="visually-hidden" for="<?= Html::e($inputId) ?>">AD-Gruppen für <?= Html::e((string) $app['title']) ?></label>
                                <input type="text" id="<?= Html::e($inputId) ?>" name="groups[<?= Html::e($key) ?>]" maxlength="2000"
                                       value="<?= Html::e(implode(', ', $directGroups[$key] ?? [])) ?>"
                                       autocomplete="off" spellcheck="false" data-group-suggest="/admin/ad/gruppen">
                            </div>
                        </td>
                        <td>
                            <?php if ($effective === []) { ?>
                                <span class="badge badge--warn">niemand</span>
                            <?php } else { ?>
                                <?= Html::e(implode(', ', $effective)) ?>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Freigaben speichern</button>
        </div>
    </form>
</section>

<section class="card" id="pakete" aria-labelledby="packages-title">
    <h2 class="card__title" id="packages-title">App-Pakete</h2>
    <p class="card__hint">
        Ein Paket bündelt mehrere Apps (z. B. „Office Basis“ mit Text, Tabelle und Dateien) und gibt sie
        gemeinsam für AD-Gruppen frei.
    </p>
    <?php if ($packages === []) { ?>
        <p class="empty-state">Es sind noch keine App-Pakete angelegt.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">App-Pakete</caption>
                <thead>
                <tr>
                    <th scope="col">Paket</th>
                    <th scope="col">Apps</th>
                    <th scope="col">AD-Gruppen</th>
                    <th scope="col">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $package) {
                    $names = array_map(static fn (string $key): string => $appTitles[$key] ?? $key, $package['apps']);
                    ?>
                    <tr>
                        <td>
                            <?= Html::e($package['name']) ?>
                            <?php if ($package['description'] !== '') { ?>
                                <div class="table__hint"><?= Html::e($package['description']) ?></div>
                            <?php } ?>
                        </td>
                        <td><?= Html::e(implode(', ', $names)) ?></td>
                        <td>
                            <?php if ($package['groups'] === []) { ?>
                                <span class="badge badge--warn">keine</span>
                            <?php } else { ?>
                                <?= Html::e(implode(', ', $package['groups'])) ?>
                            <?php } ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="/admin/office/apps/paket?id=<?= (int) $package['id'] ?>">Bearbeiten</a>
                                <form method="post" action="/admin/office/apps/paket/loeschen" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $package['id'] ?>">
                                    <button type="submit" class="button button--danger"
                                            data-confirm="Paket „<?= Html::e($package['name']) ?>“ löschen? Die Freigaben über dieses Paket entfallen.">Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
    <div class="form__actions">
        <a class="button button--primary" href="/admin/office/apps/paket">Neues Paket</a>
    </div>
</section>
