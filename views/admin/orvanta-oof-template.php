<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array{id:int,name:string,fixed_text:string,example_text:string,groups:list<string>,sort_order:int,active:bool} $oofTemplate */
/** @var array<string,string> $errors */
/** @var int $maxFixedText */
/** @var int $maxDynamicText */

$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field__error" id="oof-' . Html::e($key) . '-error">' . Html::e($errors[$key]) . '</p>'
    : '';
$invalid = static fn (string $key): string => isset($errors[$key])
    ? 'aria-invalid="true" aria-describedby="oof-' . Html::e($key) . '-error"'
    : '';
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/abwesenheit">Zurück zu den Abwesenheitsvorlagen</a>
</div>

<form method="post" action="/admin/office/abwesenheit/vorlage" class="form form--wide" novalidate data-oof-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $oofTemplate['id'] ?>">

    <fieldset class="fieldset">
        <legend>Vorlage</legend>
        <div class="field">
            <label for="oof_name">Name der Vorlage <span aria-hidden="true">*</span></label>
            <input type="text" id="oof_name" name="name" required maxlength="120" value="<?= Html::e($oofTemplate['name']) ?>" <?= $invalid('name') ?>
                   placeholder="z. B. Allgemeine Abwesenheit">
            <?= $error('name') ?>
        </div>
        <div class="field-grid">
            <div class="field">
                <label for="oof_sort_order">Reihenfolge</label>
                <input type="number" id="oof_sort_order" name="sort_order" min="1" max="999" value="<?= (int) $oofTemplate['sort_order'] ?>" <?= $invalid('sort_order') ?>
                       aria-describedby="oof_sort_order-hint">
                <p class="field__hint" id="oof_sort_order-hint">Ist ein Benutzer Mitglied mehrerer zugeordneter Gruppen, gilt die Vorlage mit der kleinsten Zahl.</p>
                <?= $error('sort_order') ?>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="oof_active" name="active" value="1" <?= $oofTemplate['active'] ? 'checked' : '' ?>>
                <label for="oof_active">Vorlage aktiv</label>
            </div>
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Fester Text</legend>
        <div class="field">
            <label for="oof_fixed_text">Text der Abwesenheitsnotiz <span aria-hidden="true">*</span></label>
            <textarea id="oof_fixed_text" name="fixed_text" rows="5" required maxlength="<?= (int) $maxFixedText ?>"
                      <?= $invalid('fixed_text') ?> data-oof-field
                      placeholder="Sehr geehrte Damen und Herren,&#10;ich befinde mich derzeit nicht im Haus. Ihre Mails werden nicht weitergeleitet."><?= Html::e($oofTemplate['fixed_text']) ?></textarea>
            <p class="field__hint">
                Dieser Teil ist für den Benutzer in Orvanta <strong>schreibgeschützt</strong>; er kann ihn weder ändern
                noch entfernen. Zeilenumbrüche bleiben erhalten.
            </p>
            <?= $error('fixed_text') ?>
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Dynamischer Text (Beispiel)</legend>
        <div class="field">
            <label for="oof_example_text">Beispieltext für die Vertretung</label>
            <textarea id="oof_example_text" name="example_text" rows="4" maxlength="<?= (int) $maxDynamicText ?>"
                      <?= $invalid('example_text') ?> data-oof-field
                      placeholder="Bei dringenden Themen oder Anfragen wenden Sie sich bitte an Herrn/Frau XY unter der example@khwf.de oder telefonisch unter der 05331/934-wxyz."><?= Html::e($oofTemplate['example_text']) ?></textarea>
            <p class="field__hint">
                Der Text wird dem Benutzer in Orvanta als bearbeitbarer Teil <strong>unterhalb des festen Textes</strong>
                vorgegeben und ist nur ein Beispiel – er passt ihn dort an seine Vertretung an. Leer lassen, wenn die
                Notiz nur aus dem festen Text bestehen soll.
            </p>
            <?= $error('example_text') ?>
        </div>
        <p class="field__hint">
            Unter dem dynamischen Text setzt Orvanta automatisch die dem Benutzer zugewiesene
            <a href="/admin/office/signaturen">Signatur</a> ein.
        </p>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Zuordnung über AD-Gruppen</legend>
        <div class="field group-suggest">
            <label for="oof_groups">Gruppennamen (kommagetrennt)</label>
            <input type="text" id="oof_groups" name="groups" maxlength="4000"
                   value="<?= Html::e(implode(', ', $oofTemplate['groups'])) ?>"
                   autocomplete="off" spellcheck="false" aria-describedby="oof_groups-hint" <?= $invalid('groups') ?>
                   data-group-suggest="/admin/ad/gruppen">
            <p class="field__hint" id="oof_groups-hint">
                Mitglieder dieser Gruppen (auch verschachtelt) erhalten diese Vorlage in Orvanta.
                Ohne Gruppe wird die Vorlage niemandem zugeordnet.
            </p>
            <?= $error('groups') ?>
        </div>
    </fieldset>

    <section class="card" aria-labelledby="oof-live-preview-title">
        <h2 class="card__title" id="oof-live-preview-title">Vorschau</h2>
        <p class="card__hint">Fester Text, Beispieltext und darunter die erste Signaturvorlage mit Beispieldaten; aktualisiert sich beim Ändern der Felder.</p>
        <iframe class="signature-preview" title="Vorschau der Abwesenheitsnotiz" data-oof-preview
                data-preview-url="/admin/office/abwesenheit/vorschau"
                src="/admin/office/abwesenheit/vorschau?id=<?= (int) $oofTemplate['id'] ?>"></iframe>
    </section>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Vorlage speichern</button>
        <a class="button button--ghost" href="/admin/office/abwesenheit">Abbrechen</a>
    </div>
</form>
