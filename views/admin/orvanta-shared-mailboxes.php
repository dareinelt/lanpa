<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array{id:int,uid:string,email:string,name:string,source:string,send_as:bool,active:bool,sort_order:int,calendar_visible:bool,verified:bool,error:string,checked_at:string}> $entries */
/** @var array{id:int,uid:string,email:string,name:string,source:string,send_as:bool,active:bool,sort_order:int,calendar_visible:bool,verified:bool,error:string,checked_at:string}|null $entry */
/** @var string $term */
/** @var list<array{uid:string,username:string,display_name:string,email:string,source:string}> $users */
/** @var string $uid */
/** @var bool $orvantaEnabled */
/** @var bool $orvantaDemo */
/** @var bool $tablesMissing */
/** @var string $base */

$editing = $entry !== null;
$formatDate = static function (string $value): string {
    $time = strtotime($value);

    return $time === false ? '–' : date('d.m.Y H:i', $time);
};
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/orvanta">Zurück zu Orvanta</a>
</div>

<p class="card__hint">
    Zusätzliche Postfächer, auf die ein Benutzer auf dem Exchange-Server per <strong>Vollzugriff</strong> oder
    <strong>„Senden als“</strong> berechtigt ist, erscheinen in Orvanta wie in Outlook als weiteres Postfach im
    Ordnerbaum. Die Zuordnung wird <strong>im Exchange (ECP) gepflegt</strong>: Mit dem Vollzugriff trägt Exchange
    den Benutzer am Postfach ein (Auto-Mapping), Orvanta liest die so eingebundenen Postfächer bei der Anmeldung aus
    dem Active Directory (<code>msExchDelegateListBL</code>) und übernimmt sie automatisch (Herkunft „Exchange“).
    Hier lassen sich nur Postfächer <em>ergänzen</em>, die ohne Auto-Mapping berechtigt wurden (Herkunft „manuell“).
    Orvanta prüft jede Zuordnung als der Benutzer: Nur Postfächer, auf die Exchange ihm Vollzugriff gewährt,
    erscheinen. Ist für den Benutzer keine Postfachadresse im Telefonbuch hinterlegt, erfolgt die Prüfung bei seiner
    nächsten Anmeldung in Orvanta.
</p>
<p class="card__hint">
    <strong>Archiviert wird weiterhin nur das primäre Benutzerpostfach</strong> (das im Active Directory hinterlegte
    Postfach des angemeldeten Benutzers). Zusätzliche Postfächer laufen nicht in die automatische Archivierung ein.
    Mit <strong>„Senden als“</strong> steht die Adresse im Verfassen-Dialog als Absender zur Auswahl; ob der Benutzer
    tatsächlich so senden darf, prüft Exchange beim Versand (gesendet wird immer als der Benutzer). Die Signatur
    bleibt die des primären Postfachs. Der Kalender eines zusätzlichen Postfachs lässt sich in Orvanta per
    Kontrollkästchen ein- und ausblenden.
</p>
<?php if ($tablesMissing) { ?>
    <p class="flash flash--info">Die Tabelle der zusätzlichen Postfächer fehlt. Bitte die Datenbankmigrationen ausführen (<code>php scripts/migrate.php</code>, Migration 046).</p>
<?php } ?>
<?php if (!$orvantaEnabled) { ?>
    <p class="flash flash--info">Die Exchange-Anbindung (Orvanta) ist nicht aktiviert. Zuordnungen lassen sich speichern, die Prüfung über EWS schlägt aber fehl – ohne erfolgreiche Prüfung erscheint kein zusätzliches Postfach.</p>
<?php } elseif ($orvantaDemo) { ?>
    <p class="flash flash--info">Orvanta läuft im Demomodus: Prüfbar sind nur die Beispielpostfächer <code>team@demo.local</code> (mit „Senden als“) und <code>buero@demo.local</code>.</p>
<?php } ?>

<section class="card" id="benutzer-suche" aria-labelledby="users-title">
    <h2 class="card__title" id="users-title">Benutzer suchen</h2>
    <p class="card__hint">Sucht im synchronisierten Active-Directory-Bestand (Telefonliste) und übernimmt die Office-Kennung in das Formular. Benutzer weiterer Identitätsquellen werden als <code>name@KENNUNG</code> eingetragen.</p>
    <form method="get" action="<?= Html::e($base) ?>#zuordnung" class="form" role="search">
        <div class="field">
            <label for="suche">Name, Benutzername oder E-Mail-Adresse</label>
            <input type="search" id="suche" name="suche" maxlength="100" value="<?= Html::e($term) ?>">
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Suchen</button>
        </div>
    </form>
    <?php if ($term !== '') { ?>
        <?php if ($users === []) { ?>
            <p class="empty-state">Kein Benutzer gefunden. Die Telefonliste wird unter <a href="/admin/ad/synchronisation">Active Directory → Synchronisation</a> gefüllt.</p>
        <?php } else { ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Office-Kennung</th>
                            <th scope="col">Eigene Adresse</th>
                            <th scope="col">Quelle</th>
                            <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $user) { ?>
                        <tr>
                            <td><?= Html::e($user['display_name'] !== '' ? $user['display_name'] : $user['username']) ?></td>
                            <td><code><?= Html::e($user['uid']) ?></code></td>
                            <td><?= Html::e($user['email']) ?></td>
                            <td><?= Html::e($user['source']) ?></td>
                            <td>
                                <a class="button button--ghost" href="<?= Html::e($base . '?benutzer=' . rawurlencode($user['uid'])) ?>#zuordnung">Auswählen</a>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    <?php } ?>
</section>

<section class="card" id="zuordnung" aria-labelledby="assign-title">
    <h2 class="card__title" id="assign-title"><?= $editing ? 'Zuordnung bearbeiten' : 'Postfach zuordnen' ?></h2>
    <form method="post" action="<?= Html::e($base . '/speichern') ?>" class="form form--wide" novalidate>
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int) $entry['id'] : 0 ?>">
        <div class="field-grid">
            <div class="field">
                <label for="benutzer">Benutzer (Office-Kennung) <span aria-hidden="true">*</span></label>
                <input type="text" id="benutzer" name="benutzer" required maxlength="190" value="<?= Html::e($uid) ?>"
                       placeholder="mueller" autocomplete="off" spellcheck="false" aria-describedby="benutzer-hint">
                <p class="field__hint" id="benutzer-hint">SamAccountName aus der Hauptquelle; bei weiteren Identitätsquellen <code>name@KENNUNG</code> (z. B. <code>mueller@ZWEIG</code>).</p>
            </div>
            <div class="field">
                <label for="postfach">Adresse des zusätzlichen Postfachs <span aria-hidden="true">*</span></label>
                <input type="email" id="postfach" name="postfach" required maxlength="254" value="<?= $editing ? Html::e($entry['email']) : '' ?>"
                       placeholder="team@example.local">
            </div>
        </div>
        <div class="field-grid">
            <div class="field">
                <label for="anzeigename">Anzeigename</label>
                <input type="text" id="anzeigename" name="anzeigename" maxlength="190" value="<?= $editing ? Html::e($entry['name']) : '' ?>"
                       placeholder="z. B. Team Postfach" aria-describedby="anzeigename-hint">
                <p class="field__hint" id="anzeigename-hint">Leer lassen, wenn die Adresse angezeigt werden soll.</p>
            </div>
            <div class="field">
                <label for="sortierung">Reihenfolge</label>
                <input type="number" id="sortierung" name="sortierung" min="0" max="999" value="<?= $editing ? (int) $entry['sort_order'] : 1 ?>"
                       aria-describedby="sortierung-hint">
                <p class="field__hint" id="sortierung-hint">Bestimmt die Reihenfolge der Postfächer im Ordnerbaum und im Absender-Dropdown.</p>
            </div>
        </div>
        <div class="field field--check">
            <input type="checkbox" id="senden_als" name="senden_als" value="1" <?= !$editing || $entry['send_as'] ? 'checked' : '' ?>>
            <label for="senden_als">Absenderadresse „Senden als“ erlaubt</label>
            <p class="field__hint">Ohne diese Berechtigung erscheint das Postfach nicht im Absender-Dropdown des Verfassen-Dialogs.</p>
        </div>
        <div class="field field--check">
            <input type="checkbox" id="aktiv" name="aktiv" value="1" <?= !$editing || $entry['active'] ? 'checked' : '' ?>>
            <label for="aktiv">Zuordnung aktiv</label>
            <p class="field__hint">Inaktive Zuordnungen bleiben gespeichert, werden dem Benutzer aber nicht angezeigt und nicht geprüft.</p>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary"><?= $editing ? 'Zuordnung speichern' : 'Zuordnung anlegen' ?></button>
            <?php if ($editing) { ?>
                <a class="button button--ghost" href="<?= Html::e($base) ?>#zuordnung">Abbrechen</a>
            <?php } ?>
        </div>
    </form>
</section>

<section class="card" aria-labelledby="entries-title">
    <h2 class="card__title" id="entries-title">Zugeordnete Postfächer</h2>
    <?php if ($entries === []) { ?>
        <p class="empty-state">Noch keine zusätzlichen Postfächer zugeordnet.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Benutzer</th>
                        <th scope="col">Postfach</th>
                        <th scope="col">Anzeigename</th>
                        <th scope="col">Herkunft</th>
                        <th scope="col">Reihenfolge</th>
                        <th scope="col">Senden als</th>
                        <th scope="col">Kalender</th>
                        <th scope="col">Prüfung über EWS</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $row) { ?>
                    <tr>
                        <td><code><?= Html::e($row['uid']) ?></code></td>
                        <td><?= Html::e($row['email']) ?></td>
                        <td><?= Html::e($row['name']) ?></td>
                        <td><?= $row['source'] === 'exchange' ? 'Exchange (Auto-Mapping)' : 'manuell' ?></td>
                        <td><?= (int) $row['sort_order'] ?></td>
                        <td><?= $row['send_as'] ? 'ja' : 'nein' ?></td>
                        <td><?= $row['calendar_visible'] ? 'eingeblendet' : 'ausgeblendet' ?></td>
                        <td>
                            <?php if ($row['verified']) { ?>
                                erreichbar (<?= Html::e($formatDate($row['checked_at'])) ?>)
                            <?php } elseif ($row['checked_at'] === '') { ?>
                                noch nicht geprüft<?= $row['error'] !== '' ? '<br>' . Html::e($row['error']) : '' ?>
                            <?php } else { ?>
                                <strong>nicht erreichbar</strong><?= $row['error'] !== '' ? '<br>' . Html::e($row['error']) : '' ?>
                            <?php } ?>
                        </td>
                        <td><?= $row['active'] ? 'aktiv' : 'inaktiv' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="<?= Html::e($base . '?id=' . (int) $row['id']) ?>#zuordnung">Bearbeiten</a>
                                <form method="post" action="<?= Html::e($base . '/pruefen') ?>" class="inline-form">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button button--ghost">Prüfen</button>
                                </form>
                                <form method="post" action="<?= Html::e($base . '/loeschen') ?>" class="inline-form"
                                      data-confirm="Zuordnung „<?= Html::e($row['email']) ?>“ für „<?= Html::e($row['uid']) ?>“ wirklich entfernen?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="button button--danger">Entfernen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
