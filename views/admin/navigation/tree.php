<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Html;

/** @var list<array<string,mixed>> $items */

$typeLabels = [
    'external' => 'extern',
    'internal' => 'intern',
    'subpage' => 'Unterseite',
    'page' => 'Textseite',
];

$childrenMap = [];
foreach ($items as $item) {
    $parentKey = $item['parent_id'] === null ? 0 : (int) $item['parent_id'];
    $childrenMap[$parentKey][] = $item;
}

$renderNode = static function (array $item) use (&$renderNode, $childrenMap, $typeLabels): void {
    $id = (int) $item['id'];
    $type = (string) $item['type'];
    $typeLabel = $typeLabels[$type] ?? $type;
    $children = $childrenMap[$id] ?? [];
    $active = (int) ($item['active'] ?? 1) === 1;
    ?>
    <li class="tree-node">
        <div class="tree-node__row">
            <span class="tree-node__title"><?= Html::e((string) $item['title']) ?></span>
            <span class="badge <?= $active ? 'badge--ok' : 'badge--muted' ?>"><?= $active ? 'aktiv' : 'inaktiv' ?></span>
            <span class="badge badge--muted"><?= Html::e($typeLabel) ?></span>

            <div class="tree-node__actions">
                <?php if ($type === 'subpage') { ?>
                    <button type="button" class="tree-node__add" data-add-button
                            aria-haspopup="menu" aria-expanded="false"
                            aria-label="Unterelement zu „<?= Html::e((string) $item['title']) ?>“ anlegen">+</button>
                <?php } ?>
                <a class="button button--ghost" href="/admin/navigation/berechtigungen?id=<?= $id ?>">Berechtigungen</a>
                <a class="button button--ghost" href="/admin/navigation/bearbeiten?id=<?= $id ?>">Bearbeiten</a>
                <form method="post" action="/admin/navigation/status" class="inline-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="button button--ghost"><?= $active ? 'Deaktivieren' : 'Aktivieren' ?></button>
                </form>
                <form method="post" action="/admin/navigation/loeschen" class="inline-form"
                      data-confirm="Soll dieses Element wirklich gelöscht werden?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="button button--danger">Löschen</button>
                </form>
            </div>
        </div>

        <?php if ($type === 'subpage') { ?>
            <div class="tree-node__menu" data-add-menu hidden>
                <a class="tree-node__menu-item" href="/admin/navigation/neu?type=subpage&parent_id=<?= $id ?>">Navigationsseite</a>
                <button type="button" class="tree-node__menu-item" data-add-page
                        data-parent-id="<?= $id ?>"
                        data-parent-title="<?= Html::e((string) $item['title']) ?>">Textseite</button>
            </div>
        <?php } ?>

        <?php if ($children !== []) { ?>
            <ul class="tree-node__children">
                <?php foreach ($children as $child) {
                    $renderNode($child);
                } ?>
            </ul>
        <?php } ?>
    </li>
    <?php
};
?>

<?php if ($items === []) { ?>
    <p class="empty-state">Es sind noch keine Navigationselemente vorhanden.</p>
<?php } else { ?>
    <ul class="nav-tree">
        <?php foreach ($childrenMap[0] ?? [] as $item) {
            $renderNode($item);
        } ?>
    </ul>
<?php } ?>

<div class="nav-tree-overlay" data-page-overlay hidden role="dialog" aria-modal="true" aria-labelledby="page-overlay-title">
    <div class="nav-tree-overlay__box">
        <h2 class="nav-tree-overlay__title" id="page-overlay-title">Textseite anlegen</h2>
        <p class="nav-tree-overlay__hint" data-overlay-parent hidden></p>

        <form method="post" action="/admin/navigation/neu" data-overlay-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="type" value="page">
            <input type="hidden" name="parent_id" value="0" data-overlay-parent-id>
            <input type="hidden" name="active" value="1">

            <div class="field">
                <label for="overlay-title">Titel <span aria-hidden="true">*</span></label>
                <input type="text" id="overlay-title" name="title" required maxlength="120">
            </div>

            <div class="field">
                <label for="overlay-content">Inhalt</label>
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
                    <textarea id="overlay-content" name="content" hidden data-editor-source></textarea>
                </div>
                <p class="field__hint">Erlaubt sind Überschriften, Absätze, Listen, Zitate, Links und Hervorhebungen.</p>
            </div>

            <div class="form__actions">
                <button type="submit" class="button button--primary">Speichern</button>
                <button type="button" class="button button--ghost" data-overlay-close>Abbrechen</button>
            </div>
        </form>
    </div>
</div>
