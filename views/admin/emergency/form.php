<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/notfallnummern/neu' : '/admin/notfallnummern/bearbeiten';
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if (!$isNew) { ?>
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php } ?>

    <div class="field">
        <label for="label">Bezeichnung <span aria-hidden="true">*</span></label>
        <input type="text" id="label" name="label" required maxlength="120"
               value="<?= Html::e((string) $item['label']) ?>"
               <?= isset($errors['label']) ? 'aria-invalid="true" aria-describedby="label-error"' : '' ?>>
        <p class="field__hint">Wird als Titel der Kachel angezeigt, z. B. „Feuerwehr“ oder „Pforte“.</p>
        <?php if (isset($errors['label'])) { ?>
            <p class="field__error" id="label-error"><?= Html::e($errors['label']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="phone">Rufnummer <span aria-hidden="true">*</span></label>
        <input type="text" id="phone" name="phone" required maxlength="64"
               value="<?= Html::e((string) $item['phone']) ?>"
               <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>>
        <?php if (isset($errors['phone'])) { ?>
            <p class="field__error" id="phone-error"><?= Html::e($errors['phone']) ?></p>
        <?php } ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="sort_order">Sortierung</label>
            <input type="number" id="sort_order" name="sort_order" min="1" max="9999"
                   value="<?= (int) $item['sort_order'] ?>">
        </div>
    </div>

    <div class="field">
        <label for="description">Hinweis</label>
        <input type="text" id="description" name="description" maxlength="255"
               value="<?= Html::e((string) $item['description']) ?>">
        <p class="field__hint">Optionaler Zusatztext, wird ebenfalls auf der Kachel angezeigt.</p>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="active" name="active" value="1" <?= (int) $item['active'] === 1 ? 'checked' : '' ?>>
        <label for="active">Notfallnummer ist aktiv</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/notfallnummern">Abbrechen</a>
    </div>
</form>
