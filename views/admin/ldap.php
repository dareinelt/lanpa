<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $primarySecretStates */
/** @var bool $ldapExtensionAvailable */
/** @var list<array<string,mixed>> $sources */
/** @var array<string,mixed>|null $dialog */
?>
<?php if (!$ldapExtensionAvailable) { ?>
    <p class="flash flash--error">Die PHP-Erweiterung <code>ldap</code> ist nicht installiert. Eine Synchronisation ist nicht möglich.</p>
<?php } ?>

<?php if (($primarySecretStates['ldap_bind_password'] ?? 'missing') === 'invalid' || ($primarySecretStates['sso_join_password'] ?? 'missing') === 'invalid') { ?>
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
                                <a class="button button--ghost" href="/admin/ad/hauptquelle">Bearbeiten</a>
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

<?php if ($dialog !== null) {
    require __DIR__ . '/ldap/source.php';
} ?>
