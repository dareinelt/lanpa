<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Passwortfeld fuer verschluesselt gespeicherte Zugangsdaten. Der gespeicherte
 * Wert wird nie ausgegeben; ein leeres Feld laesst ihn unveraendert.
 */

/** @var string $secretField */
/** @var string $secretLabel */
/** @var string $secretState missing|set|invalid */
/** @var array<string,string> $errors */
$secretStatusText = match ($secretState) {
    'set' => 'Gespeichert (verschlüsselt). Leer lassen, um es beizubehalten.',
    'invalid' => 'Das gespeicherte Passwort kann nicht entschlüsselt werden (z. B. nach einer Wiederherstellung auf einem anderen Server). Bitte neu eingeben.',
    default => 'Noch kein Passwort gespeichert.',
};
$secretDescribedBy = $secretField . '-state' . (isset($errors[$secretField]) ? ' ' . $secretField . '-error' : '');
?>
<div class="field">
    <label for="<?= Html::e($secretField) ?>"><?= Html::e($secretLabel) ?></label>
    <input type="password" id="<?= Html::e($secretField) ?>" name="<?= Html::e($secretField) ?>" maxlength="256"
           autocomplete="new-password" value=""
           placeholder="<?= $secretState === 'set' ? '••••••••' : '' ?>"
           aria-describedby="<?= Html::e($secretDescribedBy) ?>"
           <?= isset($errors[$secretField]) ? 'aria-invalid="true"' : '' ?>>
    <p class="field__hint" id="<?= Html::e($secretField) ?>-state">
        <span class="badge <?= $secretState === 'set' ? 'badge--ok' : ($secretState === 'invalid' ? 'badge--warn' : 'badge--muted') ?>">
            <?= $secretState === 'set' ? 'gesetzt' : ($secretState === 'invalid' ? 'ungültig' : 'fehlt') ?>
        </span>
        <?= Html::e($secretStatusText) ?>
    </p>
    <?php if (isset($errors[$secretField])) { ?><p class="field__error" id="<?= Html::e($secretField) ?>-error"><?= Html::e($errors[$secretField]) ?></p><?php } ?>
    <?php if ($secretState !== 'missing') { ?>
        <div class="field field--check">
            <input type="checkbox" id="<?= Html::e($secretField) ?>_clear" name="<?= Html::e($secretField) ?>_clear" value="1">
            <label for="<?= Html::e($secretField) ?>_clear">Gespeichertes Passwort entfernen</label>
        </div>
    <?php } ?>
</div>
