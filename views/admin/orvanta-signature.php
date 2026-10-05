<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array{id:int,name:string,greeting:string,street:string,postal_city:string,phone_mode:string,phone_prefix:string,text_color:string,separator_color:string,groups:list<string>,sort_order:int,active:bool} $signature */
/** @var array<string,string> $theme */
/** @var array<string,string> $colorLabels */
/** @var array<string,string> $errors */

$error = static fn (string $key): string => isset($errors[$key])
    ? '<p class="field__error" id="signature-' . Html::e($key) . '-error">' . Html::e($errors[$key]) . '</p>'
    : '';
$invalid = static fn (string $key): string => isset($errors[$key])
    ? 'aria-invalid="true" aria-describedby="signature-' . Html::e($key) . '-error"'
    : '';
?>
<div class="toolbar">
    <a class="button button--ghost" href="/admin/office/signaturen">Zurück zu den Signaturvorlagen</a>
</div>

<form method="post" action="/admin/office/signaturen/vorlage" class="form form--wide" novalidate data-signature-form>
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $signature['id'] ?>">

    <fieldset class="fieldset">
        <legend>Vorlage</legend>
        <div class="field">
            <label for="signature_name">Name der Vorlage <span aria-hidden="true">*</span></label>
            <input type="text" id="signature_name" name="name" required maxlength="120" value="<?= Html::e($signature['name']) ?>" <?= $invalid('name') ?>
                   placeholder="z. B. Standard Wolfenbüttel">
            <?= $error('name') ?>
        </div>
        <div class="field-grid">
            <div class="field">
                <label for="signature_sort_order">Reihenfolge</label>
                <input type="number" id="signature_sort_order" name="sort_order" min="1" max="999" value="<?= (int) $signature['sort_order'] ?>" <?= $invalid('sort_order') ?>
                       aria-describedby="signature_sort_order-hint">
                <p class="field__hint" id="signature_sort_order-hint">Ist ein Benutzer Mitglied mehrerer zugeordneter Gruppen, gilt die Vorlage mit der kleinsten Zahl.</p>
                <?= $error('sort_order') ?>
            </div>
            <div class="field field--check">
                <input type="checkbox" id="signature_active" name="active" value="1" <?= $signature['active'] ? 'checked' : '' ?>>
                <label for="signature_active">Vorlage aktiv</label>
            </div>
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Inhalt</legend>
        <p class="field__hint">
            Name, Position und Abteilung werden <strong>nicht</strong> hier eingetragen – sie stammen aus dem
            Active Directory des jeweiligen Benutzers (Attribute „Anzeigename“, „Position“ und „Abteilung“ der
            <a href="/admin/ad">AD-Konfiguration</a>).
        </p>
        <div class="field">
            <label for="signature_greeting">Grußformel</label>
            <input type="text" id="signature_greeting" name="greeting" maxlength="120" value="<?= Html::e($signature['greeting']) ?>" <?= $invalid('greeting') ?>
                   placeholder="Mit freundlichen Grüßen" data-signature-field>
            <p class="field__hint">Leer lassen, wenn die Signatur ohne Grußformel beginnen soll.</p>
            <?= $error('greeting') ?>
        </div>
        <div class="field-grid">
            <div class="field">
                <label for="signature_street">Straße und Hausnummer</label>
                <input type="text" id="signature_street" name="street" maxlength="190" value="<?= Html::e($signature['street']) ?>" <?= $invalid('street') ?>
                       placeholder="Alter Weg 80" data-signature-field>
                <?= $error('street') ?>
            </div>
            <div class="field">
                <label for="signature_postal_city">Postleitzahl und Ort</label>
                <input type="text" id="signature_postal_city" name="postal_city" maxlength="190" value="<?= Html::e($signature['postal_city']) ?>" <?= $invalid('postal_city') ?>
                       placeholder="38302 Wolfenbüttel" data-signature-field>
                <?= $error('postal_city') ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="fieldset" <?= isset($errors['phone_mode']) ? 'aria-describedby="signature-phone_mode-error"' : '' ?>>
        <legend>Rufnummer</legend>
        <div class="field field--check">
            <input type="radio" id="signature_phone_mode_prefix" name="phone_mode" value="prefix" <?= $signature['phone_mode'] !== 'full' ? 'checked' : '' ?> data-signature-field>
            <label for="signature_phone_mode_prefix">Präfix aus der Vorlage, Durchwahl aus dem Active Directory</label>
            <p class="field__hint">Die letzten vier Ziffern der AD-Rufnummer werden fett an den Präfix angehängt – z. B. <code>T.: +49 (05331) 934 - </code><strong>1849</strong>.</p>
        </div>
        <div class="field">
            <label for="signature_phone_prefix">Präfix der Rufnummer</label>
            <input type="text" id="signature_phone_prefix" name="phone_prefix" maxlength="64" value="<?= Html::e($signature['phone_prefix']) ?>" <?= $invalid('phone_prefix') ?>
                   placeholder="T.: +49 (05331) 934 - " data-signature-field>
            <?= $error('phone_prefix') ?>
        </div>
        <div class="field field--check">
            <input type="radio" id="signature_phone_mode_full" name="phone_mode" value="full" <?= $signature['phone_mode'] === 'full' ? 'checked' : '' ?> data-signature-field>
            <label for="signature_phone_mode_full">Komplette Rufnummer aus dem Active Directory</label>
            <p class="field__hint">Die Rufnummer wird unverändert übernommen und mit „T.: “ eingeleitet; der Präfix bleibt unberücksichtigt.</p>
        </div>
        <?= $error('phone_mode') ?>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Farben</legend>
        <p class="field__hint">Zur Auswahl stehen die Farben der <a href="/admin/design">Designeinstellungen</a>; ändert sich dort eine Farbe, übernehmen die Signaturen sie automatisch.</p>
        <div class="field-grid">
            <?php foreach (['text_color' => 'Schriftfarbe', 'separator_color' => 'Farbe der Trennzeichen (■)'] as $colorField => $colorLabel) { ?>
                <div class="field">
                    <label for="signature_<?= Html::e($colorField) ?>"><?= Html::e($colorLabel) ?></label>
                    <select id="signature_<?= Html::e($colorField) ?>" name="<?= Html::e($colorField) ?>" <?= $invalid($colorField) ?> data-signature-field>
                        <?php foreach ($colorLabels as $colorKey => $label) { ?>
                            <option value="<?= Html::e($colorKey) ?>" <?= $signature[$colorField] === $colorKey ? 'selected' : '' ?>><?= Html::e($label . ' (' . ($theme[$colorKey] ?? '') . ')') ?></option>
                        <?php } ?>
                    </select>
                    <?= $error($colorField) ?>
                </div>
            <?php } ?>
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Zuordnung über AD-Gruppen</legend>
        <div class="field group-suggest">
            <label for="signature_groups">Gruppennamen (kommagetrennt)</label>
            <input type="text" id="signature_groups" name="groups" maxlength="4000"
                   value="<?= Html::e(implode(', ', $signature['groups'])) ?>"
                   autocomplete="off" spellcheck="false" aria-describedby="signature_groups-hint" <?= $invalid('groups') ?>
                   data-group-suggest="/admin/ad/gruppen">
            <p class="field__hint" id="signature_groups-hint">
                Mitglieder dieser Gruppen (auch verschachtelt) erhalten diese Signatur in Orvanta.
                Ohne Gruppe wird die Vorlage niemandem zugeordnet.
            </p>
            <?= $error('groups') ?>
        </div>
    </fieldset>

    <section class="card" aria-labelledby="signature-live-preview-title">
        <h2 class="card__title" id="signature-live-preview-title">Vorschau</h2>
        <p class="card__hint">Mit Beispieldaten (Erika Musterfrau, Sachbearbeiterin, Verwaltung, +49 5331 934-1234); aktualisiert sich beim Ändern der Felder.</p>
        <iframe class="signature-preview" title="Vorschau der Signatur" data-signature-preview
                data-preview-url="/admin/office/signaturen/vorschau"
                src="/admin/office/signaturen/vorschau?id=<?= (int) $signature['id'] ?>"></iframe>
    </section>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Vorlage speichern</button>
        <a class="button button--ghost" href="/admin/office/signaturen">Abbrechen</a>
    </div>
</form>
