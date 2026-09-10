<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var string $descriptionMode */
/** @var bool $siteSubtitleVisible */
/** @var bool $landingIntroVisible */
/** @var string $footerText */
/** @var list<array<string,mixed>> $items */
/** @var array<string,string> $errors */
$modes = [
    'hover' => 'Mouseover – Beschreibung erscheint beim Überfahren; zusätzlich per Tastatur/Touch aufklappbar.',
    'expand' => 'Aufklappen – Beschreibung wird ausschließlich per Klick geöffnet und geschlossen.',
    'both' => 'Beides – Mouseover und Aufklappen sind gleichzeitig aktiv (empfohlen).',
];
?>
<form method="post" action="/admin/beschreibungen" class="form form--wide">
    <?= Csrf::field() ?>

    <div class="field">
        <label for="site_title">Seitentitel</label>
        <input type="text" id="site_title" name="site_title" required maxlength="120" value="<?= Html::e($siteTitle) ?>">
        <?php if (isset($errors['site_title'])) { ?>
            <p class="field__error"><?= Html::e($errors['site_title']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="site_subtitle">Untertitel</label>
        <input type="text" id="site_subtitle" name="site_subtitle" maxlength="200" value="<?= Html::e($siteSubtitle) ?>">
    </div>

    <div class="field field--check">
        <input type="checkbox" id="site_subtitle_visible" name="site_subtitle_visible" value="1"<?= $siteSubtitleVisible ? ' checked' : '' ?>>
        <label for="site_subtitle_visible">Untertitel unter dem Seitentitel anzeigen</label>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="landing_intro_visible" name="landing_intro_visible" value="1"<?= $landingIntroVisible ? ' checked' : '' ?>>
        <label for="landing_intro_visible">Seitentitel und Untertitel über den Kacheln auf der Startseite anzeigen</label>
    </div>

    <div class="field">
        <label for="footer_text">Footer-Text</label>
        <input type="text" id="footer_text" name="footer_text" maxlength="200" value="<?= Html::e($footerText) ?>">
        <p class="field__hint">Wird links im Seitenfuß angezeigt. Ohne Angabe erscheint der Seitentitel.</p>
    </div>

    <fieldset class="fieldset">
        <legend>Darstellung der Detailbeschreibungen</legend>
        <?php foreach ($modes as $value => $label) { ?>
            <div class="field field--check">
                <input type="radio" id="mode-<?= Html::e($value) ?>" name="description_mode" value="<?= Html::e($value) ?>"
                    <?= $descriptionMode === $value ? 'checked' : '' ?>>
                <label for="mode-<?= Html::e($value) ?>"><?= Html::e($label) ?></label>
            </div>
        <?php } ?>
        <?php if (isset($errors['description_mode'])) { ?>
            <p class="field__error"><?= Html::e($errors['description_mode']) ?></p>
        <?php } ?>
        <p class="field__hint">
            Unabhängig vom gewählten Modus bleibt die Beschreibung per Tastatur und auf Touchgeräten über die
            Schaltfläche „Details“ erreichbar.
        </p>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
    </div>
</form>

<section class="card">
    <h2 class="card__title">Beschreibungen der Elemente</h2>
    <p class="card__hint">Die Texte werden je Navigationselement gepflegt.</p>
    <div class="table-wrapper">
        <table class="table">
            <thead>
            <tr>
                <th scope="col">Titel</th>
                <th scope="col">Kurzbeschreibung</th>
                <th scope="col">Detailbeschreibung</th>
                <th scope="col"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item) { ?>
                <tr>
                    <td><?= Html::e((string) $item['title']) ?></td>
                    <td><?= Html::e((string) $item['short_description']) ?></td>
                    <td><?= Html::e(mb_substr((string) $item['description'], 0, 120)) ?><?= mb_strlen((string) $item['description']) > 120 ? '…' : '' ?></td>
                    <td><a class="button button--ghost" href="/admin/navigation/bearbeiten?id=<?= (int) $item['id'] ?>">Bearbeiten</a></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</section>
