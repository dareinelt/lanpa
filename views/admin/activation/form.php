<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
/** @var list<array<string,mixed>> $alarmGroups */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/aktivierungs-rufnummern/neu' : '/admin/aktivierungs-rufnummern/bearbeiten';
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide">
    <?= Csrf::field() ?>
    <?php if (!$isNew) { ?>
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php } ?>

    <div class="field">
        <label for="phone">Rufnummer <span aria-hidden="true">*</span></label>
        <input type="text" id="phone" name="phone" required maxlength="64"
               value="<?= Html::e((string) $item['phone']) ?>"
               <?= isset($errors['phone']) ? 'aria-invalid="true" aria-describedby="phone-error"' : '' ?>>
        <p class="field__hint">Rufnummer, an die der sechsstellige Zugangscode gesendet wird (z. B. +49 170 1234567).</p>
        <?php if (isset($errors['phone'])) { ?>
            <p class="field__error" id="phone-error"><?= Html::e($errors['phone']) ?></p>
        <?php } ?>
    </div>

    <div class="field">
        <label for="alarm_group_id">Gruppe <span aria-hidden="true">*</span></label>
        <select id="alarm_group_id" name="alarm_group_id"
                <?= isset($errors['alarm_group_id']) ? 'aria-invalid="true" aria-describedby="alarm_group_id-error"' : '' ?>>
            <option value="0">— Gruppe wählen —</option>
            <?php foreach ($alarmGroups as $group) { ?>
                <option value="<?= (int) $group['id'] ?>"
                    <?= (int) ($item['alarm_group_id'] ?? 0) === (int) $group['id'] ? 'selected' : '' ?>>
                    <?= Html::e((string) $group['group_number'] . ' — ' . (string) $group['description']) ?>
                </option>
            <?php } ?>
        </select>
        <p class="field__hint">Gruppennummer aus den Alarmierungseinstellungen, an die die Code-SMS gesendet wird.</p>
        <?php if (isset($errors['alarm_group_id'])) { ?>
            <p class="field__error" id="alarm_group_id-error"><?= Html::e($errors['alarm_group_id']) ?></p>
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
        <label for="active">Rufnummer ist aktiv</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/aktivierungs-rufnummern">Abbrechen</a>
    </div>
</form>
