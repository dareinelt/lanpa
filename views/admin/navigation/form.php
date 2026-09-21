<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var array<string,mixed> $item */
/** @var array<string,string> $errors */
/** @var list<array<string,mixed>> $subpages */
/** @var list<array<string,mixed>> $alarmGroups */
$isNew = ($item['id'] ?? null) === null;
$action = $isNew ? '/admin/navigation/neu' : '/admin/navigation/bearbeiten';
$type = (string) ($item['type'] ?? 'external');
$icons = [
    '', 'document', 'app', 'phone', 'alert', 'tools', 'robot', 'link',
    'clock', 'helmet', 'wrench', 'snail', 'beacon', 'ekg', 'warning', 'siren',
];
?>
<form method="post" action="<?= Html::e($action) ?>" class="form form--wide" data-editor-form>
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

    <div class="field" data-editor-url>
        <label for="url">URL <span aria-hidden="true">*</span></label>
        <input type="text" id="url" name="url" maxlength="2048"
               value="<?= Html::e((string) $item['url']) ?>"
               <?= isset($errors['url']) ? 'aria-invalid="true" aria-describedby="url-error"' : 'aria-describedby="url-hint"' ?>>
        <p class="field__hint" id="url-hint">Externe Ziele als vollständige https-URL, interne Ziele als Pfad (z. B. /telefonliste).</p>
        <?php if (isset($errors['url'])) { ?>
            <p class="field__error" id="url-error"><?= Html::e($errors['url']) ?></p>
        <?php } ?>
    </div>

    <div class="field" data-editor-alarm>
        <label for="alarm_text">Freitext <span aria-hidden="true">*</span></label>
        <textarea id="alarm_text" name="alarm_text" rows="4" maxlength="255"
                  <?= isset($errors['alarm_text']) ? 'aria-invalid="true" aria-describedby="alarm_text-error"' : '' ?>><?= Html::e((string) ($item['alarm_text'] ?? '')) ?></textarea>
        <p class="field__hint">Wird als „text“-Parameter an das SMS-Gateway übergeben.</p>
        <?php if (isset($errors['alarm_text'])) { ?>
            <p class="field__error" id="alarm_text-error"><?= Html::e($errors['alarm_text']) ?></p>
        <?php } ?>
    </div>

    <div class="field" data-editor-alarm>
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
        <p class="field__hint">Gruppennummer mit freier Beschreibung aus den Alarmierungseinstellungen.</p>
        <?php if (isset($errors['alarm_group_id'])) { ?>
            <p class="field__error" id="alarm_group_id-error"><?= Html::e($errors['alarm_group_id']) ?></p>
        <?php } ?>
    </div>

    <div class="field" data-editor-parent>
        <label for="parent_id">Übergeordnete Ebene</label>
        <select id="parent_id" name="parent_id" <?= isset($errors['parent_id']) ? 'aria-invalid="true" aria-describedby="parent-error"' : '' ?>>
            <option value="0" <?= (int) ($item['parent_id'] ?? 0) === 0 ? 'selected' : '' ?>>— oberste Ebene —</option>
            <?php foreach ($subpages as $subpage) { ?>
                <?php if ((int) $subpage['id'] === (int) ($item['id'] ?? 0)) {
                    continue;
                } ?>
                <option value="<?= (int) $subpage['id'] ?>" <?= (int) ($item['parent_id'] ?? 0) === (int) $subpage['id'] ? 'selected' : '' ?>>
                    <?= Html::e((string) $subpage['title']) ?>
                </option>
            <?php } ?>
        </select>
        <p class="field__hint">Legt fest, unter welcher Unterseite dieses Element erscheint.</p>
        <?php if (isset($errors['parent_id'])) { ?>
            <p class="field__error" id="parent-error"><?= Html::e($errors['parent_id']) ?></p>
        <?php } ?>
    </div>

    <div class="field" data-editor-content>
        <label for="content">Inhalt</label>
        <div class="editor" data-editor>
            <div class="editor__toolbar" role="toolbar" aria-label="Textformatierung">
                <button type="button" class="editor__button" data-command="bold" title="Fett" aria-label="Fett"><strong>F</strong></button>
                <button type="button" class="editor__button" data-command="italic" title="Kursiv" aria-label="Kursiv"><em>K</em></button>
                <button type="button" class="editor__button" data-command="underline" title="Unterstrichen" aria-label="Unterstrichen"><u>U</u></button>
                <button type="button" class="editor__button" data-command="strikeThrough" title="Durchgestrichen" aria-label="Durchgestrichen"><s>S</s></button>
                <span class="editor__separator" aria-hidden="true"></span>
                <button type="button" class="editor__button" data-command="formatBlock" data-value="p" title="Absatz" aria-label="Absatz">¶</button>
                <button type="button" class="editor__button" data-command="formatBlock" data-value="h2" title="Überschrift 2" aria-label="Überschrift 2">H2</button>
                <button type="button" class="editor__button" data-command="formatBlock" data-value="h3" title="Überschrift 3" aria-label="Überschrift 3">H3</button>
                <span class="editor__separator" aria-hidden="true"></span>
                <button type="button" class="editor__button" data-command="insertUnorderedList" title="Aufzählung" aria-label="Aufzählung">• Liste</button>
                <button type="button" class="editor__button" data-command="insertOrderedList" title="Nummerierung" aria-label="Nummerierung">1. Liste</button>
                <button type="button" class="editor__button" data-command="formatBlock" data-value="blockquote" title="Zitat" aria-label="Zitat">❝</button>
                <span class="editor__separator" aria-hidden="true"></span>
                <button type="button" class="editor__button" data-command="createLink" title="Link einfügen" aria-label="Link einfügen">Link</button>
                <button type="button" class="editor__button" data-command="removeFormat" title="Formatierung entfernen" aria-label="Formatierung entfernen">✕</button>
            </div>
            <div class="editor__surface" contenteditable="true" data-editor-surface></div>
            <textarea id="content" name="content" hidden data-editor-source><?= Html::e((string) ($item['content'] ?? '')) ?></textarea>
        </div>
        <p class="field__hint">Erlaubt sind Überschriften, Absätze, Listen, Zitate, Links und Hervorhebungen.</p>
        <?php if (isset($errors['content'])) { ?>
            <p class="field__error"><?= Html::e($errors['content']) ?></p>
        <?php } ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="type">Typ</label>
            <select id="type" name="type" data-editor-type>
                <option value="external" <?= $type === 'external' ? 'selected' : '' ?>>extern (neuer Tab)</option>
                <option value="internal" <?= $type === 'internal' ? 'selected' : '' ?>>intern (in der Anwendung)</option>
                <option value="subpage" <?= $type === 'subpage' ? 'selected' : '' ?>>Unterseite (weitere Kacheln)</option>
                <option value="page" <?= $type === 'page' ? 'selected' : '' ?>>Textseite (formatierter Inhalt)</option>
                <option value="alarm" <?= $type === 'alarm' ? 'selected' : '' ?>>Alarmierung</option>
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
            <label for="background_color">Kachel-Hintergrundfarbe</label>
            <div class="color-input">
                <input type="color" id="background_color" name="background_color"
                       value="<?= Html::e((string) ($item['background_color'] ?? '#1f4e79')) ?>"
                       data-color-sync="background_color-text">
                <input type="text" id="background_color-text" name="background_color_text"
                       value="<?= Html::e((string) ($item['background_color'] ?? '#1f4e79')) ?>"
                       pattern="#[0-9a-fA-F]{6}" maxlength="7"
                       aria-label="Kachel-Hintergrundfarbe als Hex-Wert" data-color-mirror="background_color">
            </div>
            <p class="field__hint">Optional. Leer lassen für Standard-Hintergrund (var(--color-surface)).</p>
            <?php if (isset($errors['background_color'])) { ?>
                <p class="field__error"><?= Html::e($errors['background_color']) ?></p>
            <?php } ?>
        </div>

        <div class="field">
            <label for="background_opacity">Kachel-Deckkraft (in %)</label>
            <input type="number" id="background_opacity" name="background_opacity" min="0" max="100" step="1"
                   value="<?= $item['background_opacity'] !== null && $item['background_opacity'] !== '' ? (int) $item['background_opacity'] : '' ?>">
            <p class="field__hint">Optional. Steuert die Transparenz der Kachel-Hintergrundfarbe (0 = transparent, 100 = deckend). Leer für Standard (97%).</p>
            <?php if (isset($errors['background_opacity'])) { ?>
                <p class="field__error"><?= Html::e($errors['background_opacity']) ?></p>
            <?php } ?>
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
