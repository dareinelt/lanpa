<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $primarySecretStates */
/** @var bool $ldapExtensionAvailable */
/** @var list<array<string,mixed>> $sources */
/** @var array<string,mixed>|null $dialog */
/** @var array<string,mixed> $ssoAgent */
$ssoInstanceBadge = static function (?array $instance): array {
    if ($instance === null) {
        return ['badge--muted', 'nicht gemeldet'];
    }
    return match ((string) $instance['state']) {
        'running' => ($instance['health'] ?? '') === 'unhealthy'
            ? ['badge--error', 'läuft (nicht gesund)']
            : ['badge--ok', 'läuft'],
        'missing' => ['badge--error', 'Instanz fehlt'],
        'exited', 'dead' => ['badge--error', 'gestoppt'],
        'created', 'restarting', 'paused' => ['badge--warn', 'startet noch'],
        default => ['badge--muted', (string) $instance['state']],
    };
};
$ssoInstanceHints = static function (array $instance): array {
    $hints = [];
    if ($instance['enabled'] === false) {
        $hints[] = 'Windows-Anmeldung inaktiv';
    }
    if ((string) $instance['domain'] !== '') {
        $hints[] = 'Domäne ' . $instance['domain'];
    }
    if ($instance['active']) {
        $hints[] = $instance['kerberos'] ? 'Kerberos aktiv' : 'nur NTLM – Kerberos nicht verfügbar';
    }
    if ((string) $instance['join'] === 'failed') {
        $hints[] = 'Domänenbeitritt fehlgeschlagen';
    }
    if ((string) $instance['ntp'] === 'failed') {
        $hints[] = 'Uhrzeit nicht geprüft oder weicht ab (Kerberos braucht unter 5 Minuten)';
    } elseif ((string) $instance['ntp'] === 'warn') {
        $hints[] = 'Uhrzeit nicht abgeglichen, Kerberos läuft (Abweichung unter 5 Minuten)';
    }
    return $hints;
};
$ssoAgentBusy = $ssoAgent['running'] || ($ssoAgent['pending'] && !$ssoAgent['pending_stale']);
$ssoInstances = array_values(array_filter($sources, static fn (array $source): bool => $source['sso'] !== null));
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
                            <?php if ($ssoAgent['available']) {
                                [$instanceClass, $instanceLabel] = $ssoInstanceBadge($source['instance']); ?>
                                <div class="table__hint">
                                    <code><?= Html::e((string) $source['service']) ?></code>:
                                    <span class="badge <?= $instanceClass ?>"><?= Html::e($instanceLabel) ?></span>
                                </div>
                                <?php foreach ($source['instance'] === null ? [] : $ssoInstanceHints($source['instance']) as $hint) { ?>
                                    <div class="table__hint"><?= Html::e($hint) ?></div>
                                <?php } ?>
                            <?php } ?>
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

<section class="card" id="windows-anmeldung" aria-labelledby="sso-agent-title">
    <h2 class="card__title" id="sso-agent-title">Windows-Anmeldung (Kerberos/NTLM)</h2>
    <p class="card__hint">
        Jede Domäne ohne Vertrauensstellung erhält eine eigene Anmelde-Instanz. „Anwenden“ erzeugt sie aus den
        Einstellungen der Identitätsquellen, baut sie neu, startet sie neu und meldet den Zustand jeder Instanz zurück.
        Ein Serverzugriff ist nicht nötig.
    </p>

    <?php if (!$ssoAgent['available']) { ?>
        <p class="card__hint">
            Der Dienst <code>sso-agent</code> meldet sich nicht. Ohne ihn bleibt der Weg auf dem Server:
            <code>./scripts/sso-domains.sh</code>, danach <code>docker compose up -d --build</code> und
            <code>docker compose restart auth</code>.
        </p>
    <?php } else { ?>
        <ul class="status-list">
            <li><span>Zustand</span><span>
                <?php if ($ssoAgent['running'] || $ssoAgent['pending']) { ?>
                    <span class="badge badge--warn">läuft</span>
                <?php } elseif ($ssoAgent['state'] === 'failed') { ?>
                    <span class="badge badge--error">fehlgeschlagen</span>
                <?php } else { ?>
                    <span class="badge badge--muted">bereit</span>
                <?php } ?>
                <?= Html::e((string) $ssoAgent['message']) ?>
            </span></li>
            <?php if ($ssoAgent['pending']) { ?>
                <li><span>Auftrag</span><span><?= $ssoAgent['pending_stale'] ? 'wartet seit über zwei Minuten – läuft der Dienst?' : 'wird in Kürze bearbeitet' ?></span></li>
            <?php } ?>
            <?php if ((string) $ssoAgent['compose_file'] !== '') { ?>
                <li><span>Compose-Dateien</span><span><code><?= Html::e((string) $ssoAgent['compose_file']) ?></code></span></li>
            <?php } ?>
            <?php if ((string) $ssoAgent['updated_at'] !== '') { ?>
                <li><span>Zuletzt gemeldet</span><span><time datetime="<?= Html::e((string) $ssoAgent['updated_at']) ?>" data-local-time><?= Html::e((string) $ssoAgent['updated_at']) ?></time></span></li>
            <?php } ?>
        </ul>

        <div class="table-wrapper">
            <table class="table">
                <caption class="visually-hidden">Zustand der Anmelde-Instanzen</caption>
                <thead>
                <tr>
                    <th scope="col">Instanz</th>
                    <th scope="col">Domäne</th>
                    <th scope="col">Zustand</th>
                    <th scope="col">Protokoll</th>
                    <th scope="col">Hinweise</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($ssoInstances === []) { ?>
                    <tr>
                        <td colspan="5"><span class="table__hint">Keine Windows-Anmeldung eingerichtet. Domäne in einer Identitätsquelle eintragen und die Windows-Anmeldung aktivieren.</span></td>
                    </tr>
                <?php } ?>
                <?php foreach ($ssoInstances as $source) {
                    [$instanceClass, $instanceLabel] = $ssoInstanceBadge($source['instance']);
                    $instance = $source['instance'];
                    $hints = $instance === null ? [] : $ssoInstanceHints($instance);
                    $notes = $instance === null ? [] : $instance['notes']; ?>
                    <tr>
                        <td>
                            <code><?= Html::e((string) $source['service']) ?></code>
                            <div class="table__hint"><?= Html::e((string) $source['label']) ?><?= $source['active'] ? '' : ' (Quelle inaktiv)' ?></div>
                        </td>
                        <td><?= Html::e($instance !== null && (string) $instance['domain'] !== '' ? (string) $instance['domain'] : (string) $source['sso']) ?></td>
                        <td>
                            <span class="badge <?= $instanceClass ?>"><?= Html::e($instanceLabel) ?></span>
                            <?php foreach ($hints as $hint) { ?>
                                <div class="table__hint"><?= Html::e($hint) ?></div>
                            <?php } ?>
                        </td>
                        <td>
                            <form method="post" action="/admin/ad/anmeldeinstanzen/protokoll" class="inline-form" data-ad-logs>
                                <?= Csrf::field() ?>
                                <input type="hidden" name="service" value="<?= Html::e((string) $source['service']) ?>">
                                <button type="submit" class="button button--ghost"
                                        aria-label="Protokoll der Anmelde-Instanz <?= Html::e((string) $source['service']) ?> anzeigen">Protokoll anzeigen</button>
                            </form>
                            <div class="table__hint">
                                <a href="/admin/ad/anmeldeinstanzen/protokoll/download?service=<?= rawurlencode((string) $source['service']) ?>">Letzte 48 Stunden herunterladen</a>
                            </div>
                        </td>
                        <td>
                            <?php if ($notes === []) { ?>
                                <span class="table__hint">–</span>
                            <?php } else { ?>
                                <ul class="status-list">
                                    <?php foreach ($notes as $note) { ?>
                                        <li><span><?= Html::e((string) $note) ?></span></li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="form__actions">
            <form method="post" action="/admin/ad/anmeldeinstanzen/anwenden" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="button button--primary" <?= $ssoAgentBusy ? 'disabled' : '' ?>
                        data-confirm="Windows-Anmeldung jetzt anwenden? Die Anmelde-Instanzen werden neu gebaut und neu gestartet; die Anmeldung ist dabei kurz unterbrochen.">Windows-Anmeldung anwenden</button>
            </form>
        </div>
        <p class="card__hint">
            Nach jeder Änderung an Domäne, Domänencontrollern, Zeitservern oder Zugangsdaten einer Identitätsquelle
            anwenden. Eine neue Domäne wird dabei automatisch als eigene Instanz angelegt; wird die Windows-Anmeldung
            entfernt, verschwindet die Instanz wieder.
        </p>

        <?php if ($ssoInstances !== []) {
            require __DIR__ . '/ldap/logs-dialog.php';
        } ?>
    <?php } ?>
</section>

<?php if ($dialog !== null) {
    require __DIR__ . '/ldap/source.php';
} ?>
