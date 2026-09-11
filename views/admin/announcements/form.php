<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/mitteilungen/neu' : '/admin/mitteilungen/bearbeiten';
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if (!$isNew) { ?>
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php } ?>

    <div class="field">
        <label for="title">Titel <span aria-hidden="true">*</span></label>
        <input type="text" id="title" name="title" required maxlength="160"
               value="<?= Html::e((string) $item['title']) ?>"
               <?= isset($errors['title']) ? 'aria-invalid="true" aria-describedby="title-error"' : '' ?>>
        <?php if (isset($errors['title'])) { ?>
            <p class="field__error" id="title-error"><?= Html::e($errors['title']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="message">Text <span aria-hidden="true">*</span></label>
        <textarea id="message" name="message" rows="6" required maxlength="5000"
                  <?= isset($errors['message']) ? 'aria-invalid="true" aria-describedby="message-error"' : '' ?>><?= Html::e((string) $item['message']) ?></textarea>
        <p class="field__hint">Wird als Overlay beim Öffnen der Landingpage angezeigt und lässt sich mit „Verstanden“ schließen.</p>
        <?php if (isset($errors['message'])) { ?>
            <p class="field__error" id="message-error"><?= Html::e($errors['message']) ?></p>
        <?php } ?>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="active" name="active" value="1" <?= (int) $item['active'] === 1 ? 'checked' : '' ?>>
        <label for="active">Mitteilung ist aktiv (wird auf der Landingpage angezeigt)</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/mitteilungen">Abbrechen</a>
    </div>
</form>
