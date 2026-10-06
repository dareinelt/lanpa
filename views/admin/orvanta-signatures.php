<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array{id:int,name:string,greeting:string,name_format:string,street:string,postal_city:string,phone_mode:string,phone_prefix:string,text_color:string,separator_color:string,groups:list<string>,sort_order:int,active:bool}> $signatures */
/** @var bool $hasLogo */
/** @var array<string,string> $theme */
/** @var array<string,string> $colorLabels */
/** @var bool $orvantaEnabled */
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/orvanta">Zurück zu Office</a>
    <a class="button button--primary" href="/admin/office/signaturen/vorlage">Neue Signaturvorlage</a>
</div>

<p class="card__hint">
    Signaturvorlagen werden in Orvanta beim Senden, Antworten und Speichern von Entwürfen
    <strong>automatisch angefügt</strong>; Benutzer sehen die Signatur im Verfassen-Dialog, können sie aber
    weder entfernen noch bearbeiten. <strong>Name, Position und Abteilung</strong> sowie die Rufnummer stammen aus
    dem Active Directory (Telefonliste, Attribute „Vorname“/„Nachname“ – je Vorlage als „Vorname Nachname“ oder
    „Nachname, Vorname“ –, „Position“, „Abteilung“, „Telefon“),
    <strong>Straße und Ort</strong> aus der Vorlage. Welche Vorlage ein Mitarbeiter erhält, bestimmen die
    hinterlegten <strong>AD-Gruppen</strong>; bei mehreren Treffern gilt die Vorlage mit der kleinsten Reihenfolge.
</p>
<p class="card__hint">
    Schriftfarbe und Farbe der Trennzeichen werden je Vorlage aus den Farben der <a href="/admin/design">Designeinstellungen</a>
    gewählt (Standard: „Textfarbe (hell)“ und „Akzentfarbe“); links neben dem Text wird das dort hochgeladene Logo
    in der Höhe der Textzeilen eingefügt
    <?php if ($hasLogo) { ?>(Logo vorhanden).<?php } else { ?>– <strong>derzeit ist kein Logo hochgeladen</strong>, die Signatur erscheint ohne Bild.<?php } ?>
</p>
<?php if (!$orvantaEnabled) { ?>
    <p class="flash flash--info">Die Exchange-Anbindung (Orvanta) ist nicht aktiviert. Vorlagen werden gespeichert, aber erst mit aktivem Orvanta verwendet.</p>
<?php } ?>

<section class="card" aria-labelledby="signatures-title">
    <h2 class="card__title" id="signatures-title">Vorlagen</h2>
    <?php if ($signatures === []) { ?>
        <p class="card__hint">Noch keine Signaturvorlage vorhanden. Ohne Vorlage werden Nachrichten in Orvanta ohne Signatur gesendet.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Reihenfolge</th>
                        <th scope="col">Name</th>
                        <th scope="col">Namensdarstellung</th>
                        <th scope="col">Adresse</th>
                        <th scope="col">Rufnummer</th>
                        <th scope="col">Farben</th>
                        <th scope="col">AD-Gruppen</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($signatures as $signature) { ?>
                    <tr>
                        <td><?= (int) $signature['sort_order'] ?></td>
                        <td><strong><?= Html::e($signature['name']) ?></strong></td>
                        <td><?= $signature['name_format'] === 'last_first' ? 'Nachname, Vorname' : 'Vorname Nachname' ?></td>
                        <td><?= Html::e(trim($signature['street'] . ' · ' . $signature['postal_city'], ' ·')) ?></td>
                        <td>
                            <?php if ($signature['phone_mode'] === 'full') { ?>
                                Komplett aus dem AD
                            <?php } else { ?>
                                <code><?= Html::e($signature['phone_prefix']) ?></code> + Durchwahl aus dem AD
                            <?php } ?>
                        </td>
                        <td>
                            <?php foreach (['text_color' => 'Schrift', 'separator_color' => 'Trennzeichen'] as $colorField => $colorLabel) { ?>
                                <?php $colorKey = $signature[$colorField]; ?>
                                <?= Html::e($colorLabel . ': ' . ($colorLabels[$colorKey] ?? $colorKey)) ?> <code><?= Html::e($theme[$colorKey] ?? '') ?></code><br>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($signature['groups'] === []) { ?>
                                <em>keine – wird niemandem zugeordnet</em>
                            <?php } else { ?>
                                <?= Html::e(implode(', ', $signature['groups'])) ?>
                            <?php } ?>
                        </td>
                        <td><?= $signature['active'] ? 'aktiv' : 'inaktiv' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="/admin/office/signaturen/vorlage?id=<?= (int) $signature['id'] ?>">Bearbeiten</a>
                                <form method="post" action="/admin/office/signaturen/loeschen" class="inline-form"
                                      data-confirm="Signaturvorlage „<?= Html::e($signature['name']) ?>“ wirklich löschen?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $signature['id'] ?>">
                                    <button type="submit" class="button button--danger">Löschen</button>
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

<?php foreach ($signatures as $signature) { ?>
    <section class="card" aria-labelledby="signature-preview-<?= (int) $signature['id'] ?>">
        <h2 class="card__title" id="signature-preview-<?= (int) $signature['id'] ?>">Vorschau: <?= Html::e($signature['name']) ?></h2>
        <p class="card__hint">Mit Beispieldaten (Erika Musterfrau). Bei Benutzern werden die Werte aus dem Active Directory eingesetzt.</p>
        <iframe class="signature-preview" title="Vorschau der Signatur <?= Html::e($signature['name']) ?>"
                src="/admin/office/signaturen/vorschau?id=<?= (int) $signature['id'] ?>" loading="lazy"></iframe>
    </section>
<?php } ?>
