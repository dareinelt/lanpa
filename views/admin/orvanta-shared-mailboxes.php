<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array{id:int,uid:string,email:string,name:string,send_as:bool,active:bool,sort_order:int,calendar_visible:bool,verified:bool,error:string,checked_at:string}> $entries */
/** @var bool $orvantaEnabled */
/** @var bool $orvantaDemo */
/** @var bool $tablesMissing */
/** @var string $base */

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
    Ordnerbaum. Die Berechtigungen werden <strong>ausschließlich im Exchange (ECP) gepflegt</strong>: Mit dem
    Vollzugriff trägt Exchange den Benutzer am Postfach ein (Auto-Mapping), Orvanta liest die so eingebundenen
    Postfächer beim Öffnen der App aus dem Active Directory (<code>msExchDelegateListBL</code>) und übernimmt sie
    automatisch – entzogene Berechtigungen verschwinden ebenso automatisch. Eine manuelle Zuordnung gibt es nicht;
    diese Seite zeigt den aktuellen Stand und erlaubt es, die Prüfung über EWS erneut anzustoßen. Orvanta prüft jedes
    Postfach als der Benutzer: Nur Postfächer, auf die Exchange ihm Vollzugriff gewährt, erscheinen. Ist für den
    Benutzer keine Postfachadresse im Telefonbuch hinterlegt, erfolgt die Prüfung bei seiner nächsten Anmeldung.
</p>
<p class="card__hint">
    <strong>Archiviert wird weiterhin nur das primäre Benutzerpostfach</strong> (das im Active Directory hinterlegte
    Postfach des angemeldeten Benutzers). Zusätzliche Postfächer laufen nicht in die automatische Archivierung ein.
    Die Adresse steht im Verfassen-Dialog als Absender zur Auswahl; ob der Benutzer tatsächlich „Senden als“ darf,
    prüft Exchange beim Versand (gesendet wird immer als der Benutzer). Die Signatur bleibt die des primären
    Postfachs. Der Kalender eines zusätzlichen Postfachs lässt sich in Orvanta per Kontrollkästchen ein- und
    ausblenden.
</p>
<?php if ($tablesMissing) { ?>
    <p class="flash flash--info">Die Tabelle der zusätzlichen Postfächer fehlt. Bitte die Datenbankmigrationen ausführen (<code>php scripts/migrate.php</code>, Migration 046).</p>
<?php } ?>
<?php if (!$orvantaEnabled) { ?>
    <p class="flash flash--info">Die Exchange-Anbindung (Orvanta) ist nicht aktiviert. Zusätzliche Postfächer werden erst übernommen und geprüft, wenn Orvanta aktiviert ist.</p>
<?php } elseif ($orvantaDemo) { ?>
    <p class="flash flash--info">Orvanta läuft im Demomodus: Das Active Directory wird nicht abgefragt; prüfbar sind nur die Beispielpostfächer <code>team@demo.local</code> (mit „Senden als“) und <code>buero@demo.local</code>.</p>
<?php } ?>

<section class="card" aria-labelledby="entries-title">
    <h2 class="card__title" id="entries-title">Aus Exchange übernommene Postfächer</h2>
    <p class="card__hint">Der Bestand wird beim Öffnen von Orvanta durch den jeweiligen Benutzer aus dem Active Directory aktualisiert (höchstens alle 15 Minuten je Sitzung).</p>
    <?php if ($entries === []) { ?>
        <p class="empty-state">Noch keine zusätzlichen Postfächer übernommen. Sie erscheinen, sobald ein Benutzer mit Vollzugriff auf ein weiteres Postfach Orvanta öffnet.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Benutzer</th>
                        <th scope="col">Postfach</th>
                        <th scope="col">Anzeigename</th>
                        <th scope="col">Reihenfolge</th>
                        <th scope="col">Kalender</th>
                        <th scope="col">Prüfung über EWS</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $row) { ?>
                    <tr>
                        <td><code><?= Html::e($row['uid']) ?></code></td>
                        <td><?= Html::e($row['email']) ?></td>
                        <td><?= Html::e($row['name']) ?></td>
                        <td><?= (int) $row['sort_order'] ?></td>
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
                        <td>
                            <form method="post" action="<?= Html::e($base . '/pruefen') ?>" class="inline-form">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="button button--ghost">Prüfen</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</section>
