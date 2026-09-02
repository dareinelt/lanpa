<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $colorKeys */
/** @var array<string,string> $theme */
/** @var array<string,string> $errors */
/** @var bool $hasLogo */
/** @var int $maxLogoKb */
?>
<form method="post" action="/admin/design" class="form form--wide">
    <?= Csrf::field() ?>

    <fieldset class="fieldset">
        <legend>Farben</legend>
        <div class="color-grid">
            <?php foreach ($colorKeys as $key => $label) { ?>
                <div class="field field--color">
                    <label for="<?= Html::e($key) ?>"><?= Html::e($label) ?></label>
                    <div class="color-input">
                        <input type="color" id="<?= Html::e($key) ?>" name="<?= Html::e($key) ?>"
                               value="<?= Html::e($theme[$key]) ?>" data-color-sync="<?= Html::e($key) ?>-text">
                        <input type="text" id="<?= Html::e($key) ?>-text" name="<?= Html::e($key) ?>_text"
                               value="<?= Html::e($theme[$key]) ?>" pattern="#[0-9a-fA-F]{6}" maxlength="7"
                               aria-label="<?= Html::e($label) ?> als Hex-Wert" data-color-mirror="<?= Html::e($key) ?>">
                    </div>
                    <?php if (isset($errors[$key])) { ?>
                        <p class="field__error"><?= Html::e($errors[$key]) ?></p>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
        <p class="field__hint">Alle Farbwerte werden serverseitig geprüft; es werden ausschließlich Hex-Werte gespeichert.</p>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Farben speichern</button>
    </div>
</form>

<section class="card">
    <h2 class="card__title">Logo</h2>

    <?php if ($hasLogo) { ?>
        <p><img src="/logo" alt="Aktuelles Logo" class="logo-preview"></p>
    <?php } else { ?>
        <p class="card__hint">Es ist kein Logo hinterlegt. Es wird ein textliches Kürzel angezeigt.</p>
    <?php } ?>

    <form method="post" action="/admin/design/logo" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="logo">Logo hochladen (PNG, JPEG, WebP oder SVG, max. <?= (int) $maxLogoKb ?> KB)</label>
            <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" required>
            <p class="field__hint">
                Dateien werden außerhalb des Webverzeichnisses gespeichert und über die Anwendung ausgeliefert.
                SVG-Dateien werden auf aktive Inhalte geprüft.
            </p>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Hochladen</button>
        </div>
    </form>

    <?php if ($hasLogo) { ?>
        <form method="post" action="/admin/design/logo-entfernen" class="inline-form"
              data-confirm="Soll das Logo entfernt werden?">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--danger">Logo entfernen</button>
        </form>
    <?php } ?>
</section>
