<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array{sources:list<array<string,mixed>>,servers:list<array<string,mixed>>,selected:?array<string,mixed>,server:?array<string,mixed>,mailboxes:list<array<string,mixed>>,mappings:list<array<string,mixed>>} $overview */
/** @var array<string,mixed>|null $dialog */
/** @var bool $tablesMissing */
/** @var bool $orvantaEnabled */
/** @var list<int> $smtpPorts */
/** @var list<int> $imapPorts */

$base = '/admin/office/mail-proxy';
$selected = $overview['selected'];
$server = $overview['server'];
$sourceName = static function (?array $source): string {
    if ($source === null) {
        return 'Unbekannte Quelle (gelöscht)';
    }
    $label = (string) $source['label'];
    $domain = (string) $source['domain'];

    return $domain !== '' && strcasecmp($domain, $label) !== 0 ? $label . ' (' . $domain . ')' : $label;
};
$configured = [];
foreach ($overview['servers'] as $row) {
    $configured[(int) $row['identity_source_id']] = true;
}
$freeSources = array_values(array_filter($overview['sources'], static fn (array $source): bool => !isset($configured[(int) $source['id']])));
$smtpSecurityLabels = ['starttls' => 'STARTTLS', 'tls' => 'TLS direkt', 'none' => 'ohne Verschlüsselung'];
$imapSecurityLabels = ['tls' => 'TLS direkt', 'starttls' => 'STARTTLS'];
$sourceQuery = $selected !== null ? (int) $selected['id'] : 0;
$selectedFree = $selected !== null && !isset($configured[(int) $selected['id']]);
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/orvanta">Orvanta – Mail &amp; Kalender</a>
    <a class="button button--ghost" href="/admin/office">Status &amp; Diagnose</a>
</div>

<p class="card__hint">
    Benutzer <strong>ohne Exchange-Postfach</strong> oder aus Identitätsquellen <strong>ohne Exchange-Server</strong> nutzen Orvanta
    über ein externes Postfach: Lesen per <strong>IMAP</strong>, Senden per <strong>SMTP</strong>. Die Verbindung baut der interne Dienst
    <code>mail-proxy</code> mit den hier hinterlegten Zugangsdaten auf – Benutzer melden sich wie gewohnt per Windows-Anmeldung an,
    ihre eigenen Zugangsdaten werden nie an den Mailserver weitergegeben. Benutzer <strong>ohne Zuordnung</strong> verwenden unverändert Exchange.
    Über den Proxy stehen nur <strong>E-Mail-Funktionen</strong> zur Verfügung (kein Kalender, keine Kontakte, Aufgaben, Notizen, Erinnerungen
    und keine automatische Langzeitarchivierung).
</p>
<?php if ($tablesMissing) { ?>
    <p class="flash flash--error">Die Tabellen des SMTP-/IMAP-Proxys fehlen. Bitte die Datenbankmigrationen ausführen (<code>php scripts/migrate.php</code>, Migration 039).</p>
<?php } ?>

<section class="card" aria-labelledby="mp-servers-title">
    <h2 class="card__title" id="mp-servers-title">1. Proxy-Konfiguration je Identitätsquelle</h2>
    <?php if ($overview['servers'] === []) { ?>
        <p class="card__hint">Noch keine Konfiguration vorhanden. Je Identitätsquelle kann genau ein Mailserver (SMTP + IMAP) hinterlegt werden.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Identitätsquelle</th>
                        <th scope="col">SMTP</th>
                        <th scope="col">IMAP</th>
                        <th scope="col">Postfächer / Zuordnungen</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($overview['servers'] as $row) {
                    $rowSource = (int) $row['identity_source_id']; ?>
                    <tr<?= $selected !== null && (int) $selected['id'] === $rowSource ? ' aria-current="true"' : '' ?>>
                        <td>
                            <a href="<?= Html::e($base . '?quelle=' . $rowSource) ?>"><strong><?= Html::e($sourceName($row['source'])) ?></strong></a><br>
                            <span class="card__hint"><?= Html::e((string) $row['name']) ?></span>
                            <?php if ($row['source'] !== null && !$row['source']['active']) { ?><br><span class="badge badge--warn">Quelle deaktiviert</span><?php } ?>
                        </td>
                        <td><code><?= Html::e($row['smtp_host'] . ':' . $row['smtp_port']) ?></code><br><span class="card__hint"><?= Html::e($smtpSecurityLabels[$row['smtp_security']] ?? (string) $row['smtp_security']) ?><?= $row['smtp_auth'] ? ', mit Anmeldung' : ', ohne Anmeldung' ?></span></td>
                        <td><code><?= Html::e($row['imap_host'] . ':' . $row['imap_port']) ?></code><br><span class="card__hint"><?= Html::e($imapSecurityLabels[$row['imap_security']] ?? (string) $row['imap_security']) ?></span></td>
                        <td><?= (int) $row['mailbox_count'] ?> / <?= (int) $row['mapping_count'] ?></td>
                        <td>
                            <?= $row['active'] ? '<span class="badge badge--ok">Aktiv</span>' : '<span class="badge badge--muted">Inaktiv</span>' ?>
                            <?php if (!$row['verify_tls']) { ?><br><span class="badge badge--warn">Zertifikatsprüfung aus</span><?php } ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="<?= Html::e($base . '/server/bearbeiten?id=' . (int) $row['id']) ?>">Bearbeiten</a>
                                <form method="post" action="<?= Html::e($base) ?>/server/status" class="inline-form"<?= $row['active'] ? ' data-confirm="Konfiguration deaktivieren? Zugeordnete Benutzer verwenden dann wieder Exchange."' : '' ?>>
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="quelle" value="<?= $rowSource ?>">
                                    <input type="hidden" name="active" value="<?= $row['active'] ? '0' : '1' ?>">
                                    <button class="button button--ghost"><?= $row['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                </form>
                                <?php if ((int) $row['mailbox_count'] === 0) { ?>
                                    <form method="post" action="<?= Html::e($base) ?>/server/loeschen" class="inline-form" data-confirm="Proxy-Konfiguration wirklich löschen?">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <button class="button button--danger">Löschen</button>
                                    </form>
                                <?php } ?>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php if ($freeSources !== []) { ?>
        <div class="toolbar">
            <a class="button button--primary" href="<?= Html::e($base . '/server/neu' . ($selectedFree ? '?quelle=' . (int) $selected['id'] : '')) ?>">Konfiguration anlegen</a>
        </div>
    <?php } ?>
</section>

<?php if ($selected !== null && $server !== null) { ?>
<section class="card" aria-labelledby="mp-mailboxes-title" id="postfaecher">
    <h2 class="card__title" id="mp-mailboxes-title">2. Postfächer – <?= Html::e($sourceName($selected)) ?></h2>
    <?php if (!$server['active']) { ?><p class="flash flash--info">Diese Konfiguration ist deaktiviert; zugeordnete Benutzer verwenden derzeit Exchange.</p><?php } ?>
    <?php if ($overview['mailboxes'] === []) { ?>
        <p class="card__hint">Noch keine Postfächer angelegt.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Benutzername</th>
                        <th scope="col">E-Mail-Adresse</th>
                        <th scope="col">Zugeordnet</th>
                        <th scope="col">Postfachgröße</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($overview['mailboxes'] as $mailbox) { ?>
                    <tr>
                        <td><code><?= Html::e((string) $mailbox['username']) ?></code></td>
                        <td><?= Html::e((string) $mailbox['email_address']) ?><?= (string) $mailbox['display_name'] !== '' ? '<br><span class="card__hint">' . Html::e((string) $mailbox['display_name']) . '</span>' : '' ?></td>
                        <td><?= ($mailbox['mapping_id'] ?? null) !== null ? Html::e((string) ($mailbox['mapped_display_name'] ?? '')) . ' <span class="card__hint">(' . Html::e((string) ($mailbox['mapped_username'] ?? '')) . ')</span>' : '<span class="card__hint">–</span>' ?></td>
                        <td><?= (int) ($mailbox['quota_mb'] ?? 0) > 0 ? Html::e(number_format((int) $mailbox['quota_mb'], 0, ',', '.')) . ' MB' : '<span class="card__hint">ohne Grenze</span>' ?></td>
                        <td>
                            <?= $mailbox['active'] ? '<span class="badge badge--ok">Aktiv</span>' : '<span class="badge badge--muted">Inaktiv</span>' ?>
                            <?= $mailbox['password_set'] ? '' : '<br><span class="badge badge--error">kein Passwort</span>' ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="<?= Html::e($base . '/postfach/bearbeiten?id=' . (int) $mailbox['id']) ?>">Bearbeiten</a>
                                <form method="post" action="<?= Html::e($base) ?>/postfach/test" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $mailbox['id'] ?>">
                                    <input type="hidden" name="quelle" value="<?= $sourceQuery ?>">
                                    <button class="button button--ghost" title="SMTP- und IMAP-Verbindung, TLS und Anmeldung prüfen – es wird keine Mail gesendet">Verbindung testen</button>
                                </form>
                                <form method="post" action="<?= Html::e($base) ?>/postfach/loeschen" class="inline-form" data-confirm="Postfach und seine Zuordnung löschen?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $mailbox['id'] ?>">
                                    <input type="hidden" name="quelle" value="<?= $sourceQuery ?>">
                                    <button class="button button--danger">Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <div class="toolbar">
        <a class="button button--primary" href="<?= Html::e($base) ?>/postfach/neu?quelle=<?= $sourceQuery ?>">Postfach anlegen</a>
    </div>
</section>

<section class="card" aria-labelledby="mp-mapping-title" id="zuordnung">
    <h2 class="card__title" id="mp-mapping-title">3. Zuordnung AD-Benutzer → Postfach – <?= Html::e($sourceName($selected)) ?></h2>
    <p class="card__hint">
        Links einen synchronisierten AD-Benutzer dieser Identitätsquelle, rechts ein aktives, noch nicht vergebenes Postfach wählen
        (Eingabe startet die Vorschläge). Gespeichert wird die interne Benutzerkennung, nicht der Anzeigename. Jeder Benutzer und jedes
        Postfach kann höchstens einmal zugeordnet werden.
    </p>
    <div class="mail-proxy-map" data-mail-proxy-map
         data-users-url="<?= Html::e($base . '/users?quelle=' . $sourceQuery) ?>"
         data-mailboxes-url="<?= Html::e($base . '/mailboxes?quelle=' . $sourceQuery) ?>">
        <div class="mail-proxy-map__head" aria-hidden="true">
            <span>AD-Benutzer</span><span>Postfach</span><span></span>
        </div>
        <?php foreach ($overview['mappings'] as $mapping) {
            $mappingId = (int) $mapping['id']; ?>
            <div class="mail-proxy-map__row">
                <form method="post" action="<?= Html::e($base) ?>/zuordnung" class="mail-proxy-map__form" data-mail-proxy-mapping="<?= $mappingId ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $mappingId ?>">
                    <input type="hidden" name="quelle" value="<?= $sourceQuery ?>">
                    <div class="mail-proxy-map__cell">
                        <label class="visually-hidden" for="mp-user-<?= $mappingId ?>">AD-Benutzer</label>
                        <input id="mp-user-<?= $mappingId ?>" type="text" value="<?= Html::e((string) $mapping['display_name']) ?>" data-mp-suggest="users" data-mp-target="phonebook_id" autocomplete="off" spellcheck="false" required>
                        <input type="hidden" name="phonebook_id" value="<?= (int) $mapping['phonebook_id'] ?>">
                        <span class="group-suggest__meta"><?= Html::e((string) $mapping['samaccount_name']) ?><?= !$mapping['user_active'] ? ' · <strong>nicht mehr aktiv</strong>' : '' ?></span>
                    </div>
                    <div class="mail-proxy-map__cell">
                        <label class="visually-hidden" for="mp-mailbox-<?= $mappingId ?>">Postfach</label>
                        <input id="mp-mailbox-<?= $mappingId ?>" type="text" value="<?= Html::e((string) $mapping['email_address']) ?>" data-mp-suggest="mailboxes" data-mp-target="mailbox_id" autocomplete="off" spellcheck="false" required>
                        <input type="hidden" name="mailbox_id" value="<?= (int) $mapping['mailbox_id'] ?>">
                        <span class="group-suggest__meta"><?= Html::e((string) $mapping['mailbox_username']) ?><?= !$mapping['mailbox_active'] ? ' · <strong>Postfach deaktiviert</strong>' : '' ?></span>
                    </div>
                    <div class="row-actions">
                        <button class="button button--ghost">Ändern</button>
                        <button class="button button--danger" form="mp-delete-<?= $mappingId ?>">Entfernen</button>
                    </div>
                </form>
                <form method="post" action="<?= Html::e($base) ?>/zuordnung/loeschen" id="mp-delete-<?= $mappingId ?>" data-confirm="Zuordnung entfernen? Der Benutzer verwendet danach wieder Exchange." hidden>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $mappingId ?>">
                    <input type="hidden" name="quelle" value="<?= $sourceQuery ?>">
                </form>
            </div>
        <?php } ?>
        <div class="mail-proxy-map__row mail-proxy-map__row--new">
            <form method="post" action="<?= Html::e($base) ?>/zuordnung" class="mail-proxy-map__form" data-mail-proxy-mapping="0">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="0">
                <input type="hidden" name="quelle" value="<?= $sourceQuery ?>">
                <div class="mail-proxy-map__cell">
                    <label class="visually-hidden" for="mp-user-new">AD-Benutzer</label>
                    <input id="mp-user-new" type="text" placeholder="AD-Benutzer suchen …" data-mp-suggest="users" data-mp-target="phonebook_id" autocomplete="off" spellcheck="false" required>
                    <input type="hidden" name="phonebook_id" value="">
                </div>
                <div class="mail-proxy-map__cell">
                    <label class="visually-hidden" for="mp-mailbox-new">Postfach</label>
                    <input id="mp-mailbox-new" type="text" placeholder="Postfach suchen …" data-mp-suggest="mailboxes" data-mp-target="mailbox_id" autocomplete="off" spellcheck="false" required>
                    <input type="hidden" name="mailbox_id" value="">
                </div>
                <div class="row-actions">
                    <button class="button button--primary">Zuordnung hinzufügen</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php } elseif ($selected !== null) { ?>
    <p class="flash flash--info">Für <?= Html::e($sourceName($selected)) ?> ist noch kein Mailserver konfiguriert. Postfächer und Zuordnungen folgen nach dem Anlegen der Konfiguration.</p>
<?php } ?>

<?php if ($dialog !== null) {
    require __DIR__ . '/mail-proxy/' . $dialog['type'] . '.php';
} ?>

<?php if (!$orvantaEnabled) { ?>
    <p class="card__hint">Hinweis: Die Exchange-Anbindung ist nicht aktiviert. Orvanta steht dann nur Benutzern mit Proxy-Zuordnung zur Verfügung (Freigabe über Office → Apps).</p>
<?php } ?>
