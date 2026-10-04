<?php
declare(strict_types=1);
use App\Security\Csrf;
use App\Support\Html;

/**
 * Notfallplan-Editor im Office-Stil: Titelleiste mit Schnellzugriff, Ribbon
 * (Reiter Start · Ablauf & Verbindungen · Prüfen & Freigabe · Ansicht),
 * dreispaltiger Arbeitsbereich (Schritte · Ablaufdiagramm · Eigenschaften)
 * und Statusleiste. Logik: public/assets/js/emergency-plan.js (Block `if (editor)`).
 */
$reviewLabels = ['draft' => 'Entwurf', 'pending' => 'Wartet auf zweite Freigabe', 'rejected' => 'Freigabe verweigert', 'approved' => 'Freigegeben'];
$canReview = $plan['review_state'] === 'pending' && !in_array($actor, $plan['contributors'], true) && $actor !== $plan['submitted_by'];

// Kleine, inline gezeichnete Symbole (CSP-konform, ohne externe Icon-Schrift).
$icons = [
    'action' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 12h10M7 9h6"/>',
    'contact' => '<path d="M6 3h4l2 5-3 2a11 11 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 4 5a2 2 0 0 1 2-2z"/>',
    'decision' => '<path d="M12 2 22 12 12 22 2 12z"/><path d="M9 12h6"/>',
    'checklist' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 9 1.5 1.5L11 8M7 15l1.5 1.5L11 14M13 9h5M13 15h5"/>',
    'note' => '<path d="M12 3 2 21h20z"/><path d="M12 10v4M12 17.5v.5"/>',
    'sms' => '<path d="M4 4h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
    'undo' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    'redo' => '<path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/>',
    'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
    'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    'up' => '<path d="M12 20V5M5 12l7-7 7 7"/>',
    'down' => '<path d="M12 4v15M5 12l7 7 7-7"/>',
    'plan' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
    'template' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M17.5 14v7M14 17.5h7"/>',
    'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'unlink' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/><path d="m3 3 18 18"/>',
    'and' => '<circle cx="7" cy="7" r="3"/><circle cx="7" cy="17" r="3"/><path d="M10 7h4a3 3 0 0 1 3 3v2a3 3 0 0 0 3 3M10 17h4a3 3 0 0 0 3-3v-2"/>',
    'or' => '<circle cx="7" cy="7" r="3"/><circle cx="7" cy="17" r="3"/><circle cx="19" cy="12" r="3"/><path d="M10 7c3 0 4 5 6 5M10 17c3 0 4-5 6-5"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'save' => '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
    'approve' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
    'zoom-in' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M11 8v6M8 11h6"/>',
    'zoom-out' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M8 11h6"/>',
    'fit' => '<path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5"/><rect x="8" y="8" width="8" height="8" rx="1"/>',
    'target' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
    'panel-left' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
    'panel-right' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
    'preview' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7M12 17h.01"/>',
    'theme' => '<path d="M12 3a9 9 0 1 0 9 9 7 7 0 0 1-9-9z"/>',
    'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
    'download' => '<path d="M12 3v12M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>',
    'cloud' => '<path d="M7 18a5 5 0 0 1-.6-9.96A6 6 0 0 1 18 9a4.5 4.5 0 0 1-.5 9z"/><path d="M12 11v5M9.5 13.5 12 11l2.5 2.5"/>',
];
$icon = static fn (string $name): string => '<svg class="ep-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
// Ribbon-Schaltfläche: großes Symbol über Beschriftung, Datenattribute steuern das Skript.
$rb = static function (string $iconName, string $label, string $attributes, string $tag = 'button', bool $small = false) use ($icon): string {
    $type = $tag === 'button' ? ' type="button"' : '';
    return '<' . $tag . $type . ' class="ep-rb' . ($small ? ' ep-rb--small' : '') . '" ' . $attributes . '>' . $icon($iconName) . '<span>' . Html::e($label) . '</span></' . $tag . '>';
};
?>
<link rel="stylesheet" href="/assets/css/emergency-plan.css?v=<?= Html::e($assetVersion) ?>">
<div class="ep-office" data-ep-editor data-save-url="<?= $base ?>/speichern" data-plan-id="<?= (int) $plan['id'] ?>">
    <textarea hidden data-ep-initial><?= Html::e(json_encode($plan, JSON_THROW_ON_ERROR)) ?></textarea>
    <textarea hidden data-ep-alarms><?= Html::e(json_encode($alarms, JSON_THROW_ON_ERROR)) ?></textarea>
    <?= Csrf::field() ?>

    <!-- Titelleiste mit Schnellzugriff -->
    <header class="ep-titlebar">
        <div class="ep-titlebar__brand"><?= $icon('plan') ?><span>Notfallplan-Editor</span></div>
        <div class="ep-titlebar__quick" role="toolbar" aria-label="Schnellzugriff">
            <button type="button" class="ep-qb" data-ep-save title="Entwurf speichern (Strg+S)"><?= $icon('save') ?><span class="visually-hidden">Speichern</span></button>
            <button type="button" class="ep-qb" data-ep-undo title="Rückgängig (Strg+Z)" disabled><?= $icon('undo') ?><span class="visually-hidden">Rückgängig</span></button>
            <button type="button" class="ep-qb" data-ep-redo title="Wiederholen (Strg+Y)" disabled><?= $icon('redo') ?><span class="visually-hidden">Wiederholen</span></button>
        </div>
        <div class="ep-titlebar__doc"><span class="ep-titlebar__docname" data-ep-doc-title><?= Html::e($plan['definition']['title'] !== '' ? $plan['definition']['title'] : 'Neuer Notfallplan') ?></span><span class="ep-titlebar__dirty" data-ep-dirty-flag hidden title="Ungespeicherte Änderungen">●</span></div>
        <div class="ep-titlebar__actions">
            <span class="ep-badge" data-ep-review-status><?= Html::e($reviewLabels[$plan['review_state']]) ?></span>
            <a class="ep-qb ep-qb--text" href="/admin/notfallplan/vorschau" target="_blank" rel="noopener" data-ep-open-preview title="Live-Vorschau in neuem Tab (Alt+Umschalt+V)"><?= $icon('preview') ?><span>Live-Vorschau</span></a>
            <a class="ep-qb ep-qb--text" href="<?= $base ?>" data-ep-close title="Editor schließen und zur Übersicht"><?= $icon('close') ?><span>Schließen</span></a>
        </div>
    </header>

    <!-- Ribbon -->
    <div class="ep-ribbon">
        <div class="ep-ribbon__tabs" role="tablist" aria-label="Funktionsbereiche">
            <button type="button" role="tab" id="ep-tab-start" aria-selected="true" aria-controls="ep-panel-start" data-ep-tab="start">Start</button>
            <button type="button" role="tab" id="ep-tab-flow" aria-selected="false" aria-controls="ep-panel-flow" data-ep-tab="flow" tabindex="-1">Ablauf &amp; Verbindungen</button>
            <button type="button" role="tab" id="ep-tab-review" aria-selected="false" aria-controls="ep-panel-review" data-ep-tab="review" tabindex="-1">Prüfen &amp; Freigabe</button>
            <button type="button" role="tab" id="ep-tab-view" aria-selected="false" aria-controls="ep-panel-view" data-ep-tab="view" tabindex="-1">Ansicht</button>
        </div>

        <div class="ep-ribbon__panel" role="tabpanel" id="ep-panel-start" aria-labelledby="ep-tab-start" data-ep-tabpanel="start">
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('action', 'Maßnahme', 'data-ep-add-type="action" title="Eine Aufgabe, die jemand erledigt"') ?>
                    <?= $rb('contact', 'Kontakt / Anruf', 'data-ep-add-type="contact" title="Jemanden anrufen oder informieren"') ?>
                    <?= $rb('decision', 'Entscheidung', 'data-ep-add-type="decision" title="Ja/Nein-Frage, verzweigt den Ablauf"') ?>
                    <?= $rb('checklist', 'Checkliste', 'data-ep-add-type="checklist" title="Mehrere Prüfpunkte abhaken"') ?>
                    <?= $rb('note', 'Hinweis', 'data-ep-add-type="note" title="Warnung oder Information ohne Aufgabe"') ?>
                    <?= $rb('sms', 'SMS-Alarm', 'data-ep-add-type="sms" title="SMS an eine Alarmvorlage (Gruppe) oder an einzelne Rufnummern"') ?>
                </div>
                <div class="ep-rg__label">Schritt hinzufügen</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('undo', 'Rückgängig', 'data-ep-undo disabled') ?>
                    <?= $rb('redo', 'Wiederholen', 'data-ep-redo disabled') ?>
                    <div class="ep-rg__stack">
                        <?= $rb('copy', 'Duplizieren', 'data-ep-duplicate', 'button', true) ?>
                        <?= $rb('trash', 'Löschen', 'data-ep-delete', 'button', true) ?>
                    </div>
                </div>
                <div class="ep-rg__label">Bearbeiten</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('up', 'Nach oben', 'data-ep-move="-1" title="Schritt in der Reihenfolge nach vorn"') ?>
                    <?= $rb('down', 'Nach unten', 'data-ep-move="1" title="Schritt in der Reihenfolge nach hinten"') ?>
                </div>
                <div class="ep-rg__label">Reihenfolge</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('plan', 'Plan-Angaben', 'data-ep-show-plan title="Titel und Kurzbeschreibung des Plans"') ?>
                    <div class="ep-rg__stack">
                        <?= $rb('template', 'Beispiel Brandfall', 'data-ep-template="fire"', 'button', true) ?>
                        <?= $rb('template', 'Beispiel MANV', 'data-ep-template="manv"', 'button', true) ?>
                    </div>
                </div>
                <div class="ep-rg__label">Plan</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('download', 'Herunterladen', 'data-ep-export="download" title="Gespeicherten Entwurf als Exportdatei(en) herunterladen (je höchstens ' . \App\Services\EmergencyPlanTransfer::PART_MAX_LABEL . ')"') ?>
                    <?= $rb('cloud', 'In Nextcloud speichern', 'data-ep-export="nextcloud" title="Gespeicherten Entwurf in den eigenen Nextcloud-Dateien ablegen (Ordner ' . \App\Services\EmergencyPlanTransfer::NEXTCLOUD_FOLDER . ')"') ?>
                </div>
                <div class="ep-rg__label">Exportieren</div>
            </div>
        </div>

        <div class="ep-ribbon__panel" role="tabpanel" id="ep-panel-flow" aria-labelledby="ep-tab-flow" data-ep-tabpanel="flow" hidden>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('link', 'Mit vorherigem Schritt verbinden', 'data-ep-link-previous title="Der Schritt davor wird Voraussetzung"') ?>
                    <?= $rb('unlink', 'Alle Voraussetzungen entfernen', 'data-ep-unlink-all title="Der Schritt wird zum Startpunkt"') ?>
                </div>
                <div class="ep-rg__label">Voraussetzungen</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('and', 'Alle müssen erledigt sein (UND)', 'data-ep-join="all" aria-pressed="false"') ?>
                    <?= $rb('or', 'Eine genügt (ODER)', 'data-ep-join="any" aria-pressed="false"') ?>
                </div>
                <div class="ep-rg__label">Verknüpfung</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items ep-rg__items--column">
                    <label class="ep-rg__check"><input type="checkbox" data-ep-autolink checked> Neue Schritte automatisch an den letzten anschließen</label>
                    <p class="ep-rg__hint">Verbindungen zeigen immer von oben nach unten. Schritte ohne Voraussetzung starten sofort.</p>
                </div>
                <div class="ep-rg__label">Automatik</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items ep-rg__items--column">
                    <label class="ep-rg__search"><?= $icon('search') ?><span class="visually-hidden">Schritte suchen</span><input type="search" data-ep-search placeholder="Schritt suchen …" autocomplete="off"></label>
                    <span class="ep-rg__hint" data-ep-search-result></span>
                </div>
                <div class="ep-rg__label">Suchen &amp; Filtern</div>
            </div>
        </div>

        <div class="ep-ribbon__panel" role="tabpanel" id="ep-panel-review" aria-labelledby="ep-tab-review" data-ep-tabpanel="review" hidden>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('check', 'Plan prüfen', 'data-ep-validate title="Unvollständige Schritte und fehlende Verbindungen anzeigen"') ?>
                </div>
                <div class="ep-rg__label">Prüfen</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('save', 'Entwurf speichern', 'data-ep-save title="Speichern erzeugt immer einen Entwurf (Strg+S)"') ?>
                </div>
                <div class="ep-rg__label">Speichern</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('approve', 'Freigabe & Protokoll', 'data-ep-open-dialog="review" title="Vier-Augen-Freigabe anfordern, prüfen oder zurückziehen"') ?>
                    <div class="ep-rg__items--column">
                        <p class="ep-rg__hint">Status: <strong data-ep-review-status><?= Html::e($reviewLabels[$plan['review_state']]) ?></strong></p>
                        <p class="ep-rg__hint"><?= (int) $plan['published'] === 1 ? 'Veröffentlicht: Version ' . (int) $plan['published_revision'] : 'Nicht veröffentlicht' ?> · Entwurf Version <span data-ep-revision><?= (int) $plan['revision'] ?></span></p>
                    </div>
                </div>
                <div class="ep-rg__label">Vier-Augen-Freigabe</div>
            </div>
        </div>

        <div class="ep-ribbon__panel" role="tabpanel" id="ep-panel-view" aria-labelledby="ep-tab-view" data-ep-tabpanel="view" hidden>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('zoom-in', 'Vergrößern', 'data-ep-zoom="in" title="Strg + Mausrad oder Strg + Plus"') ?>
                    <?= $rb('zoom-out', 'Verkleinern', 'data-ep-zoom="out" title="Strg + Mausrad oder Strg + Minus"') ?>
                    <div class="ep-rg__stack">
                        <?= $rb('fit', 'An Fenster anpassen', 'data-ep-zoom="fit"', 'button', true) ?>
                        <?= $rb('target', 'Gewählten Schritt zentrieren', 'data-ep-zoom="center"', 'button', true) ?>
                        <?= $rb('fit', '100 %', 'data-ep-zoom="reset"', 'button', true) ?>
                    </div>
                </div>
                <div class="ep-rg__label">Zoom</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('template', 'Automatisch anordnen', 'data-ep-auto-layout title="Per Drag-and-Drop gesetzte Positionen verwerfen"') ?>
                </div>
                <div class="ep-rg__label">Layout</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('panel-left', 'Schritte-Liste', 'data-ep-toggle-panel="palette" aria-pressed="true"') ?>
                    <?= $rb('panel-right', 'Eigenschaften', 'data-ep-toggle-panel="inspector" aria-pressed="true"') ?>
                </div>
                <div class="ep-rg__label">Bereiche</div>
            </div>
            <div class="ep-rg">
                <div class="ep-rg__items">
                    <?= $rb('preview', 'Live-Vorschau', 'href="/admin/notfallplan/vorschau" target="_blank" rel="noopener" data-ep-open-preview title="Öffnet die Vorschau in einem neuen Tab (Alt+Umschalt+V)"', 'a') ?>
                    <?= $rb('help', 'Hilfe', 'data-ep-open-dialog="help"') ?>
                    <?= $rb('theme', 'Design wechseln', 'data-theme-toggle') ?>
                </div>
                <div class="ep-rg__label">Fenster</div>
            </div>
        </div>
    </div>

    <!-- Arbeitsbereich -->
    <div class="ep-workspace3">
        <aside class="ep-palette" aria-label="Schritte">
            <h2>Schritte <span class="ep-count" data-ep-count>0 / 80</span></h2>
            <div class="ep-palette__quick" aria-label="Schnell hinzufügen">
                <?php foreach (['action' => 'Maßnahme', 'contact' => 'Kontakt', 'decision' => 'Entscheidung', 'checklist' => 'Checkliste', 'note' => 'Hinweis', 'sms' => 'SMS-Alarm'] as $type => $label) { ?>
                    <button type="button" class="ep-palette__add" data-ep-add-type="<?= $type ?>" draggable="true" data-ep-drag-type="<?= $type ?>" title="<?= Html::e($label) ?> hinzufügen (klicken oder ins Diagramm ziehen)"><?= $icon($type) ?><span><?= Html::e($label) ?></span></button>
                <?php } ?>
            </div>
            <ol data-ep-list class="ep-element-list" aria-label="Reihenfolge der Schritte"></ol>
        </aside>

        <section class="ep-canvas-wrap" aria-label="Ablaufdiagramm">
            <div class="ep-canvas" data-ep-canvas tabindex="0" aria-describedby="ep-canvas-hint">
                <div class="ep-diagram" data-ep-diagram></div>
            </div>
            <p id="ep-canvas-hint" class="ep-canvas__hint">Schritt anklicken zum Bearbeiten · Schritt ziehen zum Verschieben, auf einen anderen Schritt ziehen macht diesen zur Voraussetzung · Hintergrund ziehen verschiebt die Ansicht · Strg + Mausrad zum Zoomen · Baustein aus der linken Spalte hierher ziehen</p>
            <div class="ep-canvas__zoom" role="toolbar" aria-label="Zoom">
                <button type="button" class="ep-qb" data-ep-zoom="out" title="Verkleinern"><?= $icon('zoom-out') ?></button>
                <span data-ep-zoom-level>100 %</span>
                <button type="button" class="ep-qb" data-ep-zoom="in" title="Vergrößern"><?= $icon('zoom-in') ?></button>
                <button type="button" class="ep-qb" data-ep-zoom="fit" title="An Fenster anpassen"><?= $icon('fit') ?></button>
            </div>
        </section>

        <aside class="ep-inspector" aria-label="Eigenschaften">
            <details class="ep-issues" data-ep-issues hidden open>
                <summary>Prüfergebnis <span data-ep-issue-count></span></summary>
                <ul data-ep-issue-list></ul>
            </details>
            <section data-ep-plan-panel>
                <h2>Plan-Angaben</h2>
                <label>Plantitel <input data-ep-title maxlength="190" required value="<?= Html::e($plan['definition']['title']) ?>" placeholder="z. B. Brandfall"></label>
                <label>Kurzbeschreibung / erste Hinweise <textarea data-ep-description rows="4" maxlength="4000" placeholder="Was Einsatzkräfte zuerst wissen müssen"><?= Html::e($plan['definition']['description']) ?></textarea></label>
                <p class="ep-hint">Wählen Sie links oder im Diagramm einen Schritt, um ihn zu bearbeiten.</p>
            </section>
            <section data-ep-node-panel hidden>
                <h2>Schritt bearbeiten</h2>
                <div data-ep-fields></div>
            </section>
        </aside>
    </div>

    <!-- Statusleiste -->
    <footer class="ep-statusbar" aria-label="Status">
        <span><?= $icon('approve') ?><span data-ep-review-status><?= Html::e($reviewLabels[$plan['review_state']]) ?></span></span>
        <span>Entwurf Version <span data-ep-revision><?= (int) $plan['revision'] ?></span></span>
        <span><span data-ep-count>0 / 80</span> Schritte</span>
        <button type="button" class="ep-statusbar__issues" data-ep-validate><span data-ep-issue-count>Noch nicht geprüft</span></button>
        <span class="ep-statusbar__grow" data-ep-message role="status" aria-live="polite">Noch keine Änderungen.</span>
        <span class="ep-statusbar__credit">Notfallplan-Editor by Daniel-André Reinelt</span>
        <span data-ep-preview-status role="status" aria-live="polite" class="ep-statusbar__preview">Vorschau nicht verbunden</span>
        <span>Zoom <span data-ep-zoom-level>100 %</span></span>
    </footer>

    <!-- Dialog: Vier-Augen-Freigabe -->
    <dialog class="ep-dialog" data-ep-dialog="review" aria-labelledby="ep-review-heading">
        <div class="ep-dialog__head"><h2 id="ep-review-heading">Vier-Augen-Freigabe</h2><button type="button" class="ep-qb" data-ep-close-dialog title="Schließen"><?= $icon('close') ?><span class="visually-hidden">Schließen</span></button></div>
        <div class="ep-review" data-ep-review-panel>
            <p>Auch Administratoren dürfen eigene oder mitbearbeitete Entwürfe nicht selbst freigeben. Änderungen nach Antragstellung machen die offene Freigabe ungültig. Die aktuell veröffentlichte Version bleibt bis zur nächsten Freigabe unverändert.</p>
            <p><strong><?= (int) $plan['published'] === 1 ? 'Aktuell veröffentlicht: Version ' . (int) $plan['published_revision'] : 'Nicht veröffentlicht' ?></strong> · Entwurf: Version <?= (int) $plan['revision'] ?></p>
            <?php if ($plan['published_definition'] ?? null) { $definition = $plan['published_definition']; require __DIR__ . '/publication.php'; } ?>
            <?php if ($plan['contributors'] !== []) { ?><p>Am aktuellen Entwurf beteiligt: <?= Html::e(implode(', ', $plan['contributors'])) ?></p><?php } ?>
            <?php if ($plan['id'] > 0 && in_array($plan['review_state'], ['draft', 'rejected'], true)) { ?>
                <form action="<?= $base ?>/freigabe" method="post" data-ep-review-form>
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>"><input type="hidden" name="action" value="submit">
                    <button class="button button--primary">Freigabe anfordern</button>
                </form>
            <?php } elseif ($plan['review_state'] === 'pending') { ?>
                <p>Angefordert von <?= Html::e($plan['submitted_by']) ?> am <?= Html::e($plan['submitted_at']) ?> UTC.</p>
                <?php if ($canReview) { ?>
                    <form action="<?= $base ?>/freigabe" method="post" data-ep-review-form>
                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>">
                        <label>Prüfkommentar (bei Ablehnung Pflicht)<textarea name="comment" maxlength="2000" rows="3"></textarea></label>
                        <button class="button button--primary" name="action" value="approve">Geprüften Entwurf freigeben und veröffentlichen</button>
                        <button class="button button--danger" name="action" value="reject">Freigabe verweigern</button>
                    </form>
                <?php } else { ?><p>Sie haben an diesem Entwurf mitgewirkt oder ihn eingereicht. Ein anderes, unbeteiligtes KAEP-Mitglied oder ein unbeteiligter Administrator muss prüfen.</p><?php } ?>
            <?php } else { ?><p><?= $plan['id'] > 0 ? 'Der Entwurf wurde bereits freigegeben.' : 'Zuerst einen Entwurf speichern.' ?></p><?php } ?>
            <?php if ((int) $plan['published'] === 1) { ?>
                <form action="<?= $base ?>/freigabe" method="post" data-ep-review-form data-ep-confirm="Veröffentlichten Plan sofort zurückziehen? Erneutes Veröffentlichen benötigt eine neue zweite Freigabe.">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $plan['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $plan['revision'] ?>"><input type="hidden" name="action" value="withdraw">
                    <button class="button button--danger">Veröffentlichung zurückziehen</button>
                </form>
            <?php } ?>
            <h3>Freigabeprotokoll</h3>
            <ol class="ep-log"><?php foreach ($reviews as $review) { ?><li><?= Html::e($review['created_at']) ?> UTC · <?= Html::e($review['actor']) ?> · Version <?= (int) $review['revision'] ?> · <strong><?= Html::e(['saved' => 'Entwurf gespeichert', 'submitted' => 'Freigabe angefordert', 'approved' => 'Freigegeben', 'rejected' => 'Abgelehnt', 'withdrawn' => 'Zurückgezogen', 'imported' => 'Importiert'][$review['action']] ?? $review['action']) ?></strong><p><?= Html::e($review['comment']) ?></p></li><?php } ?></ol>
            <?php if ($reviews === []) { ?><p>Noch keine Einträge.</p><?php } ?>
        </div>
    </dialog>

    <!-- Dialog: Export des gespeicherten Entwurfs -->
    <dialog class="ep-dialog" data-ep-dialog="export" aria-labelledby="ep-export-heading">
        <div class="ep-dialog__head"><h2 id="ep-export-heading">Notfallplan exportieren</h2><button type="button" class="ep-qb" data-ep-close-dialog title="Schließen"><?= $icon('close') ?><span class="visually-hidden">Schließen</span></button></div>
        <div class="ep-help">
            <p>Exportiert wird der gespeicherte Entwurf einschließlich der Anhänge – ohne Freigabehistorie und Ereignisse. Eine Exportdatei ist höchstens <?= Html::e(\App\Services\EmergencyPlanTransfer::PART_MAX_LABEL) ?> groß; wird die Grenze erreicht, entsteht eine anfolgende Datei („Teil 2 von 3“ …). Für den Import (Übersicht → Export und Import) alle Dateien des Satzes auswählen. Die Dateien enthalten Ansprechpartner und Telefonnummern; bitte vertraulich behandeln.</p>
            <div class="ep-transfer" data-ep-transfer-status aria-live="polite"></div>
        </div>
    </dialog>

    <!-- Dialog: Hilfe -->
    <dialog class="ep-dialog" data-ep-dialog="help" aria-labelledby="ep-help-heading">
        <div class="ep-dialog__head"><h2 id="ep-help-heading">So funktioniert der Editor</h2><button type="button" class="ep-qb" data-ep-close-dialog title="Schließen"><?= $icon('close') ?><span class="visually-hidden">Schließen</span></button></div>
        <div class="ep-help">
            <h3>Schritte anlegen</h3>
            <p>Im Reiter <strong>Start</strong> oder in der linken Spalte auf einen Baustein klicken – der neue Schritt wird ans Ende gesetzt und automatisch mit dem letzten Schritt verbunden. Bausteine lassen sich auch in das Diagramm ziehen.</p>
            <h3>Schritte bearbeiten</h3>
            <p>Einen Schritt im Diagramm oder in der Liste anklicken. Rechts unter <strong>Eigenschaften</strong> Titel, Anweisung, Zuständigkeit, Telefon, Link und Zielzeit ausfüllen. Checklisten erhalten Prüfpunkte. SMS-Schritte gehen an eine bestehende Alarmvorlage (Gruppe) oder an einzelne Rufnummern mit eigenem Text (max. 255 Zeichen); Textbausteine wie Name des Notfallplans, Datum und Uhrzeit der Auslösung werden per Klick eingefügt und beim Auslösen ersetzt. Ein <strong>Rechtsklick</strong> auf einen Schritt im Diagramm öffnet ein Menü mit <em>Duplizieren, Kopieren, Einfügen, Rückgängig</em> und <em>Löschen</em>. Eingefügt wird hinter dem markierten Schritt – auch in einen anderen Plan.</p>
            <h3>Verbindungen (Voraussetzungen)</h3>
            <p>Ein Schritt startet, wenn seine Voraussetzungen erfüllt sind. Rechts lassen sich vorherige Schritte als Voraussetzung anhaken; bei Entscheidungen zusätzlich die Antwort <em>Ja</em> oder <em>Nein</em>. <strong>UND</strong> = alle Voraussetzungen müssen erledigt sein, <strong>ODER</strong> = eine genügt (führt Ja/Nein-Zweige wieder zusammen). Die Reihenfolge ändern Sie mit <em>Nach oben/unten</em>.</p>
            <h3>Drag-and-Drop im Diagramm</h3>
            <p>Einen Schritt im Diagramm mit der Maus ziehen und auf freier Fläche loslassen, um ihn zu verschieben – das ändert nur die Darstellung, nicht den Ablauf. Wird der Schritt auf einen <strong>anderen Schritt</strong> gezogen, wird dieser zur (einzigen) Voraussetzung: Der Ablaufpfeil wird umgehängt und die Reihenfolge bei Bedarf angepasst. <em>Ansicht → Automatisch anordnen</em> verwirft die eigenen Positionen.</p>
            <h3>Prüfen, speichern, freigeben</h3>
            <p><strong>Plan prüfen</strong> markiert unvollständige Schritte. <strong>Speichern</strong> legt immer nur einen Entwurf an – veröffentlicht wird ausschließlich über die <strong>Vier-Augen-Freigabe</strong> durch eine unbeteiligte Person. Speichern löst keine SMS aus.</p>
            <h3>Live-Vorschau</h3>
            <p>Öffnet einen zweiten Tab, der jede Änderung sofort übernimmt. Dort können Sie den Ablauf gefahrlos durchspielen; es werden keine SMS oder E-Mails versendet.</p>
            <h3>Tastenkürzel</h3>
            <ul>
                <li><kbd>Strg</kbd>+<kbd>S</kbd> Speichern · <kbd>Strg</kbd>+<kbd>Z</kbd> Rückgängig · <kbd>Strg</kbd>+<kbd>Y</kbd> Wiederholen</li>
                <li><kbd>Strg</kbd>+<kbd>+</kbd> / <kbd>−</kbd> / <kbd>0</kbd> Zoom · <kbd>Alt</kbd>+<kbd>Umschalt</kbd>+<kbd>V</kbd> Live-Vorschau</li>
                <li><kbd>Entf</kbd> löscht den markierten Schritt (wenn das Diagramm den Fokus hat) · <kbd>Esc</kbd> schließt Dialoge</li>
                <li>Im Diagramm: <kbd>Strg</kbd>+<kbd>D</kbd> Duplizieren · <kbd>Strg</kbd>+<kbd>C</kbd> Kopieren · <kbd>Strg</kbd>+<kbd>V</kbd> Einfügen · <kbd>Umschalt</kbd>+<kbd>F10</kbd> Kontextmenü</li>
            </ul>
            <p class="ep-hint">Beispielvorlagen (Brandfall, MANV) sind nur Ausgangspunkte und müssen fachlich geprüft werden.</p>
        </div>
    </dialog>

    <?php if ((int) $plan['id'] === 0) { ?>
    <!-- Overlay: Plan-Angaben beim Anlegen eines neuen Plans (Pflicht vor der Bearbeitung) -->
    <dialog class="ep-dialog ep-alert ep-setup" data-ep-setup aria-labelledby="ep-setup-heading" aria-describedby="ep-setup-text">
        <form method="dialog" data-ep-setup-form>
            <h2 id="ep-setup-heading" class="ep-alert__heading">Neuen Notfallplan anlegen</h2>
            <p id="ep-setup-text" class="ep-alert__text">Bitte zuerst Plantitel und Kurzbeschreibung angeben. Beides lässt sich später unter <strong>Plan-Angaben</strong> ändern.</p>
            <label>Plantitel <input data-ep-setup-title maxlength="190" required placeholder="z. B. Brandfall" autocomplete="off"></label>
            <label>Kurzbeschreibung / erste Hinweise <textarea data-ep-setup-description rows="4" maxlength="4000" required placeholder="Was Einsatzkräfte zuerst wissen müssen"></textarea></label>
            <div class="ep-alert__actions">
                <button type="submit" class="button button--primary">Bearbeitung beginnen</button>
                <button type="button" class="button button--ghost" data-ep-setup-cancel>Abbrechen</button>
            </div>
        </form>
    </dialog>
    <?php } ?>
</div>
<noscript><p class="flash flash--error">Der Planeditor benötigt JavaScript. Ohne JavaScript können keine Pläne bearbeitet werden.</p></noscript>
