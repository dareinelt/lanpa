<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array{id:int,name:string,fixed_text:string,example_text:string,groups:list<string>,sort_order:int,active:bool}> $templates */
/** @var bool $orvantaEnabled */
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/orvanta">Zurück zu Office</a>
    <a class="button button--primary" href="/admin/office/abwesenheit/vorlage">Neue Abwesenheitsvorlage</a>
</div>

<p class="card__hint">
    Abwesenheitsnotizen werden in Orvanta je Benutzer aktiviert und auf den Exchange-Server übertragen.
    Den Versand übernimmt der Server – Orvanta muss dafür nicht geöffnet bleiben.
    Jede Vorlage besteht aus einem <strong>festen Text</strong>, den der Benutzer nicht ändern kann, und einem
    <strong>dynamischen Text</strong>, den der Benutzer in Orvanta an seine Vertretung anpasst; der Text der Vorlage
    dient dabei nur als Beispiel. Darunter setzt Orvanta die dem Benutzer zugewiesene
    <a href="/admin/office/signaturen">Signatur</a> ein.
</p>
<p class="card__hint">
    Welche Vorlage ein Mitarbeiter erhält, bestimmen die hinterlegten <strong>AD-Gruppen</strong>; bei mehreren
    Treffern gilt die Vorlage mit der kleinsten Reihenfolge. Der Benutzer wählt in Orvanta, ob die Notiz nur an
    interne Empfänger oder auch als Antwort an Externe geht, und ob sie bis zum Abschalten oder in einem Zeitraum gilt.
</p>
<?php if (!$orvantaEnabled) { ?>
    <p class="flash flash--info">Die Exchange-Anbindung (Orvanta) ist nicht aktiviert. Vorlagen werden gespeichert, aber erst mit aktivem Orvanta verwendet.</p>
<?php } ?>

<section class="card" aria-labelledby="oof-templates-title">
    <h2 class="card__title" id="oof-templates-title">Vorlagen</h2>
    <?php if ($templates === []) { ?>
        <p class="card__hint">Noch keine Abwesenheitsvorlage vorhanden. Ohne Vorlage steht die Abwesenheitsnotiz in Orvanta nicht zur Verfügung.</p>
    <?php } else { ?>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Reihenfolge</th>
                        <th scope="col">Name</th>
                        <th scope="col">Fester Text</th>
                        <th scope="col">Dynamischer Text (Beispiel)</th>
                        <th scope="col">AD-Gruppen</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $template) { ?>
                    <tr>
                        <td><?= (int) $template['sort_order'] ?></td>
                        <td><strong><?= Html::e($template['name']) ?></strong></td>
                        <td><div class="table__hint"><?= nl2br(Html::e($template['fixed_text'])) ?></div></td>
                        <td><div class="table__hint"><?= $template['example_text'] === '' ? '<em>ohne</em>' : nl2br(Html::e($template['example_text'])) ?></div></td>
                        <td>
                            <?php if ($template['groups'] === []) { ?>
                                <em>keine – wird niemandem zugeordnet</em>
                            <?php } else { ?>
                                <?= Html::e(implode(', ', $template['groups'])) ?>
                            <?php } ?>
                        </td>
                        <td><?= $template['active'] ? 'aktiv' : 'inaktiv' ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="button button--ghost" href="/admin/office/abwesenheit/vorlage?id=<?= (int) $template['id'] ?>">Bearbeiten</a>
                                <form method="post" action="/admin/office/abwesenheit/loeschen" class="inline-form"
                                      data-confirm="Abwesenheitsvorlage „<?= Html::e($template['name']) ?>“ wirklich löschen?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $template['id'] ?>">
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

<?php foreach ($templates as $template) { ?>
    <section class="card" aria-labelledby="oof-preview-<?= (int) $template['id'] ?>">
        <h2 class="card__title" id="oof-preview-<?= (int) $template['id'] ?>">Vorschau: <?= Html::e($template['name']) ?></h2>
        <p class="card__hint">Fester Text, Beispieltext des dynamischen Teils und darunter die erste Signaturvorlage mit Beispieldaten.</p>
        <iframe class="signature-preview" title="Vorschau der Abwesenheitsnotiz <?= Html::e($template['name']) ?>"
                src="/admin/office/abwesenheit/vorschau?id=<?= (int) $template['id'] ?>" loading="lazy"></iframe>
    </section>
<?php } ?>
