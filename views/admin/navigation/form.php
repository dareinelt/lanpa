<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/navigation/neu' : '/admin/navigation/bearbeiten';
$icons = ['', 'document', 'app', 'phone', 'alert', 'tools', 'robot', 'link'];
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
        <p class="field__hint" id="url-hint">Externe Ziele als vollständige https-URL, interne Ziele als Pfad (z. B. /telefonliste).</p>
        <?php if (isset($errors['url'])) { ?>
            <p class="field__error" id="url-error"><?= Html::e($errors['url']) ?></p>
        <?php } ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="type">Typ</label>
            <select id="type" name="type">
                <option value="external" <?= (string) $item['type'] === 'external' ? 'selected' : '' ?>>extern (neuer Tab)</option>
                <option value="internal" <?= (string) $item['type'] === 'internal' ? 'selected' : '' ?>>intern (in der Anwendung)</option>
            </select>
        </div>

        <div class="field">
            <label for="icon">Icon</label>
            <select id="icon" name="icon">
                <?php foreach ($icons as $iconKey) { ?>
                    <option value="<?= Html::e($iconKey) ?>" <?= (string) ($item['icon'] ?? '') === $iconKey ? 'selected' : '' ?>>
                        <?= $iconKey === '' ? 'Standard' : Html::e($iconKey) ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <div class="field">
            <label for="sort_order">Sortierung</label>
            <input type="number" id="sort_order" name="sort_order" min="1" max="9999"
                   value="<?= (int) $item['sort_order'] ?>">
        </div>
    </div>

    <div class="field">
        <label for="short_description">Kurzbeschreibung</label>
        <input type="text" id="short_description" name="short_description" maxlength="255"
               value="<?= Html::e((string) $item['short_description']) ?>">
        <p class="field__hint">Wird direkt auf der Kachel angezeigt.</p>
    </div>

    <div class="field">
        <label for="description">Detailbeschreibung</label>
        <textarea id="description" name="description" rows="6" maxlength="5000"><?= Html::e((string) $item['description']) ?></textarea>
        <p class="field__hint">Wird je nach Darstellungsmodus per Mouseover oder Aufklappen angezeigt.</p>
    </div>

    <div class="field field--check">
        <input type="checkbox" id="active" name="active" value="1" <?= (int) $item['active'] === 1 ? 'checked' : '' ?>>
        <label for="active">Element ist aktiv</label>
    </div>

    <div class="form__actions">
        <button type="submit" class="button button--primary">Speichern</button>
        <a class="button button--ghost" href="/admin/navigation">Abbrechen</a>
    </div>
</form>
