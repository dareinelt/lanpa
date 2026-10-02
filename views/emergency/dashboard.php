<?php
declare(strict_types=1);
use App\Support\Html;
?>
<link rel="stylesheet" href="/assets/css/kaep-dashboard.css?v=<?= Html::e($assetVersion) ?>">
<section id="kaep" data-actor="<?= Html::e($actor) ?>">
    <header class="kd-header">
        <div><span class="kd-eyebrow">Einsatzführung · Gemeinsames Lagebild</span><h1>KAEP-Dashboard</h1><small>Angemeldet: <?= Html::e($actor) ?></small></div>
        <div class="kd-tools">
            <span id="kd-connection" class="kd-badge" role="status">Verbindung wird aufgebaut …</span>
            <button type="button" class="button" id="kd-tv" aria-pressed="false">TV-Ansicht</button>
            <button type="button" class="button" id="kd-arrange" aria-pressed="false">Anordnung bearbeiten</button>
            <button type="button" class="button" id="kd-layout-reset" hidden>Standardanordnung</button>
            <button type="button" class="button" id="kd-print">Drucken</button>
        </div>
    </header>
    <p id="kd-error" class="kd-warning" role="alert" hidden></p>
    <p id="kd-layout-help" class="kd-hint" hidden>Abschnitte am Griff ziehen oder mit den Pfeilen verschieben. Die Pinnadel heftet Abschnitte vor allen anderen an. Nur für diesen Browser-Tab; bleibt beim Neuladen erhalten.</p>
    <p id="kd-layout-status" class="kd-layout-status" role="status"></p>
    <p class="kd-safety">Einsatzdokumentation ersetzt keinen Notruf. SMS-/SMTP-Annahme ist keine Zustell- oder Reaktionsbestätigung. Bei Ausfall Ersatzmeldeweg und Ersatzdokumentation nutzen.</p>
    <div class="kd-layout">
        <aside class="kd-sidebar kd-panel" aria-label="Ereignisauswahl">
            <h2>Ereignisse</h2>
            <label for="kd-event-status">Einsatzstatus</label>
            <select id="kd-event-status"><option value="active">Laufende Einsätze</option><option value="closed">Abgeschlossene Einsätze</option></select>
            <div id="kd-events"></div>
            <div class="kd-pagination"><button type="button" class="button" id="kd-prev">Zurück</button><span id="kd-page"></span><button type="button" class="button" id="kd-next">Weiter</button></div>
        </aside>
        <div class="kd-workspace">
            <div id="kd-empty" class="kd-panel">Lagebild wird geladen …</div>
            <div id="kd-content" hidden>
                <section class="kd-panel kd-overview" aria-label="Einsatzstatus" data-kd-panel="overview" data-kd-label="Einsatzstatus">
                    <div class="kd-title"><div><span id="kd-event-state" class="kd-badge"></span><h2 id="kd-title"></h2><p id="kd-start"></p></div><div class="kd-clock"><span id="kd-duration-label">Seit Auslösung</span><strong id="kd-duration">—</strong></div></div>
                </section>
                <section class="kd-panel" aria-label="Kennzahlen" data-kd-panel="metrics" data-kd-label="Kennzahlen">
                    <div id="kd-metrics" class="kd-metrics"></div>
                    <label for="kd-progress">Erledigte Maßnahmen (ohne entfallene Zweige)</label>
                    <progress id="kd-progress" max="1" value="0"></progress>
                </section>
                <section class="kd-panel kd-situation" data-kd-panel="situation" data-kd-label="Lageübersicht">
                    <div class="kd-section-title"><h2>Lageübersicht</h2><button type="button" class="button kd-edit" data-dialog="situation">Lage aktualisieren</button></div>
                    <p id="kd-situation" class="kd-pre"></p><strong id="kd-briefing"></strong>
                </section>
                <details class="kd-panel" id="kd-leadership" data-kd-panel="leadership" data-kd-label="Einsatz- und Bereichsleitungen">
                    <summary>Einsatz- und Bereichsleitungen</summary>
                    <div id="kd-leaders" class="kd-leaders"></div>
                    <button type="button" class="button kd-edit" data-dialog="leadership">Leitung / Bereich besetzen</button>
                    <p>Organisatorische Zuordnung, keine Änderung von Zugriffsrechten. Erreichbarkeit, geplante Ablösung und Schichtwechsel bitte dokumentieren.</p>
                </details>
                <section class="kd-panel kd-schedule" aria-labelledby="kd-schedule-title" data-kd-panel="schedule" data-kd-label="Zeitplan und Wiedervorlagen">
                    <div class="kd-section-title"><h2 id="kd-schedule-title">Zeitplan und Wiedervorlagen</h2><button type="button" class="button kd-edit" data-dialog="reminder">Wiedervorlage anlegen</button></div>
                    <p class="kd-hint">Nächste Lagebesprechung, Zielzeiten offener Maßnahmen, geplante Ablösungen und Wiedervorlagen in zeitlicher Reihenfolge. Keine automatische Alarmierung bei Fristüberschreitung.</p>
                    <ol id="kd-schedule" class="kd-timeline"></ol>
                    <details id="kd-reminders-done-wrap" hidden><summary id="kd-reminders-done-summary">Erledigte Wiedervorlagen</summary><ul id="kd-reminders-done"></ul></details>
                </section>
                <section class="kd-measures-section" aria-labelledby="kd-measures-title" data-kd-panel="measures" data-kd-label="Maßnahmenlage">
                    <div class="kd-section-title"><h2 id="kd-measures-title">Maßnahmenlage</h2><span id="kd-updated"></span></div>
                    <div class="kd-filters">
                        <label>Maßnahmen suchen<input id="kd-search" type="search" placeholder="Titel, Zuständigkeit, Inhalt"></label>
                        <label>Anzeige<select id="kd-filter"><option value="all">Alle Maßnahmen</option><option value="outstanding">Noch offen</option><option value="critical">Kritisch / blockiert / überfällig</option><option value="unassigned">Ohne Zuständigkeit</option></select></label>
                    </div>
                    <p class="kd-hint">Element antippen: Details, Prüfpunkte, Entscheidung, Notizen und Zuständigkeit. Voraussetzungen bleiben verbindlich.</p>
                    <div id="kd-board" class="kd-board"></div>
                </section>
                <details class="kd-panel" id="kd-notifications" data-kd-panel="notifications" data-kd-label="Benachrichtigungsstatus">
                    <summary><span id="kd-notification-summary">Alarm- und Benachrichtigungsstatus</span></summary>
                    <ul id="kd-mail"></ul>
                </details>
                <section class="kd-panel" aria-labelledby="kd-journal-title" data-kd-panel="journal" data-kd-label="Einsatzjournal">
                    <div class="kd-section-title"><h2 id="kd-journal-title">Einsatzjournal</h2><div class="kd-tools kd-edit"><button type="button" class="button" data-dialog="journal">Notiz hinzufügen</button><button type="button" class="button" data-dialog="handover">Schichtübergabe</button></div></div>
                    <p>Neueste zuerst · Zeiten in Ihrer Gerätezeitzone (<span id="kd-timezone"></span>), nach Tagen gegliedert · unveränderliches Protokoll</p>
                    <div class="kd-filters">
                        <label>Element<select id="kd-journal-node"><option value="">Gesamter Einsatz</option></select></label>
                        <label>Art<select id="kd-journal-type"><option value="">Alle Einträge</option><option value="handover">Schichtübergaben</option><option value="journal">Notizen</option><option value="situation">Lageübersichten</option><option value="leadership">Leitungen</option><option value="assignment">Zuständigkeiten</option><option value="reminder">Wiedervorlagen</option><option value="status">Status / Prüfpunkte / Kommentare</option><option value="sms">SMS</option><option value="system">System / E-Mail</option></select></label>
                        <label>Einträge filtern<input type="search" id="kd-journal-search" placeholder="Person, Schichtübergabe, Inhalt"></label>
                    </div>
                    <p id="kd-journal-info" role="status"></p>
                    <ol id="kd-journal" class="kd-journal"></ol>
                    <button type="button" class="button" id="kd-more">Ältere Einträge laden</button>
                    <button type="button" class="button" id="kd-latest" hidden>Zurück zum Live-Journal</button>
                </section>
                <details class="kd-panel kd-edit" data-kd-panel="close" data-kd-label="Einsatz abschließen">
                    <summary>Einsatz abschließen</summary>
                    <p>Unwiderruflich: abgeschlossene Einsätze bleiben nur lesbar. Offene Maßnahmen oder ungeklärte Alarmierungen müssen begründet werden.</p>
                    <button type="button" class="button button--danger" data-dialog="close">Abschluss dokumentieren</button>
                </details>
            </div>
        </div>
    </div>
    <dialog id="kd-dialog" aria-labelledby="kd-dialog-title">
        <header class="kd-section-title"><h2 id="kd-dialog-title"></h2><button type="button" class="button" id="kd-dialog-close" autofocus>Schließen</button></header>
        <p id="kd-dialog-warning" class="kd-warning" role="status" hidden></p>
        <div id="kd-dialog-current" class="kd-pre"></div>
        <div id="kd-dialog-body"></div>
        <p id="kd-result" role="alert"></p>
        <button type="button" class="button" id="kd-rebase" hidden>Aktuellen Stand bewusst übernehmen (Eingaben behalten)</button>
    </dialog>
</section>
<script src="/assets/js/kaep-dashboard-layout.js?v=<?= Html::e($assetVersion) ?>" defer></script>
<noscript><p class="flash flash--error">Für die Live-Anzeige und Bearbeitung muss JavaScript aktiviert sein. Ohne JavaScript ist dieses Dashboard kein aktuelles Lagebild.</p></noscript>
