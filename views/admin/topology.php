<?php

declare(strict_types=1);

use App\Services\Topology\LanpaTopologyService;
use App\Services\Topology\TopologyStatus;
use App\Services\Topology\TopologyVisibilityService;
use App\Support\Html;

/**
 * System – Topologie: Gesamtuebersicht der Anwendung lanpa (eigener Tab,
 * Vollbild-Layout).
 *
 * Die Seite liefert nur die Huelle: Kopfleiste mit Gesamtstatus, Werkzeugleiste,
 * Zeichenflaeche, Detailseitenleiste, Ereignisprotokoll, Stoerungs- und
 * Lueckenband. Den Graphen zeichnet admin-topology.js auf ein Canvas
 * (3D-Projektion, Partikel nur fuer echte Datenfluesse). Die Startdaten liegen
 * als JSON im Dokument (type="application/json", wird nicht ausgefuehrt);
 * danach aktualisiert sich die Ansicht ueber den JSON-Endpunkt. Ohne
 * JavaScript bleibt eine Liste aller Knoten und Kanten lesbar.
 *
 * Konzept und Datenvertrag: docs/admin-topologie-referenz.md
 *
 * @var array<string,mixed> $graph Ergebnis von LanpaTopologyService::overview()
 * @var string $base Basispfad der Topologie-Ansicht
 * @var array{source:string,source_label:string,nodes:list<string>,groups:list<string>} $visibility
 *      gespeicherte Auswahl des Entwurfsmodus
 * @var bool $visibilityPersonalAvailable ob die Anmeldung ein Konto kennt
 */

$nodes = (array) ($graph['nodes'] ?? []);
$edges = (array) ($graph['edges'] ?? []);
$overall = (array) ($graph['overall'] ?? []);
$overallState = (string) ($overall['state'] ?? TopologyStatus::UNKNOWN);
$incidents = (array) ($graph['incidents'] ?? []);
$gaps = (array) ($graph['gaps'] ?? []);
$freshness = (array) ($graph['freshness'] ?? []);
$kpis = (array) ($graph['kpis'] ?? []);

$kinds = LanpaTopologyService::KIND_LABELS;
$stateLabel = static fn (string $state): string => TopologyStatus::label($state);
$nodeTitle = static fn (string $id): string => (string) ($nodes[$id]['title'] ?? $id);

/** Anzahl je Knotenart fuer die Filterliste. */
$kindCounts = [];
foreach ($nodes as $node) {
    $kind = (string) ($node['kind'] ?? 'module');
    $kindCounts[$kind] = ($kindCounts[$kind] ?? 0) + 1;
}

/** Kopfzahlen: Stoerungen, Einschraenkungen, Luecken, nicht ueberwacht. */
$counters = [
    ['key' => 'overall-errors', 'label' => 'Störungen', 'modifier' => 'error', 'value' => (int) ($overall['errors'] ?? 0)],
    ['key' => 'overall-warnings', 'label' => 'Einschränkungen', 'modifier' => 'warn', 'value' => (int) ($overall['warnings'] ?? 0)],
    ['key' => 'overall-unrated', 'label' => 'Ohne Messung', 'modifier' => 'unknown', 'value' => (int) ($overall['unrated'] ?? 0)],
    ['key' => 'overall-unwatched', 'label' => 'Nicht überwacht', 'modifier' => 'off', 'value' => (int) ($overall['unwatched'] ?? 0)],
];

/** Aktive Teilgraphen fuer die Sprungliste. */
$groups = (array) ($graph['groups'] ?? []);
$rootGroups = array_values(array_filter($groups, static fn (array $group): bool => ($group['parent'] ?? null) === null));

$visibility = (array) ($visibility ?? []);
$visibilityPersonalAvailable = (bool) ($visibilityPersonalAvailable ?? false);
// Immer die vollstaendige Form ausliefern, damit der Renderer nicht raten muss.
$visibilitySource = (string) ($visibility['source'] ?? TopologyVisibilityService::SOURCE_DEFAULT);
$visibility = [
    'source' => $visibilitySource,
    'source_label' => (string) ($visibility['source_label'] ?? TopologyVisibilityService::sourceLabel($visibilitySource)),
    'nodes' => array_values(array_map('strval', (array) ($visibility['nodes'] ?? []))),
    'groups' => array_values(array_map('strval', (array) ($visibility['groups'] ?? []))),
];
?>
<div class="topo topo--gesamt"
     data-lanpa-topology
     data-refresh-url="<?= Html::e($base . '/daten') ?>"
     data-refresh-interval="<?= (int) ($graph['refresh_interval'] ?? 120) ?>"
     data-base-url="<?= Html::e($base) ?>"
     data-save-url="<?= Html::e($base . '/entwurf') ?>"
     data-csrf="<?= Html::e((string) ($csrfToken ?? '')) ?>"
     data-personal-available="<?= $visibilityPersonalAvailable ? '1' : '0' ?>"
     data-overall-state="<?= Html::e($overallState) ?>"
     data-schema-version="<?= Html::e((string) ($graph['schema_version'] ?? '')) ?>">

    <script type="application/json" data-topo-initial><?= Html::json($graph) ?></script>
    <script type="application/json" data-topo-visibility><?= Html::json($visibility) ?></script>

    <header class="topo-head" data-topo-head>
        <div class="topo-head__brand">
            <a class="topo-head__back" href="/admin" title="Zurück zur Administration">
                <span aria-hidden="true">←</span><span class="visually-hidden">Zurück zur Administration</span>
            </a>
            <div class="topo-head__titles">
                <h1 class="topo-head__title">Topologie der Anwendung</h1>
                <p class="topo-head__subtitle">lanpa · Module, Dienste, Container, Netze und Speicherziele</p>
            </div>
        </div>

        <div class="topo-head__status topo-head__status--<?= Html::e($overallState) ?>" data-topo-overall role="status" aria-live="polite">
            <span class="topo-pulse topo-pulse--<?= Html::e($overallState) ?>" data-topo-overall-pulse aria-hidden="true"></span>
            <span class="topo-head__status-label" data-topo-field="overall-label"><?= Html::e((string) ($overall['label'] ?? '')) ?></span>
            <span class="topo-head__status-message" data-topo-field="overall-message"><?= Html::e((string) ($overall['message'] ?? '')) ?></span>
        </div>

        <dl class="topo-head__counters">
            <?php foreach ($counters as $counter) { ?>
                <div class="topo-counter topo-counter--<?= Html::e($counter['modifier']) ?>">
                    <dt><?= Html::e($counter['label']) ?></dt>
                    <dd data-topo-field="<?= Html::e($counter['key']) ?>"><?= (int) $counter['value'] ?></dd>
                </div>
            <?php } ?>
            <div class="topo-counter">
                <dt>Bausteine</dt>
                <dd data-topo-field="node-count"><?= count($nodes) ?></dd>
            </div>
            <div class="topo-counter">
                <dt>Stand</dt>
                <dd data-topo-field="generated"><?= Html::e((string) ($graph['generated_at'] ?? '')) ?></dd>
            </div>
        </dl>

        <div class="topo-head__actions">
            <button class="topo-btn" type="button" data-topo-action="draft" aria-pressed="false" title="Entwurfsmodus: Bausteine per Rechtsklick ein- oder ausblenden (E)">
                <span class="topo-btn__icon" aria-hidden="true">✎</span> Entwurf
            </button>
            <button class="topo-btn" type="button" data-topo-action="refresh" title="Jetzt aktualisieren (R)">
                <span class="topo-btn__icon" aria-hidden="true">⟳</span> Aktualisieren
            </button>
            <button class="topo-btn" type="button" data-topo-action="fullscreen" title="Vollbild umschalten (F11 im Browser)">
                <span class="topo-btn__icon" aria-hidden="true">⛶</span> Vollbild
            </button>
        </div>
    </header>

    <?php $isFresh = (bool) ($freshness['fresh'] ?? true); ?>
    <p class="topo-banner topo-banner--warn" role="status" data-topo-banner="stale"<?= $isFresh ? ' hidden' : '' ?>>
        Einzelne Messwerte sind älter als <?= (int) ($freshness['stale_after'] ?? 300) ?> Sekunden. Die Anzeige kennzeichnet sie als veraltet.
    </p>

    <section class="topo-draft" data-topo-draft aria-labelledby="topo-draft-title" hidden>
        <div class="topo-draft__head">
            <h2 class="topo-draft__title" id="topo-draft-title">Entwurfsmodus</h2>
            <span class="topo-draft__source" data-topo-draft-source></span>
        </div>
        <p class="topo-draft__hint" data-topo-draft-hint>
            Rechtsklick auf einen Baustein oder eine Modulgruppe blendet ihn aus bzw. wieder ein.
            Ausgeblendete Bausteine bleiben im Entwurfsmodus blass sichtbar.
        </p>
        <div class="topo-draft__actions">
            <button class="topo-btn topo-btn--primary" type="button" data-topo-action="draft-save-global" title="Auswahl für alle Betrachter speichern">
                Global speichern
            </button>
            <button class="topo-btn" type="button" data-topo-action="draft-save-personal" title="Auswahl nur für dieses Konto speichern">
                Persönlich speichern
            </button>
            <button class="topo-btn" type="button" data-topo-action="draft-reset" title="Auswahl auf den gespeicherten Stand zurücksetzen">
                Zurücksetzen
            </button>
            <button class="topo-btn" type="button" data-topo-action="draft-clear-personal" title="Persönliche Einstellung entfernen; danach gilt wieder die globale Einstellung">
                Persönliche Einstellung löschen
            </button>
            <button class="topo-btn" type="button" data-topo-action="draft" aria-pressed="true" title="Entwurfsmodus beenden (E)">
                Beenden
            </button>
        </div>
        <p class="topo-draft__status" data-topo-draft-status role="status" aria-live="polite"></p>
    </section>

    <div class="topo-stage" data-topo-stage>
        <canvas class="topo-canvas" data-topo-canvas tabindex="0" role="application"
                aria-label="Interaktives Netz der Anwendung lanpa. Ziehen dreht die Ansicht, Mausrad zoomt, Klick auf einen Knoten öffnet Details."></canvas>

        <div class="topo-toolbar" role="toolbar" aria-label="Ansicht steuern">
            <div class="topo-toolbar__group">
                <button class="topo-tool is-active" type="button" data-topo-action="mode-3d" aria-pressed="true" title="3D-Darstellung (3)">3D</button>
                <button class="topo-tool" type="button" data-topo-action="mode-2d" aria-pressed="false" title="Flache Darstellung (2)">2D</button>
            </div>
            <div class="topo-toolbar__group">
                <button class="topo-tool is-active" type="button" data-topo-action="rotate" aria-pressed="true" title="Automatisch drehen (Leertaste)">
                    <span aria-hidden="true">↻</span><span class="visually-hidden">Automatisch drehen</span>
                </button>
                <button class="topo-tool is-active" type="button" data-topo-action="particles" aria-pressed="true" title="Datenfluss (Partikel) anzeigen (P)">
                    <span aria-hidden="true">∴</span><span class="visually-hidden">Datenfluss anzeigen</span>
                </button>
                <button class="topo-tool is-active" type="button" data-topo-action="labels" aria-pressed="true" title="Beschriftungen anzeigen (L)">
                    <span aria-hidden="true">Aa</span><span class="visually-hidden">Beschriftungen anzeigen</span>
                </button>
                <button class="topo-tool" type="button" data-topo-action="focus-problems" aria-pressed="false" title="Nur Störungen und Lücken hervorheben (!)">
                    <span aria-hidden="true">!</span><span class="visually-hidden">Nur Störungen hervorheben</span>
                </button>
            </div>
            <div class="topo-toolbar__group">
                <button class="topo-tool" type="button" data-topo-action="collapse" aria-pressed="false" title="Teilgraphen einklappen – nur Modulgruppen zeigen (G)">
                    <span aria-hidden="true">⊟</span><span class="visually-hidden">Teilgraphen einklappen</span>
                </button>
                <button class="topo-tool" type="button" data-topo-action="zoom-in" title="Vergrößern (+)"><span aria-hidden="true">+</span><span class="visually-hidden">Vergrößern</span></button>
                <button class="topo-tool" type="button" data-topo-action="zoom-out" title="Verkleinern (−)"><span aria-hidden="true">−</span><span class="visually-hidden">Verkleinern</span></button>
                <button class="topo-tool" type="button" data-topo-action="fit" title="Einpassen (F)"><span aria-hidden="true">⤢</span><span class="visually-hidden">Einpassen</span></button>
                <button class="topo-tool" type="button" data-topo-action="reset" title="Ansicht zurücksetzen (0)"><span aria-hidden="true">⌂</span><span class="visually-hidden">Ansicht zurücksetzen</span></button>
            </div>
        </div>

        <aside class="topo-legend" aria-label="Legende">
            <h2 class="topo-legend__title">Zustände</h2>
            <ul class="topo-legend__list" data-topo-state-filter>
                <?php foreach (TopologyStatus::labels() as $state => $label) { ?>
                    <li>
                        <label>
                            <input type="checkbox" value="<?= Html::e($state) ?>" checked>
                            <span class="topo-dot topo-dot--<?= Html::e($state) ?>" aria-hidden="true"></span><?= Html::e($label) ?>
                            <span class="topo-legend__count" data-topo-state-count="<?= Html::e($state) ?>"><?= (int) ($overall['counts'][$state] ?? 0) ?></span>
                        </label>
                    </li>
                <?php } ?>
            </ul>
            <h2 class="topo-legend__title">Bausteine</h2>
            <ul class="topo-legend__list topo-legend__list--kinds" data-topo-kind-filter>
                <?php foreach ($kinds as $kind => $label) {
                    if (!isset($kindCounts[$kind])) {
                        continue;
                    } ?>
                    <li>
                        <label>
                            <input type="checkbox" value="<?= Html::e($kind) ?>" checked>
                            <span class="topo-kind topo-kind--<?= Html::e($kind) ?>" aria-hidden="true"></span><?= Html::e($label) ?>
                            <span class="topo-legend__count"><?= (int) $kindCounts[$kind] ?></span>
                        </label>
                    </li>
                <?php } ?>
            </ul>
            <h2 class="topo-legend__title">Aussagekraft</h2>
            <ul class="topo-legend__list topo-legend__list--evidence">
                <?php foreach (LanpaTopologyService::EVIDENCE_LABELS as $evidence => $label) { ?>
                    <li><span class="topo-evidence topo-evidence--<?= Html::e($evidence) ?>" aria-hidden="true"></span><?= Html::e($label) ?></li>
                <?php } ?>
            </ul>
            <p class="topo-legend__hint">Partikel = laufender Datenfluss · Pulsieren = Störung · gestrichelt = vermutet</p>
        </aside>

        <div class="topo-tooltip" data-topo-tooltip hidden aria-hidden="true">
            <strong data-topo-tooltip-title></strong>
            <span class="topo-tooltip__state" data-topo-tooltip-state></span>
            <span class="topo-tooltip__message" data-topo-tooltip-message></span>
        </div>

        <div class="topo-menu" data-topo-menu role="menu" aria-label="Baustein ein- oder ausblenden" hidden>
            <p class="topo-menu__title" data-topo-menu-title></p>
            <ul class="topo-menu__list" data-topo-menu-list></ul>
            <p class="topo-menu__hint" data-topo-menu-hint></p>
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
                <h3 data-topo-panel-cloud-title>Container</h3>
                <ul data-topo-panel-cloud-list></ul>
            </div>
            <div class="topo-panel__members" data-topo-panel-members hidden>
                <h3>Untergeordnete Bausteine</h3>
                <ul data-topo-panel-members-list></ul>
            </div>
            <div class="topo-panel__neighbours">
                <h3>Beziehungen</h3>
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
                <li class="topo-log__item topo-log__item--info">Ansicht geladen · Stand <?= Html::e((string) ($graph['generated_at'] ?? '')) ?></li>
            </ol>
        </section>

        <p class="topo-hint" data-topo-hint>
            Ziehen = drehen · Umschalt + Ziehen oder rechte Maustaste = verschieben · Rad = zoomen · Klick = Details · Doppelklick = zentrieren · Rechtsklick = ein-/ausblenden (Entwurfsmodus)
        </p>
    </div>

    <section class="topo-incidents topo-incidents--<?= $incidents === [] ? 'none' : 'open' ?>" data-topo-incidents aria-labelledby="topo-incidents-title">
        <h2 class="topo-incidents__title" id="topo-incidents-title">
            <span class="topo-incidents__count" data-topo-field="incident-count"><?= count($incidents) ?></span> Störung(en) und Einschränkung(en)
        </h2>
        <ul class="topo-incidents__list" data-topo-incident-list>
            <?php foreach ($incidents as $incident) { ?>
                <li class="topo-incident topo-incident--<?= Html::e((string) ($incident['state'] ?? '')) ?>" data-topo-incident="<?= Html::e((string) ($incident['node'] ?? '')) ?>">
                    <button type="button" class="topo-incident__focus" data-topo-focus="<?= Html::e((string) ($incident['node'] ?? '')) ?>">
                        <strong><?= Html::e((string) ($incident['title'] ?? '')) ?></strong>
                        <span class="topo-incident__state"><?= Html::e((string) ($incident['state_label'] ?? '')) ?></span>
                        <?php if (trim((string) ($incident['message'] ?? '')) !== '') { ?>
                            <span><?= Html::e((string) $incident['message']) ?></span>
                        <?php } ?>
                    </button>
                    <?php if (trim((string) ($nodes[(string) ($incident['node'] ?? '')]['link'] ?? '')) !== '') { ?>
                        <a href="<?= Html::url((string) $nodes[(string) $incident['node']]['link']) ?>">Öffnen</a>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>
        <p class="topo-incidents__empty" data-topo-incidents-empty<?= $incidents === [] ? '' : ' hidden' ?>>Keine Störung und keine Einschränkung erkannt.</p>
    </section>

    <section class="topo-incidents topo-incidents--<?= $gaps === [] ? 'none' : 'open' ?> topo-gaps" data-topo-gaps aria-labelledby="topo-gaps-title">
        <h2 class="topo-incidents__title" id="topo-gaps-title">
            <span class="topo-incidents__count" data-topo-field="gap-count"><?= count($gaps) ?></span> Baustein(e) ohne belastbare Messung
        </h2>
        <ul class="topo-incidents__list" data-topo-gap-list>
            <?php foreach ($gaps as $gap) { ?>
                <li class="topo-incident topo-incident--<?= Html::e((string) ($gap['state'] ?? '')) ?>" data-topo-gap="<?= Html::e((string) ($gap['node'] ?? '')) ?>">
                    <button type="button" class="topo-incident__focus" data-topo-focus="<?= Html::e((string) ($gap['node'] ?? '')) ?>">
                        <strong><?= Html::e((string) ($gap['title'] ?? '')) ?></strong>
                        <span class="topo-incident__state"><?= Html::e((string) ($gap['state_label'] ?? '')) ?></span>
                        <?php if (trim((string) ($gap['message'] ?? '')) !== '') { ?>
                            <span><?= Html::e((string) $gap['message']) ?></span>
                        <?php } ?>
                    </button>
                </li>
            <?php } ?>
        </ul>
        <p class="topo-incidents__empty" data-topo-gaps-empty<?= $gaps === [] ? '' : ' hidden' ?>>Für alle messbaren Bausteine liegt ein aktueller Wert vor.</p>
    </section>

    <section class="topo-kpis" aria-labelledby="topo-kpis-title">
        <h2 class="topo-kpis__title" id="topo-kpis-title">Kennzahlen</h2>
        <dl class="topo-kpis__list" data-topo-kpis>
            <?php foreach ($kpis as $kpi) { ?>
                <div class="topo-kpi topo-kpi--<?= Html::e((string) ($kpi['state'] ?? 'plain')) ?>" data-topo-kpi="<?= Html::e((string) ($kpi['key'] ?? '')) ?>">
                    <dt><?= Html::e((string) ($kpi['label'] ?? '')) ?></dt>
                    <dd><?= Html::e((string) ($kpi['value'] ?? '')) ?></dd>
                </div>
            <?php } ?>
        </dl>
    </section>

    <?php if ($rootGroups !== []) { ?>
        <section class="topo-groups" aria-labelledby="topo-groups-title">
            <h2 class="topo-groups__title" id="topo-groups-title">Modulgruppen</h2>
            <ul class="topo-groups__list">
                <?php foreach ($rootGroups as $group) { ?>
                    <li>
                        <button type="button" class="topo-groups__item topo-groups__item--<?= Html::e((string) ($group['state'] ?? TopologyStatus::UNKNOWN)) ?>" data-topo-group="<?= Html::e((string) $group['id']) ?>">
                            <span class="topo-dot topo-dot--<?= Html::e((string) ($group['state'] ?? TopologyStatus::UNKNOWN)) ?>" aria-hidden="true"></span>
                            <strong><?= Html::e((string) $group['title']) ?></strong>
                            <span><?= (int) ($group['node_count'] ?? 0) ?> Bausteine · <?= Html::e((string) ($group['state_label'] ?? '')) ?></span>
                        </button>
                    </li>
                <?php } ?>
            </ul>
        </section>
    <?php } ?>

    <noscript>
        <section class="topo-noscript">
            <h2>Bausteine und Zustände</h2>
            <p>Die interaktive Netzdarstellung benötigt JavaScript. Die Zustände aller Bausteine:</p>
            <ul>
                <?php foreach ($nodes as $node) { ?>
                    <li>
                        <strong><?= Html::e((string) ($node['title'] ?? '')) ?></strong>
                        (<?= Html::e((string) ($node['kind_label'] ?? '')) ?>):
                        <?= Html::e($stateLabel((string) ($node['state'] ?? TopologyStatus::UNKNOWN))) ?>
                        <?php if (trim((string) ($node['evidence_label'] ?? '')) !== '') { ?>· <?= Html::e((string) $node['evidence_label']) ?><?php } ?>
                        <?php if (trim((string) ($node['message'] ?? '')) !== '') { ?>– <?= Html::e((string) $node['message']) ?><?php } ?>
                    </li>
                <?php } ?>
            </ul>
            <h2>Beziehungen</h2>
            <ul>
                <?php foreach ($edges as $edge) { ?>
                    <li>
                        <?= Html::e($nodeTitle((string) ($edge['from'] ?? ''))) ?> → <?= Html::e($nodeTitle((string) ($edge['to'] ?? ''))) ?>:
                        <?= Html::e((string) ($edge['type_label'] ?? '')) ?> ·
                        <?= Html::e($stateLabel((string) ($edge['state'] ?? TopologyStatus::UNKNOWN))) ?> ·
                        <?= Html::e((string) ($edge['evidence_label'] ?? '')) ?>
                    </li>
                <?php } ?>
            </ul>
            <p><a href="/admin">Zurück zur Administration</a></p>
        </section>
    </noscript>
</div>
