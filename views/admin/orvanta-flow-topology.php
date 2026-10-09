<?php

declare(strict_types=1);

use App\Services\Orvanta\OrvantaFlowService;
use App\Support\Html;

/**
 * Nachrichtenfluss – Topologie-Ansicht (eigener Tab, Vollbild-Layout).
 *
 * Die Seite liefert nur die Huelle: Kopfleiste mit Gesamtstatus, Werkzeugleiste,
 * Zeichenflaeche, Detailseitenleiste, Ereignisprotokoll und Stoerungsband.
 * Das Netz selbst zeichnet admin-orvanta-flow-topology.js auf ein Canvas
 * (3D-Projektion, Partikel fuer Aktivitaet, Animationen bei Zustandswechsel).
 * Die Startdaten liegen als JSON im Dokument (type="application/json", wird
 * nicht ausgefuehrt); danach aktualisiert sich die Ansicht ueber den
 * JSON-Endpunkt des Kartendashboards. Ohne JavaScript bleibt eine Liste aller
 * Knoten mit Zustand lesbar.
 *
 * Konzept: docs/orvanta-nachrichtenfluss.md Abschnitt 14
 *
 * @var array<string,mixed> $flow Ergebnis von OrvantaFlowService::overview(false)
 * @var string $base Basispfad des Nachrichtenfluss-Dashboards
 * @var bool $orvantaEnabled Orvanta ist aktiviert
 * @var bool $orvantaDemo Demo-Modus (kein echter Exchange)
 * @var int $refreshInterval Sekunden bis zur naechsten Aktualisierung
 */

$nodes = (array) ($flow['nodes'] ?? []);
$overall = (array) ($flow['overall'] ?? []);
$overallState = (string) ($overall['state'] ?? 'off');
$incidents = (array) ($flow['incidents'] ?? []);
$edges = (array) ($flow['edges'] ?? []);

$stateLabel = static function (string $state): string {
    return OrvantaFlowService::STATE_LABELS[$state] ?? $state;
};

/** Startdaten fuer das Skript: ohne Verlauf, der ist fuer das Netz unnoetig. */
$initial = $flow;
unset($initial['history']);
foreach ($initial['nodes'] ?? [] as $key => $node) {
    unset($initial['nodes'][$key]['chart']);
}

$kinds = [
    'source' => 'Identitätsquelle',
    'proxy' => 'IMAP-/SMTP-Proxy',
    'host' => 'Exchange-Host',
    'users' => 'Orvanta-Nutzer',
    'ai' => 'KI-Endpunkt',
    'cache' => 'Zwischenspeicher',
    'tier' => 'Speicher-Tier',
];
?>
<div class="topo"
     data-flow-topology
     data-refresh-url="<?= Html::e($base . '/daten') ?>"
     data-refresh-interval="<?= (int) $refreshInterval ?>"
     data-dashboard-url="<?= Html::e($base) ?>"
     data-overall-state="<?= Html::e($overallState) ?>">

    <script type="application/json" data-flow-initial><?= Html::json($initial) ?></script>

    <header class="topo-head" data-topo-head>
        <div class="topo-head__brand">
            <a class="topo-head__back" href="<?= Html::e($base) ?>" title="Zurück zum Kartendashboard">
                <span aria-hidden="true">←</span><span class="visually-hidden">Zurück zum Kartendashboard</span>
            </a>
            <div class="topo-head__titles">
                <h1 class="topo-head__title">Nachrichtenfluss – Topologie</h1>
                <p class="topo-head__subtitle">Orvanta · Live-Netz aller beteiligten Bausteine</p>
            </div>
        </div>

        <div class="topo-head__status topo-head__status--<?= Html::e($overallState) ?>" data-topo-overall role="status" aria-live="polite">
            <span class="topo-pulse topo-pulse--<?= Html::e($overallState) ?>" data-topo-overall-pulse aria-hidden="true"></span>
            <span class="topo-head__status-label" data-topo-field="overall-label"><?= Html::e((string) ($overall['label'] ?? '')) ?></span>
            <span class="topo-head__status-message" data-topo-field="overall-message"><?= Html::e((string) ($overall['message'] ?? '')) ?></span>
        </div>

        <dl class="topo-head__counters">
            <div class="topo-counter topo-counter--error">
                <dt>Störungen</dt>
                <dd data-topo-field="overall-errors"><?= (int) ($overall['errors'] ?? 0) ?></dd>
            </div>
            <div class="topo-counter topo-counter--warn">
                <dt>Einschränkungen</dt>
                <dd data-topo-field="overall-warnings"><?= (int) ($overall['warnings'] ?? 0) ?></dd>
            </div>
            <div class="topo-counter">
                <dt>Bausteine</dt>
                <dd data-topo-field="node-count"><?= count($nodes) ?></dd>
            </div>
            <div class="topo-counter">
                <dt>Stand</dt>
                <dd data-topo-field="generated"><?= Html::e((string) ($flow['generated_at'] ?? '')) ?></dd>
            </div>
        </dl>

        <div class="topo-head__actions">
            <button class="topo-btn" type="button" data-topo-action="refresh" title="Jetzt aktualisieren (R)">
                <span class="topo-btn__icon" aria-hidden="true">⟳</span> Aktualisieren
            </button>
            <button class="topo-btn" type="button" data-topo-action="fullscreen" title="Vollbild umschalten (F11 im Browser)">
                <span class="topo-btn__icon" aria-hidden="true">⛶</span> Vollbild
            </button>
        </div>
    </header>

    <?php if (!$orvantaEnabled) { ?>
        <p class="topo-banner topo-banner--error" role="alert">Orvanta ist nicht aktiviert. Die Topologie zeigt deshalb nur die eingerichteten Bausteine ohne Exchange-Pfad.</p>
    <?php } elseif ($orvantaDemo) { ?>
        <p class="topo-banner topo-banner--warn" role="status">Demo-Modus (Exchange-Server „demo“): Die Werte des Exchange-Pfads sind nicht aussagekräftig.</p>
    <?php } ?>

    <div class="topo-stage" data-topo-stage>
        <canvas class="topo-canvas" data-topo-canvas tabindex="0" role="application"
                aria-label="Interaktives Netz des Nachrichtenflusses. Ziehen dreht die Ansicht, Mausrad zoomt, Klick auf einen Knoten öffnet Details."></canvas>

        <div class="topo-toolbar" role="toolbar" aria-label="Ansicht steuern">
            <div class="topo-toolbar__group">
                <button class="topo-tool is-active" type="button" data-topo-action="mode-3d" aria-pressed="true" title="3D-Darstellung (3)">3D</button>
                <button class="topo-tool" type="button" data-topo-action="mode-2d" aria-pressed="false" title="Flache Darstellung (2)">2D</button>
            </div>
            <div class="topo-toolbar__group">
                <button class="topo-tool is-active" type="button" data-topo-action="rotate" aria-pressed="true" title="Automatisch drehen (Leertaste)">
                    <span aria-hidden="true">↻</span><span class="visually-hidden">Automatisch drehen</span>
                </button>
                <button class="topo-tool is-active" type="button" data-topo-action="particles" aria-pressed="true" title="Aktivitätsfluss (Partikel) anzeigen (P)">
                    <span aria-hidden="true">∴</span><span class="visually-hidden">Aktivitätsfluss anzeigen</span>
                </button>
                <button class="topo-tool is-active" type="button" data-topo-action="labels" aria-pressed="true" title="Beschriftungen anzeigen (L)">
                    <span aria-hidden="true">Aa</span><span class="visually-hidden">Beschriftungen anzeigen</span>
                </button>
                <button class="topo-tool" type="button" data-topo-action="focus-problems" aria-pressed="false" title="Nur Störungen hervorheben (!)">
                    <span aria-hidden="true">!</span><span class="visually-hidden">Nur Störungen hervorheben</span>
                </button>
            </div>
            <div class="topo-toolbar__group">
                <button class="topo-tool" type="button" data-topo-action="zoom-in" title="Vergrößern (+)"><span aria-hidden="true">+</span><span class="visually-hidden">Vergrößern</span></button>
                <button class="topo-tool" type="button" data-topo-action="zoom-out" title="Verkleinern (−)"><span aria-hidden="true">−</span><span class="visually-hidden">Verkleinern</span></button>
                <button class="topo-tool" type="button" data-topo-action="fit" title="Einpassen (F)"><span aria-hidden="true">⤢</span><span class="visually-hidden">Einpassen</span></button>
                <button class="topo-tool" type="button" data-topo-action="reset" title="Ansicht zurücksetzen (0)"><span aria-hidden="true">⌂</span><span class="visually-hidden">Ansicht zurücksetzen</span></button>
            </div>
        </div>

        <aside class="topo-legend" aria-label="Legende">
            <h2 class="topo-legend__title">Zustände</h2>
            <ul class="topo-legend__list">
                <?php foreach (OrvantaFlowService::STATE_LABELS as $state => $label) { ?>
                    <li><span class="topo-dot topo-dot--<?= Html::e($state) ?>" aria-hidden="true"></span><?= Html::e($label) ?></li>
                <?php } ?>
            </ul>
            <h2 class="topo-legend__title">Bausteine</h2>
            <ul class="topo-legend__list topo-legend__list--kinds" data-topo-kind-filter>
                <?php foreach ($kinds as $kind => $label) { ?>
                    <li>
                        <label>
                            <input type="checkbox" value="<?= Html::e($kind) ?>" checked>
                            <span class="topo-kind topo-kind--<?= Html::e($kind) ?>" aria-hidden="true"></span><?= Html::e($label) ?>
                        </label>
                    </li>
                <?php } ?>
            </ul>
            <p class="topo-legend__hint">Partikel = Aktivität · Pulsieren = Störung · Welle = Zustandswechsel</p>
        </aside>

        <div class="topo-tooltip" data-topo-tooltip hidden aria-hidden="true">
            <strong data-topo-tooltip-title></strong>
            <span class="topo-tooltip__state" data-topo-tooltip-state></span>
            <span class="topo-tooltip__message" data-topo-tooltip-message></span>
        </div>

        <aside class="topo-panel" data-topo-panel hidden aria-labelledby="topo-panel-title">
            <header class="topo-panel__head">
                <span class="topo-panel__kind" data-topo-panel-kind></span>
                <h2 class="topo-panel__title" id="topo-panel-title" data-topo-panel-title></h2>
                <button class="topo-panel__close" type="button" data-topo-action="close-panel" aria-label="Details schließen">✕</button>
            </header>
            <p class="topo-panel__state"><span class="badge" data-topo-panel-badge></span> <code data-topo-panel-subtitle></code></p>
            <p class="topo-panel__message" data-topo-panel-message hidden></p>
            <p class="topo-panel__muted" data-topo-panel-muted hidden></p>
            <dl class="topo-panel__facts" data-topo-panel-facts></dl>
            <div class="topo-panel__cloud" data-topo-panel-cloud hidden>
                <h3 data-topo-panel-cloud-title></h3>
                <ul data-topo-panel-cloud-list></ul>
            </div>
            <div class="topo-panel__members" data-topo-panel-members hidden>
                <h3>Erweiterungen</h3>
                <ul data-topo-panel-members-list></ul>
            </div>
            <div class="topo-panel__neighbours">
                <h3>Verbindungen</h3>
                <ul data-topo-panel-neighbours></ul>
            </div>
            <p class="topo-panel__link"><a class="button button--primary" data-topo-panel-link href="#" hidden>Verwalten</a></p>
        </aside>

        <section class="topo-log" data-topo-log aria-labelledby="topo-log-title">
            <header class="topo-log__head">
                <h2 class="topo-log__title" id="topo-log-title">Ereignisse</h2>
                <button class="topo-log__toggle" type="button" data-topo-action="toggle-log" aria-expanded="true" aria-controls="topo-log-list">Einklappen</button>
            </header>
            <ol class="topo-log__list" id="topo-log-list" data-topo-log-list aria-live="polite">
                <li class="topo-log__item topo-log__item--info">Ansicht geladen · Stand <?= Html::e((string) ($flow['generated_at'] ?? '')) ?></li>
            </ol>
        </section>

        <p class="topo-hint" data-topo-hint>
            Ziehen = drehen · Umschalt + Ziehen oder rechte Maustaste = verschieben · Rad = zoomen · Klick = Details · Doppelklick = zentrieren
        </p>
    </div>

    <section class="topo-incidents topo-incidents--<?= $incidents === [] ? 'none' : 'open' ?>" data-topo-incidents aria-labelledby="topo-incidents-title">
        <h2 class="topo-incidents__title" id="topo-incidents-title">
            <span class="topo-incidents__count" data-topo-field="incident-count"><?= count($incidents) ?></span> offene Störung(en)
        </h2>
        <ul class="topo-incidents__list" data-topo-incident-list>
            <?php foreach ($incidents as $incident) { ?>
                <li class="topo-incident" data-topo-incident="<?= Html::e((string) ($incident['key'] ?? '')) ?>">
                    <button type="button" class="topo-incident__focus" data-topo-focus="<?= Html::e((string) ($incident['key'] ?? '')) ?>">
                        <strong><?= Html::e((string) ($incident['title'] ?? '')) ?></strong>
                        <?php if (trim((string) ($incident['message'] ?? '')) !== '') { ?>
                            <span><?= Html::e((string) $incident['message']) ?></span>
                        <?php } ?>
                    </button>
                    <?php if (trim((string) ($incident['url'] ?? '')) !== '') { ?>
                        <a href="<?= Html::url((string) $incident['url']) ?>">Beheben</a>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
        <p class="topo-incidents__empty" data-topo-incidents-empty<?= $incidents === [] ? '' : ' hidden' ?>>Keine Störung – der Nachrichtenfluss läuft.</p>
    </section>

    <noscript>
        <section class="topo-noscript">
            <h2>Bausteine und Zustände</h2>
            <p>Die interaktive Netzdarstellung benötigt JavaScript. Die Zustände aller Bausteine:</p>
            <ul>
                <?php foreach ($nodes as $node) { ?>
                    <li>
                        <strong><?= Html::e((string) ($node['title'] ?? '')) ?></strong>
                        (<?= Html::e($kinds[(string) ($node['kind'] ?? '')] ?? (string) ($node['kind'] ?? '')) ?>):
                        <?= Html::e($stateLabel((string) ($node['state'] ?? 'off'))) ?>
                        <?php if (trim((string) ($node['message'] ?? '')) !== '') { ?>– <?= Html::e((string) $node['message']) ?><?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <h2>Verbindungen</h2>
            <ul>
                <?php foreach ($edges as $edge) { ?>
                    <li><?= Html::e((string) ($nodes[(string) ($edge['from'] ?? '')]['title'] ?? ($edge['from'] ?? ''))) ?> → <?= Html::e((string) ($nodes[(string) ($edge['to'] ?? '')]['title'] ?? ($edge['to'] ?? ''))) ?>: <?= Html::e($stateLabel((string) ($edge['state'] ?? 'ok'))) ?></li>
                <?php } ?>
            </ul>
            <p><a href="<?= Html::e($base) ?>">Zum Kartendashboard</a></p>
        </section>
    </noscript>
</div>
