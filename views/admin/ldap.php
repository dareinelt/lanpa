<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Dates;
use App\Support\Html;

/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,string> $attributeKeys */
/** @var array<string,string> $secretStates */
/** @var bool $ldapExtensionAvailable */
/** @var list<array<string,mixed>> $syncRuns */
/** @var list<array<string,mixed>> $sources */
?>
<?php if (!$ldapExtensionAvailable) { ?>
    <p class="flash flash--error">Die PHP-Erweiterung <code>ldap</code> ist nicht installiert. Eine Synchronisation ist nicht möglich.</p>
<?php } ?>

<?php if (($secretStates['ldap_bind_password'] ?? 'missing') === 'invalid' || ($secretStates['sso_join_password'] ?? 'missing') === 'invalid') { ?>
    <p class="flash flash--error">
        Mindestens ein gespeichertes Passwort der Hauptquelle kann nicht entschlüsselt werden (z. B. nach einer
        Wiederherstellung auf einem anderen Server). Bitte die Passwörter neu eingeben.
    </p>
<?php } ?>

<section class="card">
    <h2 class="card__title">Identitätsquellen</h2>
    <p class="card__hint">
        Jedes Active Directory (Zentrale, Zweigstellen, Tochtergesellschaften, …) wird als eigene Identitätsquelle
        synchronisiert. Fällt eine Quelle aus, bleiben deren letzte Daten erhalten; die übrigen Quellen werden trotzdem aktualisiert.
        Zugangsdaten werden hier gepflegt und ausschließlich verschlüsselt gespeichert; sie werden nie angezeigt.
    </p>

    <div class="table-wrapper">
        <table class="table">
            <caption class="visually-hidden">Liste der Identitätsquellen</caption>
            <thead>
            <tr>
                <th scope="col">Beschriftung</th>
                <th scope="col">Server</th>
                <th scope="col">Status</th>
                <th scope="col">Aktionen</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sources as $source) {
                $sourceId = (int) $source['id']; ?>
                <tr>
                    <td>
                        <strong><?= Html::e((string) $source['label']) ?></strong>
                        <div class="table__hint"><?= $source['primary'] ? 'Hauptquelle' : 'Kennung <code>' . Html::e((string) $source['key']) . '</code>' ?></div>
                        <?php if ((string) $source['base_dn'] !== '') { ?><div class="table__hint"><?= Html::e((string) $source['base_dn']) ?></div><?php } ?>
                    </td>
                    <td>
                        <?php if ($source['hosts'] === []) { ?>
                            <span class="table__hint">nicht konfiguriert</span>
                        <?php } else { ?>
                            <?= implode('<br>', array_map(static fn (string $host): string => Html::e($host), $source['hosts'])) ?>
                        <?php } ?>
                    </td>
                    <td>
                        <span class="badge <?= $source['active'] && $source['configured'] ? 'badge--ok' : 'badge--muted' ?>">
                            <?= !$source['active'] ? 'inaktiv' : ($source['configured'] ? 'aktiv' : 'unvollständig') ?>
                        </span>
                        <div class="table__hint"><?= (int) $source['users'] ?> aktive Einträge</div>
                        <?php if ($source['password_state'] === 'invalid') { ?>
                            <div class="table__hint">Passwort nicht entschlüsselbar – bitte neu eingeben</div>
                        <?php } elseif ($source['password_state'] !== 'set') { ?>
                            <div class="table__hint">Ohne Passwort des Dienstkontos</div>
                        <?php } ?>
                        <?php if ($source['sso'] !== null) { ?>
                            <div class="table__hint">Windows-Anmeldung: <?= Html::e((string) $source['sso']) ?></div>
                        <?php } ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <?php if ($source['primary']) { ?>
                                <a class="button button--ghost" href="#hauptquelle">Bearbeiten</a>
                            <?php } else { ?>
                                <a class="button button--ghost" href="/admin/ad/quellen/bearbeiten?id=<?= $sourceId ?>">Bearbeiten</a>
                            <?php } ?>
                            <form method="post" action="/admin/ad/quellen/testen" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= $sourceId ?>">
                                <button type="submit" class="button button--ghost" <?= $ldapExtensionAvailable && $source['configured'] ? '' : 'disabled' ?>
                                        aria-label="Verbindung zu <?= Html::e((string) $source['label']) ?> testen">Verbindung testen</button>
                            </form>
                            <?php if (!$source['primary']) { ?>
                                <form method="post" action="/admin/ad/quellen/loeschen" class="inline-form"
                                      data-confirm="Soll die Identitätsquelle wirklich gelöscht werden? Ihre Telefonbucheinträge und Gruppen werden ausgeblendet.">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $sourceId ?>">
                                    <button type="submit" class="button button--danger">Löschen</button>
                                </form>
                            <?php } ?>
                        </div>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="toolbar">
        <a class="button button--primary" href="/admin/ad/quellen/neu">Identitätsquelle hinzufügen</a>
    </div>
</section>

<h2 id="hauptquelle">Hauptquelle</h2>
<form method="post" action="/admin/ad" class="form form--wide">
    <?= Csrf::field() ?>

    <?php require __DIR__ . '/ldap/fields.php'; ?>

    <?php $serviceName = 'auth'; require __DIR__ . '/ldap/sso.php'; ?>

    <fieldset class="fieldset">
        <legend>Synchronisation</legend>
        <div class="field">
            <label for="ldap_sync_interval">Synchronisationsintervall (Sekunden)</label>
            <input type="number" id="ldap_sync_interval" name="ldap_sync_interval" min="60" max="86400"
                   value="<?= Html::e($values['ldap_sync_interval']) ?>">
            <p class="field__hint">Wird vom Synchronisationsdienst (Container <code>sync</code>) ausgewertet.</p>
            <?php if (isset($errors['ldap_sync_interval'])) { ?><p class="field__error"><?= Html::e($errors['ldap_sync_interval']) ?></p><?php } ?>
        </div>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Einstellungen speichern</button>
    </div>
</form>

<section class="card">
    <h2 class="card__title">Synchronisation</h2>
    <p class="card__hint">
        Aktive Telefonbucheinträge: <?= (int) $phonebookCount ?>
        <?php if (($groupCount ?? null) !== null) { ?> · AD-Gruppen für die Rechtevergabe: <?= (int) $groupCount ?><?php } ?>
    </p>

    <form method="post" action="/admin/ad/sync" class="inline-form">
        <?= Csrf::field() ?>
        <button type="submit" class="button button--primary" <?= $ldapExtensionAvailable ? '' : 'disabled' ?>>
            Manuelle Synchronisation starten
        </button>
    </form>

    <?php if ($syncRuns === []) { ?>
        <p class="card__hint">Es wurde noch keine Synchronisation ausgeführt.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col">Start</th>
                    <th scope="col">Ende</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aktualisiert</th>
                    <th scope="col">Deaktiviert</th>
                    <th scope="col">Meldung</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($syncRuns as $run) { ?>
                    <tr>
                        <td><?= Html::e(Dates::formatDateTime((string) $run['started_at'])) ?></td>
                        <td><?= $run['finished_at'] === null ? '–' : Html::e(Dates::formatDateTime((string) $run['finished_at'])) ?></td>
                        <td>
                            <span class="badge <?= (string) $run['status'] === 'success' ? 'badge--ok' : 'badge--warn' ?>">
                                <?= Html::e((string) $run['status']) ?>
                            </span>
                        </td>
                        <td><?= (int) $run['processed'] ?></td>
                        <td><?= (int) $run['deactivated'] ?></td>
                        <td class="table__hint"><?= Html::e((string) ($run['message'] ?? '')) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
