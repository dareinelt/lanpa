<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/alarmierung/gruppen/neu' : '/admin/alarmierung/gruppen/bearbeiten';
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if (!$isNew) { ?>
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php } ?>

    <div class="field">
        <label for="group_number">Gruppennummer <span aria-hidden="true">*</span></label>
        <input type="text" id="group_number" name="group_number" required maxlength="64"
               value="<?= Html::e((string) $item['group_number']) ?>"
               <?= isset($errors['group_number']) ? 'aria-invalid="true" aria-describedby="group_number-error"' : '' ?>>
        <p class="field__hint">Wird als „to“-Parameter an das SMS-Gateway übergeben.</p>
        <?php if (isset($errors['group_number'])) { ?>
            <p class="field__error" id="group_number-error"><?= Html::e($errors['group_number']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="description">Beschreibung <span aria-hidden="true">*</span></label>
        <input type="text" id="description" name="description" required maxlength="255"
               value="<?= Html::e((string) $item['description']) ?>"
               <?= isset($errors['description']) ? 'aria-invalid="true" aria-describedby="description-error"' : '' ?>>
        <p class="field__hint">Freie Beschreibung, die in der Alarmierungskachel zur Auswahl steht.</p>
        <?php if (isset($errors['description'])) { ?>
            <p class="field__error" id="description-error"><?= Html::e($errors['description']) ?></p>
        <?php } ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="sort_order">Sortierung</label>
            <input type="number" id="sort_order" name="sort_order" min="1" max="9999"
                   value="<?= (int) $item['sort_order'] ?>">
        </div>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="active" name="active" value="1" <?= (int) $item['active'] === 1 ? 'checked' : '' ?>>
        <label for="active">Gruppe ist aktiv</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/alarmierung">Abbrechen</a>
    </div>
</form>
