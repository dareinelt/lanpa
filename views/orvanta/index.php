<?php

declare(strict_types=1);

use App\Support\Html;

/**
 * Orvanta – Mail- und Kalender-App im Office-Stil (Vorlage: Notfallplan-Editor).
 *
 * Aufbau: Titelleiste (Logo, Suche, Einstellungen, Profil) · Ribbon je Modul ·
 * Arbeitsbereich (Modulleiste · Liste · Detail) · Statusleiste. Die Inhalte
 * werden von public/assets/js/orvanta.js über /api/orvanta/… geladen.
 *
 * @var array<string,mixed> $orvanta
 * @var array<string,mixed> $ssoUser
 * @var string $csrfToken
 * @var string $assetVersion
 */
$icons = [
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    'contacts' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17" cy="9" r="2.5"/><path d="M15.5 14.5A5 5 0 0 1 21.5 20"/>',
    'tasks' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m7 12 3 3 7-7"/>',
    'notes' => '<path d="M5 3h14v12l-6 6H5z"/><path d="M13 21v-6h6M8 8h8M8 12h5"/>',
    'compose' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
    'reply' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/>',
    'replyall' => '<path d="M7 14 2 9l5-5M12 14 7 9l5-5"/><path d="M7 9h8a6 6 0 0 1 0 12h-3"/>',
    'forward' => '<path d="m15 14 5-5-5-5"/><path d="M20 9H10a6 6 0 0 0 0 12h3"/>',
    'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    'archive' => '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9M10 13h4"/>',
    'flag' => '<path d="M5 21V4h11l-2 4 2 4H5"/>',
    'read' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6M3 19l6-6M21 19l-6-6"/>',
    'unread' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="18" cy="6" r="3" fill="currentColor"/>',
    'move' => '<path d="M3 7h6l2 2h10v10H3z"/><path d="M12 11v6M9 14l3 3 3-3"/>',
    'refresh' => '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'attachment' => '<path d="m21 12-8.5 8.5a5 5 0 0 1-7-7L14 5a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L16 7"/>',
    'cloud' => '<path d="M7 18a5 5 0 0 1-.6-9.96A6 6 0 0 1 18 9a4.5 4.5 0 0 1-.5 9z"/><path d="M12 11v5M9.5 13.5 12 11l2.5 2.5"/>',
    'open' => '<path d="M14 3h7v7M21 3l-9 9"/><path d="M19 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h5"/>',
    'plus' => '<path d="M12 5v14M5 12h14"/>',
    'check' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
    'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
    'prev' => '<path d="m15 5-7 7 7 7"/>',
    'next' => '<path d="m9 5 7 7-7 7"/>',
    'today' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><circle cx="12" cy="15" r="2" fill="currentColor"/>',
    'day' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/>',
    'week' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11M15 9v11"/>',
    'month' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11M15 9v11M3 14.5h18"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21a2 2 0 0 0 4 0"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7M12 17h.01"/>',
    'print' => '<path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7"/>',
    'accept' => '<path d="M20 6 9 17l-5-5"/>',
    'tentative' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7M12 17h.01"/>',
    'decline' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
    'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
    'panel-left' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
    'panel-right' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
    'save' => '<path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
    'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
];
$icon = static fn (string $name, string $class = 'ov-icon'): string => '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';
$rb = static function (string $iconName, string $label, string $attributes, bool $small = false) use ($icon): string {
    return '<button type="button" class="ov-rb' . ($small ? ' ov-rb--small' : '') . '" ' . $attributes . '>' . $icon($iconName) . '<span>' . Html::e($label) . '</span></button>';
};
$modules = [
    'mail' => ['Mail', 'mail'],
    'calendar' => ['Kalender', 'calendar'],
    'contacts' => ['Kontakte', 'contacts'],
    'tasks' => ['Aufgaben', 'tasks'],
    'notes' => ['Notizen', 'notes'],
];
// Module, die das Mail-Backend nicht unterstuetzt (z. B. Kalender beim
// SMTP-/IMAP-Proxy), werden nicht angeboten.
$capabilities = is_array($orvanta['capabilities'] ?? null) ? $orvanta['capabilities'] : [];
$modules = array_filter($modules, static fn (string $key): bool => ($capabilities[$key] ?? true) === true, ARRAY_FILTER_USE_KEY);
$moduleFor = ['inbox' => 'mail', 'calendar' => 'calendar', 'contacts' => 'contacts', 'tasks' => 'tasks', 'notes' => 'notes'];
$startModule = $moduleFor[(string) $orvanta['defaultModule']] ?? 'mail';
if (!isset($modules[$startModule])) {
    $startModule = 'mail';
}
$isProxy = ($orvanta['backend'] ?? 'exchange') === 'proxy';
$userName = (string) $orvanta['user']['name'];
$initials = '';
foreach (preg_split('/\s+/', $userName) ?: [] as $part) {
    if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
}
$initials = mb_substr($initials !== '' ? $initials : '?', 0, 2);
?>
<div class="ov-office" data-orvanta
     data-config="<?= Html::e((string) json_encode($orvanta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     data-csrf="<?= Html::e($csrfToken) ?>"
     data-module="<?= Html::e($startModule) ?>">

    <!-- Titelleiste -->
    <header class="ov-titlebar">
        <a class="ov-titlebar__brand" href="/office-starten" title="Zurück zur Office-Übersicht">
            <img class="ov-logo" src="/assets/images/orvanta-logo.png?v=<?= Html::e($assetVersion) ?>" alt="" width="40" height="35">
            <span class="ov-titlebar__name">Orvanta</span>
            <span class="ov-titlebar__sub">Mail &amp; Kalender</span>
        </a>
        <?php if (!empty($orvanta['demo'])) { ?><span class="ov-badge ov-badge--demo" title="Beispieldaten ohne Exchange-Server">Demo</span><?php } ?>
        <div class="ov-titlebar__search" role="search">
            <?= $icon('search') ?>
            <input type="search" data-ov-search placeholder="Suchen in Mail, Kalender, Kontakten …" aria-label="Suchen" autocomplete="off">
            <button type="button" class="ov-titlebar__search-clear" data-ov-search-clear aria-label="Suche zurücksetzen" hidden><?= $icon('close') ?></button>
        </div>
        <div class="ov-titlebar__actions">
            <button type="button" class="ov-qb" data-ov-reminders-toggle title="Erinnerungen" aria-label="Erinnerungen"<?= ($capabilities['reminders'] ?? true) === true ? '' : ' hidden' ?>>
                <?= $icon('bell') ?><span class="ov-qb__count" data-ov-reminders-count hidden>0</span>
            </button>
            <button type="button" class="ov-qb" data-ov-dialog-open="settings" title="Einstellungen (⚙)" aria-label="Einstellungen"><?= $icon('settings') ?></button>
            <button type="button" class="ov-qb ov-qb--profile" data-ov-dialog-open="profile" title="<?= Html::e($userName) ?>" aria-label="Profil">
                <span class="ov-avatar" aria-hidden="true"><?= Html::e($initials) ?></span>
                <span class="ov-qb__text"><?= Html::e($userName) ?></span>
            </button>
        </div>
    </header>

    <!-- Ribbon -->
    <div class="ov-ribbon">
        <div class="ov-ribbon__tabs" role="tablist" aria-label="Menüband">
            <button type="button" role="tab" aria-selected="true" data-ov-tab="start" id="ov-tab-start" aria-controls="ov-panel-start">Start</button>
            <button type="button" role="tab" aria-selected="false" data-ov-tab="view" id="ov-tab-view" aria-controls="ov-panel-view">Ansicht</button>
            <button type="button" role="tab" aria-selected="false" data-ov-tab="help" id="ov-tab-help" aria-controls="ov-panel-help">Hilfe</button>
        </div>

        <!-- Start: Mail -->
        <div class="ov-ribbon__panel" id="ov-panel-start" role="tabpanel" data-ov-panel="start" data-ov-for="mail">
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('compose', 'Neue E-Mail', 'data-ov-action="compose"') ?></div><div class="ov-rg__label">Neu</div></div>
            <?php if (($capabilities['tasks'] ?? true) === true) { ?>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('tasks', 'In Aufgabe übernehmen', 'data-ov-action="mail-to-task" data-ov-needs="message"') ?></div><div class="ov-rg__label">Aufgabe</div></div>
            <?php } ?>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('trash', 'Löschen', 'data-ov-action="mail-delete" data-ov-needs="message"') ?><?= $rb('archive', 'Archivieren', 'data-ov-action="mail-archive" data-ov-needs="message"') ?><?= $rb('move', 'Verschieben', 'data-ov-action="mail-move" data-ov-needs="message"') ?></div><div class="ov-rg__label">Löschen</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('reply', 'Antworten', 'data-ov-action="reply" data-ov-needs="message"') ?><?= $rb('replyall', 'Allen antworten', 'data-ov-action="replyall" data-ov-needs="message"') ?><?= $rb('forward', 'Weiterleiten', 'data-ov-action="forward" data-ov-needs="message"') ?></div><div class="ov-rg__label">Antworten</div></div>
            <div class="ov-rg"><div class="ov-rg__items ov-rg__items--column"><?= $rb('unread', 'Ungelesen/Gelesen', 'data-ov-action="toggle-read" data-ov-needs="message"', true) ?><?= $rb('flag', 'Zur Nachverfolgung', 'data-ov-action="toggle-flag" data-ov-needs="message"', true) ?><?= $rb('print', 'Drucken', 'data-ov-action="print" data-ov-needs="message"', true) ?></div><div class="ov-rg__label">Kategorien</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('refresh', 'Senden/Empfangen', 'data-ov-action="refresh"') ?></div><div class="ov-rg__label">Senden/Empfangen</div></div>
        </div>
        <!-- Start: Kalender -->
        <div class="ov-ribbon__panel" role="tabpanel" data-ov-panel="start" data-ov-for="calendar" hidden>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('plus', 'Neuer Termin', 'data-ov-action="event-new"') ?><?= $rb('contacts', 'Neue Besprechung', 'data-ov-action="meeting-new"') ?></div><div class="ov-rg__label">Neu</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('today', 'Heute', 'data-ov-action="cal-today"') ?><?= $rb('prev', 'Zurück', 'data-ov-action="cal-prev"') ?><?= $rb('next', 'Weiter', 'data-ov-action="cal-next"') ?></div><div class="ov-rg__label">Gehe zu</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('day', 'Tag', 'data-ov-action="cal-view" data-ov-value="day" aria-pressed="false"') ?><?= $rb('week', 'Arbeitswoche', 'data-ov-action="cal-view" data-ov-value="workweek" aria-pressed="false"') ?><?= $rb('week', 'Woche', 'data-ov-action="cal-view" data-ov-value="week" aria-pressed="true"') ?><?= $rb('month', 'Monat', 'data-ov-action="cal-view" data-ov-value="month" aria-pressed="false"') ?><?= $rb('list', 'Liste', 'data-ov-action="cal-view" data-ov-value="agenda" aria-pressed="false"') ?></div><div class="ov-rg__label">Anordnen</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('edit', 'Bearbeiten', 'data-ov-action="event-edit" data-ov-needs="event"') ?><?= $rb('trash', 'Löschen', 'data-ov-action="event-delete" data-ov-needs="event"') ?><?= $rb('refresh', 'Aktualisieren', 'data-ov-action="refresh"') ?></div><div class="ov-rg__label">Aktionen</div></div>
        </div>
        <!-- Start: Kontakte -->
        <div class="ov-ribbon__panel" role="tabpanel" data-ov-panel="start" data-ov-for="contacts" hidden>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('plus', 'Neuer Kontakt', 'data-ov-action="contact-new"') ?></div><div class="ov-rg__label">Neu</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('edit', 'Bearbeiten', 'data-ov-action="contact-edit" data-ov-needs="contact"') ?><?= $rb('trash', 'Löschen', 'data-ov-action="contact-delete" data-ov-needs="contact"') ?><?= $rb('mail', 'E-Mail senden', 'data-ov-action="contact-mail" data-ov-needs="contact"') ?><?= $rb('calendar', 'Besprechung', 'data-ov-action="contact-meeting" data-ov-needs="contact"') ?></div><div class="ov-rg__label">Aktionen</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('refresh', 'Aktualisieren', 'data-ov-action="refresh"') ?></div><div class="ov-rg__label">Ansicht</div></div>
        </div>
        <!-- Start: Aufgaben -->
        <div class="ov-ribbon__panel" role="tabpanel" data-ov-panel="start" data-ov-for="tasks" hidden>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('plus', 'Neue Aufgabe', 'data-ov-action="task-new"') ?></div><div class="ov-rg__label">Neu</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('check', 'Als erledigt', 'data-ov-action="task-complete" data-ov-needs="task"') ?><?= $rb('edit', 'Bearbeiten', 'data-ov-action="task-edit" data-ov-needs="task"') ?><?= $rb('trash', 'Löschen', 'data-ov-action="task-delete" data-ov-needs="task"') ?></div><div class="ov-rg__label">Aufgabe verwalten</div></div>
            <div class="ov-rg"><div class="ov-rg__items ov-rg__items--column"><label class="ov-rg__check"><input type="checkbox" data-ov-tasks-completed checked> Erledigte anzeigen</label><?= $rb('refresh', 'Aktualisieren', 'data-ov-action="refresh"', true) ?></div><div class="ov-rg__label">Ansicht</div></div>
        </div>
        <!-- Start: Notizen -->
        <div class="ov-ribbon__panel" role="tabpanel" data-ov-panel="start" data-ov-for="notes" hidden>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('plus', 'Neue Notiz', 'data-ov-action="note-new"') ?></div><div class="ov-rg__label">Neu</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('save', 'Speichern', 'data-ov-action="note-save" data-ov-needs="note"') ?><?= $rb('trash', 'Löschen', 'data-ov-action="note-delete" data-ov-needs="note"') ?><?= $rb('refresh', 'Aktualisieren', 'data-ov-action="refresh"') ?></div><div class="ov-rg__label">Aktionen</div></div>
        </div>

        <!-- Ansicht -->
        <div class="ov-ribbon__panel" id="ov-panel-view" role="tabpanel" data-ov-panel="view" hidden>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('panel-left', 'Ordnerbereich', 'data-ov-action="toggle-folders" aria-pressed="true"') ?><?= $rb('panel-right', 'Lesebereich', 'data-ov-action="toggle-reading" aria-pressed="true"') ?></div><div class="ov-rg__label">Layout</div></div>
            <div class="ov-rg"><div class="ov-rg__items ov-rg__items--column"><label class="ov-rg__check"><input type="checkbox" data-ov-pref="dense"> Kompakte Liste</label><label class="ov-rg__check"><input type="checkbox" data-ov-pref="preview" checked> Vorschautext anzeigen</label><label class="ov-rg__check"><input type="checkbox" data-ov-pref="images"> Externe Bilder in E-Mails laden</label></div><div class="ov-rg__label">Darstellung</div></div>
            <div class="ov-rg"><div class="ov-rg__items ov-rg__items--column"><label class="ov-rg__check"><input type="checkbox" data-ov-pref="notify" checked> Desktop-Benachrichtigungen</label><label class="ov-rg__check"><input type="checkbox" data-ov-pref="sound"> Hinweiston bei Erinnerungen</label><?= $rb('bell', 'Berechtigung anfragen', 'data-ov-action="notify-permission"', true) ?></div><div class="ov-rg__label">Erinnerungen</div></div>
            <?php if (!empty($orvanta['owaUrl'])) { ?>
            <div class="ov-rg"><div class="ov-rg__items"><a class="ov-rb" href="<?= Html::e((string) $orvanta['owaUrl']) ?>" target="_blank" rel="noopener"><?= $icon('open') ?><span>Outlook Web App</span></a></div><div class="ov-rg__label">Extern</div></div>
            <?php } ?>
        </div>

        <!-- Hilfe -->
        <div class="ov-ribbon__panel" id="ov-panel-help" role="tabpanel" data-ov-panel="help" hidden>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('help', 'Kurzanleitung', 'data-ov-dialog-open="help"') ?></div><div class="ov-rg__label">Hilfe</div></div>
            <div class="ov-rg"><div class="ov-rg__items"><?= $rb('cloud', 'Zwischenspeicher', 'data-ov-dialog-open="settings"') ?></div><div class="ov-rg__label">Nextcloud</div></div>
        </div>
    </div>

    <!-- Arbeitsbereich -->
    <div class="ov-workspace" data-ov-workspace>
        <nav class="ov-modules" aria-label="Module">
            <?php foreach ($modules as $key => [$label, $iconName]) { ?>
                <button type="button" class="ov-module" data-ov-module="<?= $key ?>" aria-pressed="<?= $key === $startModule ? 'true' : 'false' ?>" title="<?= Html::e($label) ?>">
                    <?= $icon($iconName) ?><span><?= Html::e($label) ?></span>
                    <span class="ov-module__count" data-ov-module-count="<?= $key ?>" hidden></span>
                </button>
            <?php } ?>
            <div class="ov-modules__spacer"></div>
            <div class="ov-modules__quota" data-ov-mailbox title="Belegung des Postfachs auf dem Exchange-Server">
                <?= $icon('mail') ?>
                <span class="ov-quota__bar"><span class="ov-quota__fill" data-ov-mailbox-fill></span></span>
                <span class="ov-quota__text" data-ov-mailbox-text>–</span>
            </div>
            <div class="ov-modules__quota" data-ov-quota title="Zwischenspeicher für Anhänge im Nextcloud-Bereich">
                <?= $icon('cloud') ?>
                <span class="ov-quota__bar"><span class="ov-quota__fill" data-ov-quota-fill></span></span>
                <span class="ov-quota__text" data-ov-quota-text>–</span>
            </div>
        </nav>

        <aside class="ov-folders" data-ov-folders aria-label="Ordner">
            <div class="ov-folders__head"><span data-ov-folders-title>Ordner</span><button type="button" class="ov-mini" data-ov-action="refresh" title="Aktualisieren"><?= $icon('refresh') ?></button></div>
            <div class="ov-folders__body" data-ov-folders-body><p class="ov-muted">Wird geladen …</p></div>
        </aside>

        <section class="ov-list" data-ov-list aria-label="Elemente">
            <div class="ov-list__head">
                <h2 class="ov-list__title" data-ov-list-title>Posteingang</h2>
                <div class="ov-list__tools">
                    <select class="ov-select" data-ov-list-filter aria-label="Filter">
                        <option value="all">Alle</option>
                        <option value="unread">Ungelesen</option>
                        <option value="flagged">Gekennzeichnet</option>
                        <option value="attachments">Mit Anhang</option>
                    </select>
                </div>
            </div>
            <div class="ov-list__body" data-ov-list-body tabindex="0"><p class="ov-muted">Wird geladen …</p></div>
            <div class="ov-list__foot" data-ov-list-foot hidden><button type="button" class="ov-link" data-ov-action="more">Weitere laden</button></div>
        </section>

        <section class="ov-detail" data-ov-detail aria-live="polite">
            <div class="ov-detail__empty" data-ov-detail-empty>
                <img src="/assets/images/orvanta-logo.png?v=<?= Html::e($assetVersion) ?>" alt="" width="160" height="141" class="ov-detail__logo">
                <p>Wählen Sie ein Element aus, um es hier anzuzeigen.</p>
            </div>
            <div class="ov-detail__body" data-ov-detail-body hidden></div>
        </section>
    </div>

    <!-- Statusleiste -->
    <footer class="ov-statusbar">
        <span data-ov-status-conn><?= $icon('check') ?> Verbunden<?= !empty($orvanta['demo']) ? ' (Demo)' : ($isProxy ? ' (IMAP/SMTP)' : '') ?></span>
        <span data-ov-status-count></span>
        <span class="ov-statusbar__grow" data-ov-status-text></span>
        <button type="button" class="ov-statusbar__credit" data-ov-dialog-open="about" title="Hinweise zu Orvanta anzeigen">Orvanta Mail-App by Daniel-André Reinelt</button>
        <?php if (!empty($orvanta['aiAvailable'])) { ?>
            <button type="button" class="ov-ai-indicator" data-ov-ai-indicator title="KI-Unterstützung verfügbar – Text markieren und Rechtsklick" aria-label="KI-Unterstützung verfügbar: Hinweise anzeigen">
                <img src="/assets/images/orvanta-ai-robot-small.png" srcset="/assets/images/orvanta-ai-robot-small@2x.png 2x" width="18" height="24" alt="">
            </button>
        <?php } ?>
        <span data-ov-status-sync title="Letzte Aktualisierung">–</span>
        <span><?= $icon('user') ?> <?= Html::e((string) $orvanta['user']['email']) ?></span>
    </footer>

    <!-- Erinnerungen (Popover) -->
    <div class="ov-reminders" data-ov-reminders hidden>
        <div class="ov-reminders__head"><?= $icon('bell') ?> <strong>Erinnerungen</strong><button type="button" class="ov-mini" data-ov-reminders-close aria-label="Schließen"><?= $icon('close') ?></button></div>
        <div class="ov-reminders__body" data-ov-reminders-body><p class="ov-muted">Keine anstehenden Erinnerungen.</p></div>
    </div>
    <div class="ov-toasts" data-ov-toasts aria-live="assertive"></div>

    <!-- Dialog: E-Mail schreiben -->
    <dialog class="ov-dialog ov-dialog--wide" data-ov-dialog="compose">
        <form method="dialog" class="ov-dialog__form" data-ov-form="compose">
            <div class="ov-dialog__head">
                <h2 data-ov-compose-title>Neue E-Mail</h2>
                <div class="ov-dialog__head-actions">
                    <button type="button" class="ov-mini ov-mini--light" data-ov-action="compose-detach" title="In neuem Tab öffnen – Text und Anhänge werden übernommen" aria-label="In neuem Tab öffnen"><?= $icon('open') ?></button>
                    <button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button>
                </div>
            </div>
            <div class="ov-dialog__body">
                <input type="hidden" name="mode" value="new"><input type="hidden" name="reply_id" value="">
                <label class="ov-field"><span>An</span><input type="text" name="to" placeholder="name@firma.de; …" autocomplete="off" required data-ov-recipients></label>
                <div class="ov-field-row">
                    <label class="ov-field"><span>Cc</span><input type="text" name="cc" autocomplete="off" data-ov-recipients></label>
                    <label class="ov-field"><span>Bcc</span><input type="text" name="bcc" autocomplete="off" data-ov-recipients></label>
                </div>
                <div class="ov-field-row">
                    <label class="ov-field ov-field--grow"><span>Betreff</span><input type="text" name="subject" maxlength="255" required></label>
                    <label class="ov-field"><span>Priorität</span><select name="importance"><option value="Normal">Normal</option><option value="High">Hoch</option><option value="Low">Niedrig</option></select></label>
                </div>
                <div class="ov-editor-tools" role="toolbar" aria-label="Formatierung">
                    <button type="button" data-ov-fmt="bold" title="Fett"><b>F</b></button>
                    <button type="button" data-ov-fmt="italic" title="Kursiv"><i>K</i></button>
                    <button type="button" data-ov-fmt="underline" title="Unterstrichen"><u>U</u></button>
                    <button type="button" data-ov-fmt="insertUnorderedList" title="Aufzählung">•≡</button>
                    <button type="button" data-ov-fmt="insertOrderedList" title="Nummerierung">1≡</button>
                    <button type="button" data-ov-fmt="removeFormat" title="Formatierung entfernen">Tx</button>
                    <span class="ov-editor-tools__spacer"></span>
                    <label class="ov-attach-btn"><?= $icon('attachment') ?> Anhang <input type="file" multiple hidden data-ov-attach-input></label>
                </div>
                <div class="ov-editor" contenteditable="true" data-ov-compose-body aria-label="Nachrichtentext"></div>
                <ul class="ov-attach-list" data-ov-attach-list></ul>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot">
                <button type="button" class="button button--primary" data-ov-action="send"><?= $icon('send') ?> Senden</button>
                <button type="button" class="button" data-ov-action="save-draft">Entwurf speichern</button>
                <button type="button" class="button button--ghost" data-ov-dialog-close>Verwerfen</button>
            </div>
        </form>
    </dialog>
    <div class="ov-standalone-done" data-ov-standalone-done hidden>
        <p data-ov-standalone-done-text>Dieses Fenster kann geschlossen werden.</p>
        <a class="button" href="/office/orvanta?modul=mail">Zu Orvanta</a>
    </div>

    <!-- Dialog: Termin -->
    <dialog class="ov-dialog" data-ov-dialog="event">
        <form method="dialog" class="ov-dialog__form" data-ov-form="event">
            <div class="ov-dialog__head"><h2 data-ov-event-title>Neuer Termin</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <input type="hidden" name="id" value=""><input type="hidden" name="change_key" value="">
                <label class="ov-field"><span>Betreff</span><input type="text" name="subject" maxlength="255" required></label>
                <label class="ov-field"><span>Ort</span><input type="text" name="location" maxlength="255"></label>
                <div class="ov-field-row">
                    <label class="ov-field"><span>Beginn</span><input type="datetime-local" name="start" required></label>
                    <label class="ov-field"><span>Ende</span><input type="datetime-local" name="end" required></label>
                </div>
                <div class="ov-field-row">
                    <label class="ov-check"><input type="checkbox" name="all_day"> Ganztägig</label>
                    <label class="ov-field"><span>Erinnerung</span><select name="reminder"><option value="-1">Keine</option><option value="0">Zum Beginn</option><option value="5">5 Minuten</option><option value="15" selected>15 Minuten</option><option value="30">30 Minuten</option><option value="60">1 Stunde</option><option value="1440">1 Tag</option></select></label>
                    <label class="ov-field"><span>Anzeigen als</span><select name="free_busy"><option value="Busy">Beschäftigt</option><option value="Free">Frei</option><option value="Tentative">Mit Vorbehalt</option><option value="OOF">Abwesend</option></select></label>
                </div>
                <label class="ov-field"><span>Teilnehmer (erforderlich)</span><input type="text" name="required" placeholder="name@firma.de; …" data-ov-recipients></label>
                <label class="ov-field"><span>Teilnehmer (optional)</span><input type="text" name="optional" data-ov-recipients></label>
                <div class="ov-field"><span id="ov-event-body-label">Beschreibung</span><div class="ov-editor ov-editor--event" contenteditable="true" data-ov-event-body aria-labelledby="ov-event-body-label"></div></div>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot">
                <button type="submit" class="button button--primary"><?= $icon('save') ?> Speichern</button>
                <button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button>
            </div>
        </form>
    </dialog>

    <!-- Dialog: Kontakt -->
    <dialog class="ov-dialog" data-ov-dialog="contact">
        <form method="dialog" class="ov-dialog__form" data-ov-form="contact">
            <div class="ov-dialog__head"><h2 data-ov-contact-title>Neuer Kontakt</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <input type="hidden" name="id" value="">
                <div class="ov-field-row">
                    <label class="ov-field"><span>Vorname</span><input type="text" name="given_name" maxlength="120"></label>
                    <label class="ov-field"><span>Nachname</span><input type="text" name="surname" maxlength="120"></label>
                </div>
                <div class="ov-field-row">
                    <label class="ov-field"><span>Firma</span><input type="text" name="company" maxlength="120"></label>
                    <label class="ov-field"><span>Position</span><input type="text" name="job_title" maxlength="120"></label>
                    <label class="ov-field"><span>Abteilung</span><input type="text" name="department" maxlength="120"></label>
                </div>
                <label class="ov-field"><span>E-Mail</span><input type="email" name="email" maxlength="190"></label>
                <div class="ov-field-row">
                    <label class="ov-field"><span>Telefon (geschäftlich)</span><input type="tel" name="phone" maxlength="60"></label>
                    <label class="ov-field"><span>Mobil</span><input type="tel" name="mobile" maxlength="60"></label>
                </div>
                <label class="ov-field"><span>Notizen</span><textarea name="notes" rows="3"></textarea></label>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot">
                <button type="submit" class="button button--primary"><?= $icon('save') ?> Speichern</button>
                <button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button>
            </div>
        </form>
    </dialog>

    <!-- Dialog: Aufgabe -->
    <dialog class="ov-dialog" data-ov-dialog="task">
        <form method="dialog" class="ov-dialog__form" data-ov-form="task">
            <div class="ov-dialog__head"><h2 data-ov-task-title>Neue Aufgabe</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <input type="hidden" name="id" value="">
                <label class="ov-field"><span>Betreff</span><input type="text" name="subject" maxlength="255" required></label>
                <div class="ov-field-row">
                    <label class="ov-field"><span>Beginn</span><input type="date" name="start"></label>
                    <label class="ov-field"><span>Fällig am</span><input type="date" name="due"></label>
                    <label class="ov-field"><span>Erinnerung</span><input type="datetime-local" name="reminder"></label>
                </div>
                <div class="ov-field-row">
                    <label class="ov-field"><span>Status</span><select name="status"><option value="NotStarted">Nicht begonnen</option><option value="InProgress">In Bearbeitung</option><option value="Completed">Erledigt</option><option value="WaitingOnOthers">Wartet auf andere</option><option value="Deferred">Zurückgestellt</option></select></label>
                    <label class="ov-field"><span>Priorität</span><select name="importance"><option value="Normal">Normal</option><option value="High">Hoch</option><option value="Low">Niedrig</option></select></label>
                    <label class="ov-field"><span>% erledigt</span><input type="number" name="percent" min="0" max="100" step="5" value="0"></label>
                </div>
                <label class="ov-field"><span>Notizen</span><textarea name="body" rows="4"></textarea></label>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot">
                <button type="submit" class="button button--primary"><?= $icon('save') ?> Speichern</button>
                <button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button>
            </div>
        </form>
    </dialog>

    <!-- Dialog: Postfach-Kennwort (nur Proxy-Postfaecher; Mailserver lehnt das hinterlegte Kennwort ab) -->
    <dialog class="ov-dialog ov-dialog--small" data-ov-dialog="mail-password">
        <form method="dialog" class="ov-dialog__form" data-ov-form="mail-password">
            <div class="ov-dialog__head"><h2>Kennwort des Postfachs</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <p data-ov-mail-password-reason>Der Mailserver hat die Anmeldung abgelehnt. Möglicherweise wurde das Kennwort geändert.</p>
                <p class="ov-muted">Bitte das aktuelle Kennwort für <strong data-ov-mail-password-email></strong> eingeben. Es wird zuerst am Mailserver geprüft und bei Erfolg für Orvanta gespeichert.</p>
                <label class="ov-field"><span>Aktuelles Kennwort</span><input type="password" name="password" maxlength="4096" required autocomplete="current-password"></label>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot"><button type="submit" class="button button--primary">Anmelden</button><button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button></div>
        </form>
    </dialog>

    <!-- Dialog: Verschieben -->
    <dialog class="ov-dialog ov-dialog--small" data-ov-dialog="move">
        <form method="dialog" class="ov-dialog__form" data-ov-form="move">
            <div class="ov-dialog__head"><h2>In Ordner verschieben</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body"><label class="ov-field"><span>Zielordner</span><select name="folder" data-ov-move-folders></select></label></div>
            <div class="ov-dialog__foot"><button type="submit" class="button button--primary">Verschieben</button><button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button></div>
        </form>
    </dialog>

    <!-- Dialog: Neuer Ordner (Kontextmenue im Ordnerbaum) -->
    <dialog class="ov-dialog ov-dialog--small" data-ov-dialog="folder-new">
        <form method="dialog" class="ov-dialog__form" data-ov-form="folder-new">
            <div class="ov-dialog__head"><h2>Neuer Ordner</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <input type="hidden" name="parent" value="">
                <p class="ov-muted">Wird angelegt in: <strong data-ov-folder-new-parent></strong></p>
                <label class="ov-field"><span>Name</span><input type="text" name="name" maxlength="255" required autocomplete="off"></label>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot"><button type="submit" class="button button--primary">Anlegen</button><button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button></div>
        </form>
    </dialog>

    <!-- Dialog: Ordnereigenschaften (Kontextmenue im Ordnerbaum) -->
    <dialog class="ov-dialog ov-dialog--small" data-ov-dialog="folder-props">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2>Eigenschaften – <span data-ov-folder-props-title></span></h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body"><dl class="ov-dl" data-ov-folder-props aria-live="polite"></dl></div>
            <div class="ov-dialog__foot"><button type="button" class="button button--primary" data-ov-dialog-close>Schließen</button></div>
        </div>
    </dialog>

    <!-- Dialog: Info (rohe Kopfzeilen) -->
    <dialog class="ov-dialog ov-dialog--wide ov-dialog--headers" data-ov-dialog="headers">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2>Nachrichteninfo – Kopfzeilen</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <p class="ov-muted" data-ov-headers-subject></p>
                <pre class="ov-headers" tabindex="0" data-ov-headers-raw></pre>
            </div>
            <div class="ov-dialog__foot"><button type="button" class="button" data-ov-action="headers-copy">Kopieren</button><button type="button" class="button button--primary" data-ov-dialog-close>Schließen</button></div>
        </div>
    </dialog>

    <!-- Dialog: Über Orvanta -->
    <dialog class="ov-dialog" data-ov-dialog="about">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2>Orvanta Mail-App</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <p>Orvanta ist ein Phantasiename. Ähnlichkeiten zu anderen Bedeutungen sind rein zufällig. Teile der Applikation wurden mit Hilfe von KI entwickelt (Claude Sonnet 4.8, Claude Opus 5, 5.5, Claude Fable 5.1, Deepseek Pro v4).</p>
            </div>
            <div class="ov-dialog__foot"><button type="button" class="button button--primary" data-ov-dialog-close>Schließen</button></div>
        </div>
    </dialog>

    <!-- Dialog: Einstellungen -->
    <dialog class="ov-dialog" data-ov-dialog="settings">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2><?= $icon('settings') ?> Einstellungen</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body ov-settings">
                <h3>Postfach auf dem Exchange-Server</h3>
                <p class="ov-muted">Belegter Speicher Ihres Postfachs (alle Ordner) und die vom Server gesetzte Grenze. Bei Erreichen der Grenze können keine E-Mails mehr gesendet werden.</p>
                <div class="ov-quota ov-quota--large"><span class="ov-quota__bar"><span class="ov-quota__fill" data-ov-mailbox-fill></span></span><span data-ov-mailbox-text>–</span></div>
                <p class="ov-muted" data-ov-mailbox-detail hidden></p>
                <h3>Zwischenspeicher für Anhänge</h3>
                <p class="ov-muted">Geöffnete Anhänge werden in Ihrem Nextcloud-Bereich im Ordner <code><?= Html::e((string) $orvanta['cacheFolder']) ?>/Zwischenspeicher</code> abgelegt. Bei Erreichen des Quotas werden die ältesten Dateien automatisch entfernt.</p>
                <div class="ov-quota ov-quota--large"><span class="ov-quota__bar"><span class="ov-quota__fill" data-ov-quota-fill></span></span><span data-ov-quota-text>–</span></div>
                <div class="ov-inline-actions"><button type="button" class="button" data-ov-action="cache-clear">Zwischenspeicher leeren</button></div>
                <h3>Benachrichtigungen</h3>
                <p class="ov-muted">Terminerinnerungen erscheinen als Desktop-Benachrichtigung (Browser), als Hinweis in Orvanta und in den Mitteilungen des Intranets. Status: <strong data-ov-notify-state>unbekannt</strong></p>
                <div class="ov-inline-actions"><button type="button" class="button" data-ov-action="notify-permission">Desktop-Benachrichtigungen erlauben</button><button type="button" class="button button--ghost" data-ov-action="notify-test">Testbenachrichtigung</button></div>
                <?php if (!empty($orvanta['spellcheckAvailable'])) { ?>
                    <h3>Rechtschreibprüfung – eigenes Wörterbuch</h3>
                    <p class="ov-muted">Wörter, die Sie per Rechtsklick mit „Zum Wörterbuch hinzufügen“ aufgenommen haben, gelten auf allen Geräten als richtig geschrieben.</p>
                    <ul class="ov-spell-words" data-ov-spell-words aria-label="Eigene Wörter"></ul>
                <?php } ?>
                <h3>Verbindung</h3>
                <dl class="ov-dl" data-ov-settings-info></dl>
            </div>
            <div class="ov-dialog__foot"><button type="button" class="button button--primary" data-ov-dialog-close>Schließen</button></div>
        </div>
    </dialog>

    <!-- Dialog: Profil -->
    <dialog class="ov-dialog ov-dialog--small" data-ov-dialog="profile">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2><?= $icon('user') ?> Profil</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body ov-profile">
                <span class="ov-avatar ov-avatar--large" aria-hidden="true"><?= Html::e($initials) ?></span>
                <div><strong><?= Html::e($userName) ?></strong><br><span class="ov-muted"><?= Html::e((string) $orvanta['user']['email']) ?></span><br><span class="ov-muted">Anmeldung: <?= Html::e((string) $orvanta['user']['username']) ?> (Windows-Anmeldung)</span></div>
            </div>
            <div class="ov-dialog__foot"><a class="button" href="/office-starten">Office-Übersicht</a><button type="button" class="button button--primary" data-ov-dialog-close>Schließen</button></div>
        </div>
    </dialog>

    <!-- Dialog: Hilfe -->
    <dialog class="ov-dialog" data-ov-dialog="help">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2><?= $icon('help') ?> Kurzanleitung</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body ov-help">
                <p><strong>Module</strong> wechseln Sie links: Mail, Kalender, Kontakte, Aufgaben, Notizen. Die mittlere Spalte listet die Elemente, rechts erscheint das Detail.</p>
                <?php if (($capabilities['tasks'] ?? true) === true) { ?>
                <p><strong>E-Mail als Aufgabe:</strong> Bei geöffneter Nachricht übernimmt „In Aufgabe übernehmen“ (Menüband <em>Start</em>) den Betreff als Titel und den Text als Notiz in eine neue Aufgabe. Fälligkeit, Erinnerung und Priorität ergänzen Sie im Dialog.</p>
                <?php } ?>
                <p><strong>Anhänge</strong> öffnen sich per Klick in einem neuen Tab in Euro-Office (Word, Excel, PowerPoint, PDF). Mit „In Nextcloud speichern“ legen Sie den Anhang dauerhaft in Ihrem Nextcloud-Ordner ab.</p>
                <p><strong>Erinnerungen</strong> zu Terminen erscheinen als Desktop-Benachrichtigung, als Hinweis in Orvanta und in den Mitteilungen des Intranets. Sie können sie verschieben („Später“) oder schließen.</p>
                <?php if (!empty($orvanta['aiAvailable'])) { ?>
                    <div class="ov-help__ai" id="ov-help-ai">
                        <img src="/assets/images/orvanta-ai-robot.png" srcset="/assets/images/orvanta-ai-robot@2x.png 2x" width="90" height="120" alt="" class="ov-help__robot">
                        <div>
                            <p><strong>KI-Unterstützung</strong> beim Schreiben: Markieren Sie in einer E-Mail, einem Termin oder einer Erinnerung einen Textabschnitt und öffnen Sie mit der <strong>rechten Maustaste</strong> das Menü „Mit KI verbessern“. Beschreiben Sie kurz, was geändert werden soll (z.&nbsp;B. „höflicher“, „kürzer“, „als Aufzählung“).</p>
                            <p>Der erzeugte Text erscheint <span class="ov-ai-block ov-ai-block--sample">hellblau umrandet</span>. Per Rechtsklick darauf können Sie ihn weiter verfeinern, auf den ursprünglichen Text zurücksetzen oder die Markierung entfernen. Beim Senden bzw. Speichern wird die Markierung automatisch entfernt – Empfänger sehen nur den Text.</p>
                            <p class="ov-muted">Es wird nur der markierte Abschnitt übertragen (dazu Betreff und Anzahl der Empfänger), nie das gesamte Postfach. Verarbeitet wird im lokalen KI-Dienst des Intranets.</p>
                        </div>
                    </div>
                <?php } ?>
                <p><strong>Tastatur:</strong> <kbd>N</kbd> neues Element · <kbd>R</kbd> antworten · <kbd>Entf</kbd> löschen · <kbd>/</kbd> Suche · <kbd>Esc</kbd> Dialog schließen · <kbd>1</kbd>–<kbd>5</kbd> Modul wechseln.</p>
            </div>
            <div class="ov-dialog__foot"><button type="button" class="button button--primary" data-ov-dialog-close>Schließen</button></div>
        </div>
    </dialog>

    <!-- App-Kontextmenue (Ordnerbaum, Mail-Liste, Textfelder, KI-Unterstuetzung); Eintraege werden per JS gefuellt -->
    <div class="ov-ctx-menu" data-ov-ctx-menu role="menu" aria-label="Kontextmenü" hidden></div>

    <!-- Dialog: KI-Anweisung -->
    <dialog class="ov-dialog ov-dialog--small ov-dialog--ai" data-ov-dialog="ai">
        <form method="dialog" class="ov-dialog__form" data-ov-form="ai">
            <div class="ov-dialog__head"><h2><img src="/assets/images/orvanta-ai-robot-small.png" srcset="/assets/images/orvanta-ai-robot-small@2x.png 2x" width="15" height="20" alt="" class="ov-ai-dialog__icon"> <span data-ov-ai-title>Mit KI verbessern</span></h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body">
                <p class="ov-ai-dialog__excerpt"><span class="ov-muted">Markierter Text:</span> <span data-ov-ai-excerpt></span></p>
                <label class="ov-field"><span>Was soll geändert werden?</span><textarea name="prompt" rows="3" maxlength="1000" placeholder="z. B. höflicher formulieren, kürzer fassen, Rechtschreibung korrigieren …" required data-ov-ai-prompt></textarea></label>
                <div class="ov-ai-dialog__chips" data-ov-ai-chips>
                    <button type="button" data-ov-ai-chip="Formuliere den Text höflicher und professioneller.">Höflicher</button>
                    <button type="button" data-ov-ai-chip="Fasse den Text deutlich kürzer.">Kürzer</button>
                    <button type="button" data-ov-ai-chip="Korrigiere Rechtschreibung und Grammatik, ändere sonst nichts.">Korrigieren</button>
                    <button type="button" data-ov-ai-chip="Strukturiere den Text als klare Aufzählung.">Aufzählung</button>
                    <button type="button" data-ov-ai-chip="Übersetze den Text ins Englische.">Englisch</button>
                </div>
                <p class="ov-muted" data-ov-ai-note>Übertragen werden nur dieser Abschnitt, Betreff und Empfängeranzahl.</p>
                <p class="ov-form-error" data-ov-form-error hidden></p>
            </div>
            <div class="ov-dialog__foot">
                <button type="submit" class="button button--primary" data-ov-ai-submit>Vorschlag erzeugen</button>
                <button type="button" class="button button--ghost" data-ov-dialog-close>Abbrechen</button>
            </div>
        </form>
    </dialog>

    <!-- Dialog: Erinnerung -->
    <dialog class="ov-dialog ov-dialog--small ov-dialog--reminder" data-ov-dialog="reminder">
        <div class="ov-dialog__form">
            <div class="ov-dialog__head"><h2><?= $icon('bell') ?> Terminerinnerung</h2><button type="button" class="ov-mini ov-mini--light" data-ov-dialog-close aria-label="Schließen"><?= $icon('close') ?></button></div>
            <div class="ov-dialog__body" data-ov-reminder-dialog-body></div>
            <div class="ov-dialog__foot">
                <select class="ov-select" data-ov-snooze-minutes><option value="5">5 Minuten</option><option value="10">10 Minuten</option><option value="15">15 Minuten</option><option value="30">30 Minuten</option><option value="60">1 Stunde</option></select>
                <button type="button" class="button" data-ov-action="snooze-all">Später erinnern</button>
                <button type="button" class="button button--primary" data-ov-action="dismiss-all">Alle schließen</button>
            </div>
        </div>
    </dialog>
</div>
