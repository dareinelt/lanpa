<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/wichtige-links/neu' : '/admin/wichtige-links/bearbeiten';
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if (!$isNew) { ?>
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php } ?>

    <div class="field">
        <label for="title">Titel <span aria-hidden="true">*</span></label>
        <input type="text" id="title" name="title" required maxlength="120"
               value="<?= Html::e((string) $item['title']) ?>"
               <?= isset($errors['title']) ? 'aria-invalid="true" aria-describedby="title-error"' : '' ?>>
        <?php if (isset($errors['title'])) { ?>
            <p class="field__error" id="title-error"><?= Html::e($errors['title']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="url">URL <span aria-hidden="true">*</span></label>
        <input type="text" id="url" name="url" required maxlength="2048"
               value="<?= Html::e((string) $item['url']) ?>"
               <?= isset($errors['url']) ? 'aria-invalid="true" aria-describedby="url-error"' : 'aria-describedby="url-hint"' ?>>
        <p class="field__hint" id="url-hint">Vollständige https-URL oder ein interner Pfad (z. B. /telefonliste). Das Favicon der Seite wird, wenn möglich, automatisch übernommen.</p>
        <?php if (isset($errors['url'])) { ?>
            <p class="field__error" id="url-error"><?= Html::e($errors['url']) ?></p>
        <?php } ?>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="active" name="active" value="1" <?= (int) $item['active'] === 1 ? 'checked' : '' ?>>
        <label for="active">Link ist aktiv</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/wichtige-links">Abbrechen</a>
    </div>
</form>
