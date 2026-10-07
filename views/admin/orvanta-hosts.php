<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array{hosts:list<array<string,mixed>>,sessions:list<array<string,mixed>>,totals:array<string,int>,generated_at:string} $overview */
/** @var bool $tablesMissing */
/** @var bool $orvantaEnabled */
/** @var bool $orvantaDemo */
/** @var string $primaryHost */
/** @var string $ewsUrl */
/** @var string $testMailbox */
/** @var string $serviceUser */
/** @var int $maxHosts */
/** @var int $sessionTtl */
/** @var int $refreshInterval */
/** @var string $base */

$statusBadge = ['online' => 'badge--ok', 'offline' => 'badge--warn', 'maintenance' => 'badge--muted', 'unknown' => 'badge--muted'];
$totals = $overview['totals'];
$hosts = $overview['hosts'];
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/orvanta">Orvanta – Mail &amp; Kalender</a>
    <a class="button button--ghost" href="/admin/office">Status &amp; Diagnose</a>
</div>

<p class="card__hint">
    Exchange-Hosts einer <strong>Database Availability Group (DAG)</strong> stellen dasselbe Postfach auf mehreren Servern bereit.
    Orvanta hält jede Sitzung auf ihrem Host und verteilt neue Sitzungen in dieser Reihenfolge:
    <strong>1.</strong> Host, der am längsten keine Sitzung mehr erhalten hat (Fair-use),
    <strong>2.</strong> Host mit den wenigsten verbundenen Sitzungen,
    <strong>3.</strong> Host mit der geringsten mittleren Antwortzeit.
    Fällt ein Host aus, wird die Sitzung ohne Zutun des Benutzers auf einen anderen Host umgeleitet und dort fortgesetzt.
    Der Server aus <a href="/admin/office/orvanta">Office → Orvanta</a> ist immer der primäre Host; hier lassen sich
    weitere Mitglieder derselben DAG ergänzen.
</p>
<?php if ($tablesMissing) { ?>
    <p class="flash flash--error">Die Tabellen der DAG-Hosts fehlen. Bitte die Datenbankmigrationen ausführen (<code>php scripts/migrate.php</code>, Migration 043).</p>
<?php } ?>
<?php if (!$orvantaEnabled) { ?>
    <p class="flash flash--error">Orvanta ist nicht aktiviert. Bitte zuerst unter <a href="/admin/office/orvanta">Office → Orvanta</a> die Exchange-Anbindung einrichten und testen – eine DAG lässt sich nur zu einer vorhandenen Konfiguration ergänzen.</p>
<?php } elseif ($orvantaDemo) { ?>
    <p class="flash flash--error">Der Demo-Modus (Exchange-Server „demo“) verwendet keine echten Hosts. Die Verteilung greift erst mit einem echten Exchange-Server.</p>
<?php } ?>

<section class="card" aria-labelledby="orv-hosts-title" data-orvanta-hosts
         data-refresh-url="<?= Html::e($base . '/daten') ?>" data-refresh-interval="<?= (int) $refreshInterval ?>">
    <div class="card__head">
        <h2 class="card__title" id="orv-hosts-title">Hosts der DAG</h2>
        <button type="button" class="button button--ghost" data-hosts-refresh>Jetzt aktualisieren</button>
    </div>
    <p class="card__hint">
        <span data-hosts-summary><?= (int) $totals['hosts'] ?> Host(s), davon <?= (int) $totals['active'] ?> aktiv und <?= (int) $totals['online'] ?> online · <?= (int) $totals['sessions'] ?> verbundene Sitzung(en)</span> ·
        Stand: <span data-hosts-generated><?= Html::e($overview['generated_at']) ?></span> ·
        Sitzungen ohne Aktivität seit <?= (int) $sessionTtl ?> Sekunden gelten als beendet.
        Die Kacheln aktualisieren sich automatisch, die Sitzungsliste beim Neuladen der Seite.
    </p>

    <?php if ($hosts === []) { ?>
        <p class="card__hint">Es ist noch kein Exchange-Host eingetragen.</p>
    <?php } else { ?>
        <div class="host-grid">
            <?php foreach ($hosts as $host) { ?>
                <article class="host-tile" data-host-id="<?= (int) $host['id'] ?>" data-status="<?= Html::e((string) $host['status']) ?>">
                    <header class="host-tile__head">
                        <h3 class="host-tile__name"><?= Html::e((string) $host['host']) ?></h3>
                        <span class="badge <?= Html::e($statusBadge[(string) $host['status']] ?? 'badge--muted') ?>" data-field="status"><?= Html::e((string) $host['status_label']) ?></span>
                    </header>
                    <p class="host-tile__meta">
                        <?php if ((int) $host['is_primary'] === 1) { ?><span class="badge">Primär</span><?php } ?>
                        <code class="host-tile__url"><?= Html::e((string) $host['url']) ?></code>
                    </p>
                    <ul class="status-list">
                        <li><span>Latenz (Ø)</span><strong data-field="latency"><?= Html::e((string) $host['latency_label']) ?></strong></li>
                        <li><span>Letzte Antwort</span><strong data-field="last_latency"><?= Html::e((string) $host['last_latency_label']) ?></strong></li>
                        <li><span>Verbundene Sitzungen</span><strong data-field="sessions"><?= (int) $host['sessions'] ?></strong></li>
                        <li><span>Letzte Prüfung</span><strong data-field="check"><?= Html::e((string) $host['last_check_label']) ?></strong></li>
                        <li><span>Letzte Sitzung</span><strong data-field="last_session"><?= Html::e((string) $host['last_session_label']) ?></strong></li>
                    </ul>
                    <p class="host-tile__error" data-field="error"<?= (string) $host['last_error'] === '' ? ' hidden' : '' ?>><?= Html::e((string) $host['last_error']) ?></p>
                    <div class="row-actions">
                        <form method="post" action="<?= Html::e($base) ?>/pruefen" class="inline-form">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int) $host['id'] ?>">
                            <button class="button button--ghost">Verbindung testen</button>
                        </form>
                        <?php if ((int) $host['active'] === 1) { ?>
                            <form method="post" action="<?= Html::e($base) ?>/status" class="inline-form"
                                  data-confirm="Host „<?= Html::e((string) $host['host']) ?>“ in Wartung nehmen? Laufende Sitzungen werden auf die übrigen Hosts umgeleitet.">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $host['id'] ?>">
                                <button class="button button--ghost">Wartung</button>
                            </form>
                        <?php } else { ?>
                            <form method="post" action="<?= Html::e($base) ?>/status" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $host['id'] ?>">
                                <button class="button button--ghost">Wieder aktivieren</button>
                            </form>
                        <?php } ?>
                        <?php if ((int) $host['is_primary'] !== 1) { ?>
                            <form method="post" action="<?= Html::e($base) ?>/loeschen" class="inline-form"
                                  data-confirm="Host „<?= Html::e((string) $host['host']) ?>“ aus der DAG entfernen? Laufende Sitzungen werden beim nächsten Aufruf auf die übrigen Hosts verteilt.">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $host['id'] ?>">
                                <button class="button button--danger">Entfernen</button>
                            </form>
                        <?php } ?>
                    </div>
                </article>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="toolbar">
        <form method="post" action="<?= Html::e($base) ?>/pruefen" class="inline-form">
            <?= Csrf::field() ?>
            <button class="button button--ghost"<?= $hosts === [] ? ' disabled' : '' ?>>Alle Hosts prüfen</button>
        </form>
    </div>

    <form method="post" action="<?= Html::e($base) ?>/pruefpostfach" class="form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="test_mailbox">Prüfpostfach für den Verbindungstest</label>
            <input type="email" id="test_mailbox" name="test_mailbox" maxlength="190"
                   value="<?= Html::e($testMailbox) ?>" placeholder="z. B. pruefung@firma.de">
            <p class="field__hint">
                „Verbindung testen“ und „Alle Hosts prüfen“ öffnen den Posteingang dieses Postfachs im Namen des Dienstkontos
                (Impersonation). Leer = Posteingang des Dienstkontos<?= $serviceUser !== '' ? ' (' . Html::e($serviceUser) . ')' : '' ?> –
                hat es kein eigenes Postfach, melden alle Hosts „Gestört“.
            </p>
        </div>
        <div class="form__actions">
            <button class="button button--ghost">Prüfpostfach speichern</button>
        </div>
    </form>
</section>

<section class="card" aria-labelledby="orv-hosts-add">
    <h2 class="card__title" id="orv-hosts-add">Hosts derselben Exchange-DAG ergänzen</h2>
    <p class="card__hint">
        Primärer Host: <strong><?= Html::e($primaryHost !== '' ? $primaryHost : 'nicht eingetragen') ?></strong><?php if ($ewsUrl !== '') { ?>
            (<code><?= Html::e($ewsUrl) ?></code>)<?php } ?>.
        Bitte je Zeile einen Hostnamen der DAG angeben (optional gefolgt von einer abweichenden EWS-Adresse).
        Es lassen sich höchstens <?= (int) $maxHosts ?> Hosts verwalten. Falsche Angaben führen dazu, dass Sitzungen auf einem
        Server ohne Postfach-Replikat landen – die Verteilung erfolgt ausschließlich auf Grundlage Ihrer Bestätigung.
    </p>
    <form method="post" action="<?= Html::e($base) ?>" class="form"
          data-confirm="Handelt es sich bei allen angegebenen Hosts wirklich um Mitglieder derselben Exchange-DAG und um Replikate desselben Postfachs? Bei falschen Angaben können Benutzer ihre Mails und Kalender nicht öffnen.">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="dag_hosts">Hostnamen (ein Host je Zeile)</label>
            <textarea id="dag_hosts" name="hosts" rows="4" maxlength="2000"
                      placeholder="mail02.firma.local&#10;mail03.firma.local&#10;mail04.firma.local https://mail04.firma.local/EWS/Exchange.asmx"<?= $orvantaEnabled && !$orvantaDemo ? '' : ' disabled' ?>></textarea>
            <p class="field__hint">Der Hostname wird als EWS-Adresse <code>https://&lt;host&gt;/EWS/Exchange.asmx</code> verwendet, sofern keine eigene Adresse angegeben ist.</p>
        </div>
        <div class="field field--check">
            <input type="checkbox" id="dag_confirmed" name="dag_confirmed" value="1" required>
            <label for="dag_confirmed">Ich bestätige, dass die angegebenen Hosts Mitglieder derselben Exchange-DAG sind und dasselbe Postfach bereitstellen.</label>
        </div>
        <div class="form__actions">
            <button class="button button--primary"<?= $orvantaEnabled && !$orvantaDemo ? '' : ' disabled' ?>>Hosts ergänzen</button>
        </div>
    </form>
</section>

<section class="card" aria-labelledby="orv-sessions-title">
    <h2 class="card__title" id="orv-sessions-title">Verbundene Sitzungen</h2>
    <?php if ($overview['sessions'] === []) { ?>
        <p class="card__hint">Zurzeit ist keine Orvanta-Sitzung einem Host zugeordnet.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Benutzer</th>
                        <th scope="col">Host</th>
                        <th scope="col">Aufrufe</th>
                        <th scope="col">Umleitungen</th>
                        <th scope="col">Begonnen</th>
                        <th scope="col">Letzte Aktivität</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($overview['sessions'] as $session) { ?>
                    <tr>
                        <td><?= Html::e((string) $session['user'] !== '' ? (string) $session['user'] : 'unbekannt') ?></td>
                        <td><code><?= Html::e((string) $session['host']) ?></code></td>
                        <td><?= (int) $session['requests'] ?></td>
                        <td><?= (int) $session['failovers'] > 0 ? '<span class="badge badge--warn">' . (int) $session['failovers'] . '</span>' : '0' ?></td>
                        <td><?= Html::e((string) $session['started_label']) ?></td>
                        <td><?= Html::e((string) $session['last_seen_label']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
