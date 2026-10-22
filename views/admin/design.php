<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,string> $colorKeys */
/** @var array<string,string> $theme */
/** @var int|string $navOpacity */
/** @var array<string,string> $errors */
/** @var bool $hasLogo */
/** @var string $headerLogoMode */
/** @var bool $hasCompleteLogo */
/** @var int $maxLogoKb */
/** @var bool $hasBackground */
/** @var int $maxBackgroundKb */
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
        <p class="field__hint">
            Alle Farbwerte werden serverseitig geprüft; es werden ausschließlich Hex-Werte gespeichert.
            Reicht der Kontrast einer Textfarbe auf der Kachelfläche nicht aus (z.&nbsp;B. Textfarbe für den
            dunklen Modus auf einer hellen Kachel-Hintergrundfarbe), passt die Startseite die Kacheltexte
            automatisch an einen lesbaren Wert an.
        </p>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Navigation</legend>
        <div class="field">
            <label for="nav_opacity">Deckkraft der Navigationselemente (in %)</label>
            <input type="number" id="nav_opacity" name="nav_opacity" min="0" max="100" step="1"
                   value="<?= Html::e((string) $navOpacity) ?>">
            <p class="field__hint">
                Steuert die Deckkraft von Kopf- und Fußzeile, damit das Hintergrundbild nicht hart verdeckt wird.
                Werte zwischen 0 (vollständig transparent) und 100 (vollständig deckend) sind möglich.
            </p>
            <?php if (isset($errors['nav_opacity'])) { ?>
                <p class="field__error"><?= Html::e($errors['nav_opacity']) ?></p>
            <?php } ?>
        </div>
    </fieldset>

    <fieldset class="fieldset">
        <legend>Kopfbereich</legend>
        <div class="field field--check">
            <input type="radio" id="header_logo_mode-logo_text" name="header_logo_mode" value="logo_text"
                <?= $headerLogoMode !== 'complete_svg' ? 'checked' : '' ?>>
            <label for="header_logo_mode-logo_text">Logo und Seitentitel (bisherige Darstellung)</label>
        </div>
        <div class="field field--check">
            <input type="radio" id="header_logo_mode-complete_svg" name="header_logo_mode" value="complete_svg"
                <?= $headerLogoMode === 'complete_svg' ? 'checked' : '' ?>>
            <label for="header_logo_mode-complete_svg">Komplettes SVG (Logo mit Text, ohne zusätzlichen Seitentitel)</label>
            <p class="field__hint">
                Das komplette SVG wird unter „Komplettes Logo“ hochgeladen. Ist dort keine Datei hinterlegt,
                erscheint wie gewohnt Logo und Seitentitel. Das Logo für die E-Mail-Signaturen bleibt unberührt.
            </p>
        </div>
        <?php if (isset($errors['header_logo_mode'])) { ?>
            <p class="field__error"><?= Html::e($errors['header_logo_mode']) ?></p>
        <?php } ?>
    </fieldset>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Einstellungen speichern</button>
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

<section class="card">
    <h2 class="card__title">Komplettes Logo für den Kopfbereich (SVG)</h2>
    <p class="card__hint">
        Das komplette Logo enthält Bildmarke und Text in einer Datei. Es erscheint nur im Kopfbereich, wenn
        oben die Darstellung „Komplettes SVG“ gewählt ist. Die Grafik für die E-Mail-Signaturen entspricht
        weiterhin dem oben hochgeladenen Logo.
    </p>

    <?php if ($hasCompleteLogo) { ?>
        <p><img src="/logo-komplett" alt="Aktuelles komplettes Logo" class="logo-preview logo-preview--complete"></p>
    <?php } else { ?>
        <p class="card__hint">Es ist kein komplettes Logo hinterlegt.</p>
    <?php } ?>

    <form method="post" action="/admin/design/logo-komplett" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="logo_complete">Komplettes Logo hochladen (nur SVG, max. <?= (int) $maxLogoKb ?> KB)</label>
            <input type="file" id="logo_complete" name="logo_complete" accept="image/svg+xml" required>
            <p class="field__hint">
                Empfohlen ist ein breites Seitenverhältnis (Beispiel im Repository: 1379 × 169). Die Höhe im
                Kopfbereich ist fest, die Breite folgt dem Seitenverhältnis. SVG-Dateien werden auf aktive
                Inhalte geprüft.
            </p>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Hochladen</button>
        </div>
    </form>

    <?php if ($hasCompleteLogo) { ?>
        <form method="post" action="/admin/design/logo-komplett-entfernen" class="inline-form"
              data-confirm="Soll das komplette Logo entfernt werden?">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--danger">Komplettes Logo entfernen</button>
        </form>
    <?php } ?>
</section>

<section class="card">
    <h2 class="card__title">Hintergrundbild (Wasserzeichen)</h2>
    <p class="card__hint">
        Das Bild wird auf allen öffentlichen Seiten sehr dezent und transparent im Hintergrund dargestellt,
        bleibt beim Scrollen fixiert und reicht bis hinter Kopf- und Fußzeile. Es lenkt nicht vom Inhalt ab
        und beeinträchtigt nicht die Barrierefreiheit.
    </p>

    <?php if ($hasBackground) { ?>
        <p><img src="/hintergrundbild" alt="Aktuelles Hintergrundbild" class="logo-preview"></p>
    <?php } else { ?>
        <p class="card__hint">Es ist kein Hintergrundbild hinterlegt.</p>
    <?php } ?>

    <form method="post" action="/admin/design/hintergrundbild" enctype="multipart/form-data" class="form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="background">Hintergrundbild hochladen (nur PNG, max. <?= (int) $maxBackgroundKb ?> KB)</label>
            <input type="file" id="background" name="background" accept="image/png" required>
            <?php if (isset($errors['background'])) { ?>
                <p class="field__error"><?= Html::e($errors['background']) ?></p>
            <?php } ?>
            <p class="field__hint">
                Dateien werden außerhalb des Webverzeichnisses gespeichert und über die Anwendung ausgeliefert.
                Es sind ausschließlich PNG-Dateien erlaubt.
            </p>
        </div>
        <div class="form__actions">
            <button type="submit" class="button button--primary">Hochladen</button>
        </div>
    </form>

    <?php if ($hasBackground) { ?>
        <form method="post" action="/admin/design/hintergrundbild-entfernen" class="inline-form"
              data-confirm="Soll das Hintergrundbild entfernt werden?">
            <?= Csrf::field() ?>
            <button type="submit" class="button button--danger">Hintergrundbild entfernen</button>
        </form>
    <?php } ?>
</section>
