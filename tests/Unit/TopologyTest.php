<?php

declare(strict_types=1);

use App\Repositories\AdminUserPreferenceRepository;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use App\Services\Topology\LanpaTopologyService;
use App\Services\Topology\TopologyCollector;
use App\Services\Topology\TopologyGraph;
use App\Services\Topology\TopologyStatus;
use App\Services\Topology\TopologyVisibilityService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Tests der Gesamt-Topologie der Anwendung (System – Topologie).
 *
 * Der Graph wird ohne Datenbank und ohne Netzzugriff aus einem Tatsachen-Array
 * aufgebaut: nur "now" ist gesetzt, alle Abschnitte fehlen. Damit pruefen die
 * Tests genau das, was das Konzept verlangt – fehlende Messungen werden zu
 * "unknown", nie zu "ok", abgeschaltete optionale Module werden nicht als
 * Fehler dargestellt und der Vertrag bleibt trotzdem vollstaendig.
 */
function topologyService(): LanpaTopologyService
{
    return new LanpaTopologyService(new TopologyCollector());
}

/** Baut den Vertrag aus einem Tatsachen-Array; ohne Argument nur "now". */
function topologyContract(array $facts = []): array
{
    $facts = array_merge(['now' => time()], $facts);
    $service = topologyService();

    return $service->contract($service->build($facts), $facts);
}

/** Rendert die Ansichtshuelle mit dem uebergebenen Vertrag. */
function topologyRender(array $graph): string
{
    $_SESSION = [];
    $base = '/admin/topologie';

    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/topology.php';

    return (string) ob_get_clean();
}

// ------------------------------------------------------------------ Zustandsmodell

Runner::test('Topologie: Zustandsmodell kennt genau sechs Zustaende', static function (): void {
    Assert::same(['ok', 'warn', 'error', 'off', 'unknown', 'stale'], TopologyStatus::STATES);
    foreach (TopologyStatus::STATES as $state) {
        Assert::true(TopologyStatus::label($state) !== '', 'Bezeichnung fuer ' . $state);
    }
    Assert::same('Unbekannt', TopologyStatus::label('unknown'));
    Assert::same('Fehler', TopologyStatus::label('error'));
    Assert::same('unknown', TopologyStatus::normalize('Unbekannt'), 'auch die Anzeige-Schreibweise faellt auf das Modell zurueck');
    Assert::same('unknown', TopologyStatus::normalize('gibt-es-nicht'), 'unbekannte Zustandsnamen werden nie gesund');
});

Runner::test('Topologie: unbekannte Zustandsvokabeln werden nie gesund', static function (): void {
    foreach (['', 'keine ahnung', 'n/a', '-', '42'] as $vocabulary) {
        Assert::same('unknown', TopologyStatus::fromSource($vocabulary), 'Vokabel "' . $vocabulary . '"');
        Assert::false(TopologyStatus::isHealthy(TopologyStatus::fromSource($vocabulary)));
    }

    Assert::same('unknown', TopologyStatus::normalize('okay'), 'nur die bekannten Werte gelten');
});

Runner::test('Topologie: Zustandsvokabeln der Module werden korrekt abgebildet', static function (): void {
    $expected = [
        'ok' => 'ok',
        'in_sync' => 'ok',
        'reachable' => 'ok',
        'degraded' => 'warn',
        'lagging' => 'warn',
        'queued' => 'warn',
        'critical' => 'error',
        'unreachable' => 'error',
        'expired' => 'error',
        'disabled' => 'off',
        'not_configured' => 'off',
        'stale' => 'stale',
        'unknown' => 'unknown',
    ];

    foreach ($expected as $vocabulary => $state) {
        Assert::same($state, TopologyStatus::fromSource($vocabulary), 'Vokabel ' . $vocabulary);
    }

    Assert::same('error', TopologyStatus::fromSource('  CRITICAL '), 'Gross-/Kleinschreibung und Leerraum sind egal');
});

Runner::test('Topologie: der schwerste Zustand gewinnt', static function (): void {
    Assert::same('error', TopologyStatus::worst(['ok', 'warn', 'error']));
    Assert::same('error', TopologyStatus::worst(['unknown', 'error', 'off']));
    Assert::same('warn', TopologyStatus::worst(['ok', 'stale', 'warn']));
    Assert::same('unknown', TopologyStatus::worst([]), 'leere Menge ergibt unknown, nicht ok');
    Assert::same('unknown', TopologyStatus::worst(['quatsch']), 'unbekannte Vokabel ergibt unknown');
    Assert::true(TopologyStatus::severity('error') > TopologyStatus::severity('warn'));
    Assert::true(TopologyStatus::severity('warn') > TopologyStatus::severity('stale'));
    Assert::true(TopologyStatus::severity('unknown') > TopologyStatus::severity('off'));
});

Runner::test('Topologie: veraltete Messungen werden auf stale gesetzt', static function (): void {
    $now = 1_700_000_000;

    Assert::same('ok', TopologyStatus::fresh('ok', $now - 10, $now, 300), 'frische Messung bleibt');
    Assert::same('stale', TopologyStatus::fresh('ok', $now - 301, $now, 300), 'zu alte Messung wird veraltet');
    Assert::same('stale', TopologyStatus::fresh('warn', $now - 4000, $now, 300), 'Veraltung gilt auch fuer Warnungen');
    Assert::same('ok', TopologyStatus::fresh('ok', null, $now, 300), 'ohne Messzeitpunkt bleibt der Zustand');
    Assert::same('ok', TopologyStatus::fresh('ok', $now - 99999, $now, 0), 'ohne Veraltungsgrenze bleibt der Zustand');
});

Runner::test('Topologie: Handlungsbedarf, Gesundheit und Nicht-Bewertung sind getrennt', static function (): void {
    Assert::true(TopologyStatus::isHealthy('ok'));
    Assert::false(TopologyStatus::isHealthy('off'), 'abgeschaltet ist nicht gesund');
    Assert::false(TopologyStatus::isHealthy('unknown'), 'unbekannt ist nicht gesund');
    Assert::false(TopologyStatus::isHealthy('stale'), 'veraltet ist nicht gesund');

    Assert::true(TopologyStatus::isProblem('error'));
    Assert::true(TopologyStatus::isProblem('warn'));
    Assert::false(TopologyStatus::isProblem('off'), 'abgeschaltete Module sind keine Stoerung');
    Assert::false(TopologyStatus::isProblem('unknown'), 'fehlende Messung ist keine Stoerung');

    Assert::true(TopologyStatus::isUnrated('unknown'));
    Assert::true(TopologyStatus::isUnrated('stale'));
    Assert::false(TopologyStatus::isUnrated('off'));
    Assert::false(TopologyStatus::isUnrated('error'));
});

// ------------------------------------------------------------------ Graphaufbau

Runner::test('Topologie: der Graph meldet doppelte Kennungen statt sie zu verschlucken', static function (): void {
    $graph = new TopologyGraph();
    $graph->node('a', ['title' => 'A', 'kind' => 'module']);
    $graph->node('a', ['title' => 'A zum Zweiten', 'kind' => 'module']);
    $graph->edge('e', 'a', 'b');
    $graph->edge('e', 'a', 'b');

    $codes = array_column($graph->validate(), 'code');

    Assert::true(in_array('duplicate_id', $codes, true), 'doppelte Kennung wird gemeldet');
    Assert::true(in_array('unknown_endpoint', $codes, true), 'Kante auf unbekannten Knoten wird gemeldet');
    Assert::same('A', $graph->nodeById('a')['title'] ?? '', 'der erste Knoten gewinnt');
});

Runner::test('Topologie: ein sauberer Graph hat keine Beanstandungen', static function (): void {
    $graph = new TopologyGraph();
    $graph->group('group:test', 'Test');
    $graph->node('a', ['title' => 'A', 'kind' => 'module', 'group' => 'group:test']);
    $graph->node('b', ['title' => 'B', 'kind' => 'service', 'group' => 'group:test', 'parent' => 'a']);
    $graph->edge('e1', 'a', 'b');
    $graph->edge('e2', 'b', 'a');

    Assert::same([], $graph->validate(), 'auch ein fachlicher Zyklus ist erlaubt');
    Assert::true($graph->hasNode('a'));
    Assert::same(['b'], $graph->childrenOf('a'), 'Kinder des Knotens A');
    Assert::same(['b'], $graph->dependenciesOf('a'), 'Abhaengigkeiten von A sind Zielknoten');
    Assert::same(['b'], $graph->dependentsOf('a'), 'Abhaengige von A sind Quellknoten');

    $graph->edge('e3', 'a', 'b', ['type' => 'monitors']);
    Assert::same(['b'], $graph->dependenciesOf('a'), 'ueberwachende Kanten sind keine fachliche Abhaengigkeit');
});

// ------------------------------------------------------------------ Datenvertrag

Runner::test('Topologie: der Vertrag ist auch ohne Messungen vollstaendig', static function (): void {
    $contract = topologyContract();

    foreach ([
        'schema_version', 'generated_at', 'generated_iso', 'refresh_interval',
        'overall', 'groups', 'nodes', 'edges', 'incidents', 'gaps', 'freshness',
        'kpis', 'validation', 'validation_ok',
    ] as $key) {
        Assert::true(array_key_exists($key, $contract), 'Feld ' . $key . ' fehlt im Vertrag');
    }

    Assert::same('1.0', $contract['schema_version']);
    Assert::true($contract['validation_ok'], 'der Vertrag ist beanstandungsfrei');
    Assert::same([], $contract['validation']);
    Assert::true($contract['nodes'] !== [], 'der Graph enthaelt Knoten');
    Assert::true($contract['edges'] !== [], 'der Graph enthaelt Kanten');
    Assert::true($contract['groups'] !== [], 'der Graph enthaelt Modulgruppen');
    Assert::true(is_int($contract['refresh_interval']));
    Assert::true($contract['refresh_interval'] >= 60 && $contract['refresh_interval'] <= 600, 'Aktualisierung bleibt begrenzt');
});

Runner::test('Topologie: Kennungen sind stabil, eindeutig und Kanten haengen nie in der Luft', static function (): void {
    $first = topologyContract();
    $second = topologyContract();

    Assert::same(array_keys($first['nodes']), array_keys($second['nodes']), 'Knotenkennungen bleiben stabil');
    Assert::same(array_column($first['edges'], 'id'), array_column($second['edges'], 'id'), 'Kantenkennungen bleiben stabil');
    Assert::same(array_column($first['groups'], 'id'), array_column($second['groups'], 'id'), 'Gruppenkennungen bleiben stabil');

    $nodeIds = array_keys($first['nodes']);
    Assert::same(count($nodeIds), count(array_unique($nodeIds)), 'Knotenkennungen sind eindeutig');

    $edgeIds = array_column($first['edges'], 'id');
    Assert::same(count($edgeIds), count(array_unique($edgeIds)), 'Kantenkennungen sind eindeutig');

    foreach ($first['edges'] as $edge) {
        Assert::true(isset($first['nodes'][$edge['from']]), 'Kante ' . $edge['id'] . ' startet an einem bekannten Knoten');
        Assert::true(isset($first['nodes'][$edge['to']]), 'Kante ' . $edge['id'] . ' endet an einem bekannten Knoten');
        Assert::true(in_array($edge['type'], TopologyGraph::EDGE_TYPES, true), 'Kantentyp ' . $edge['type']);
        Assert::true(in_array($edge['state'], TopologyStatus::STATES, true), 'Kantenzustand ' . $edge['state']);
        Assert::true(in_array($edge['evidence'], TopologyGraph::EVIDENCE, true), 'Aussagekraft ' . $edge['evidence']);
    }

    $groupIds = array_column($first['groups'], 'id');

    foreach ($first['nodes'] as $id => $node) {
        Assert::true(in_array($node['kind'], TopologyGraph::KINDS, true), 'Knotenart ' . $node['kind'] . ' von ' . $id);
        Assert::true(in_array($node['state'], TopologyStatus::STATES, true), 'Knotenzustand ' . $node['state'] . ' von ' . $id);
        Assert::true(in_array($node['evidence'], TopologyGraph::EVIDENCE, true), 'Aussagekraft ' . $node['evidence'] . ' von ' . $id);
        Assert::true(in_array($node['group'], $groupIds, true), 'Knoten ' . $id . ' liegt in einer bekannten Gruppe');
    }

    foreach ($first['groups'] as $group) {
        if ($group['parent'] !== null) {
            Assert::true(in_array($group['parent'], $groupIds, true), 'Gruppe ' . $group['id'] . ' hat eine bekannte Elterngruppe');
        }
    }
});

Runner::test('Topologie: fehlende Messungen werden nie als gesund dargestellt', static function (): void {
    $contract = topologyContract();
    $unknown = 0;

    foreach ($contract['nodes'] as $id => $node) {
        if ($node['state'] !== 'unknown') {
            continue;
        }
        $unknown++;
        Assert::true($node['unwatched'] || $node['evidence'] !== 'proven', 'unbekannter Knoten ' . $id . ' belegt seine Aussagekraft');
        Assert::false($node['state'] === 'ok', 'unbekannt bleibt unbekannt');
    }

    Assert::true($unknown > 0, 'ohne Messungen gibt es unbekannte Knoten');
    Assert::same($unknown - $contract['overall']['unwatched'], $contract['overall']['unrated'], 'nicht bewertet sind die ueberwachten Unbekannten');
});

Runner::test('Topologie: abgeschaltete Module sind keine Stoerung', static function (): void {
    $contract = topologyContract();

    Assert::same([], $contract['incidents'], 'ohne Messungen gibt es keine Stoerung');
    Assert::same('ok', $contract['overall']['state']);
    Assert::same(0, $contract['overall']['errors']);
    Assert::same(0, $contract['overall']['warnings']);

    $off = 0;
    foreach ($contract['nodes'] as $id => $node) {
        if ($node['state'] !== 'off') {
            continue;
        }
        $off++;
        Assert::false(TopologyStatus::isProblem($node['state']), 'abgeschalteter Knoten ' . $id . ' ist kein Handlungsbedarf');
        Assert::false($node['severity'] >= TopologyStatus::severity('warn'), 'abgeschalteter Knoten ' . $id . ' hat keine Warnschwere');
    }

    Assert::true($off > 0, 'ohne Messungen sind optionale Module abgeschaltet');
    Assert::same($off, $contract['overall']['counts']['off']);
    Assert::same(0, count(array_intersect(array_column($contract['incidents'], 'node'), array_keys(array_filter(
        $contract['nodes'],
        static fn (array $node): bool => $node['state'] === 'off'
    )))), 'kein abgeschalteter Knoten steht im Stoerungsband');
});

Runner::test('Topologie: Stoerungen und Luecken sind getrennte Baender', static function (): void {
    $facts = [
        'now' => time(),
        'database' => [
            'state' => 'error',
            'message' => 'Datenbank nicht erreichbar.',
            'measured_at' => time(),
            'available' => true,
            'data' => [],
        ],
    ];
    $contract = topologyContract($facts);

    $incidentIds = array_column($contract['incidents'], 'node');
    $gapIds = array_column($contract['gaps'], 'node');

    Assert::same('error', $contract['overall']['state']);
    Assert::true(in_array('data:mysql', $incidentIds, true), 'der Datenbankfehler steht im Stoerungsband');
    Assert::false(in_array('data:mysql', $gapIds, true), 'ein Fehler ist keine Luecke');
    Assert::same([], array_intersect($incidentIds, $gapIds), 'kein Baustein steht in beiden Baendern');

    foreach ($contract['incidents'] as $incident) {
        Assert::true(in_array($incident['state'], ['error', 'warn'], true), 'Stoerungen sind nur Fehler und Warnungen');
    }
    foreach ($contract['gaps'] as $gap) {
        Assert::true(in_array($gap['state'], ['unknown', 'stale'], true), 'Luecken sind nur unbekannt und veraltet');
    }

    $unratedGaps = array_filter($contract['gaps'], static fn (array $gap): bool => $gap['state'] === 'unknown');
    Assert::true(count($unratedGaps) === 0, 'ohne Messzeitpunkt gibt es keine Luecke, nur einen unbeobachteten Baustein');
});

Runner::test('Topologie: veraltete Messungen landen im Lueckenband, nicht im Stoerungsband', static function (): void {
    $facts = [
        'now' => time(),
        'database' => [
            'state' => 'ok',
            'message' => 'Datenbank erreichbar.',
            'measured_at' => time() - 3600,
            'available' => true,
            'data' => [],
        ],
    ];
    $contract = topologyContract($facts);

    $gapIds = array_column($contract['gaps'], 'node');

    Assert::true(in_array('data:mysql', $gapIds, true), 'die veraltete Messung steht im Lueckenband');
    Assert::false(in_array('data:mysql', array_column($contract['incidents'], 'node'), true), 'veraltet ist keine Stoerung');
    Assert::same('stale', $contract['nodes']['data:mysql']['state'], 'der veraltete Knoten wird als veraltet gekennzeichnet');
    Assert::same('Veraltet', $contract['nodes']['data:mysql']['state_label']);
    Assert::same('unknown', $contract['overall']['state'], 'veraltete Messungen lassen den Gesamtzustand nicht gesund erscheinen');
});

Runner::test('Topologie: der Gesamtzustand folgt dem schwersten Einzelzustand', static function (): void {
    $base = topologyContract();
    $broken = topologyContract([
        'now' => time(),
        'database' => [
            'state' => 'error',
            'message' => 'Datenbank nicht erreichbar.',
            'measured_at' => time(),
            'available' => true,
            'data' => [],
        ],
    ]);

    Assert::same('ok', $base['overall']['state']);
    Assert::same('error', $broken['overall']['state']);
    Assert::true($broken['overall']['errors'] >= 1);
    Assert::same('error', $broken['nodes']['data:mysql']['state']);
    $groups = array_column($broken['groups'], null, 'id');
    Assert::same('error', $groups['group:data']['state'], 'die Gruppe erbt den schwersten Zustand');
});

Runner::test('Topologie: Kennzahlen und Frische sind beschrieben', static function (): void {
    $contract = topologyContract();
    $keys = array_column($contract['kpis'], 'key');

    foreach (['nodes', 'groups', 'edges', 'errors', 'warnings', 'unrated', 'containers', 'exchange', 'identity', 'tiers', 'snapshots', 'mail'] as $key) {
        Assert::true(in_array($key, $keys, true), 'Kennzahl ' . $key);
    }

    foreach ($contract['kpis'] as $kpi) {
        Assert::true(isset($kpi['label'], $kpi['value']), 'Kennzahl ' . $kpi['key'] . ' ist beschriftet');
    }

    Assert::true(isset($contract['freshness']['stale_after']));
    Assert::true($contract['freshness']['stale_after'] > 0);
    Assert::true(isset($contract['freshness']['fresh']));
    Assert::true($contract['freshness']['age_seconds'] === null || is_int($contract['freshness']['age_seconds']));
    Assert::same(date('d.m.Y H:i:s', (int) $contract['freshness']['generated_ts']), $contract['generated_at'], 'Erzeugungszeit und Frische passen zusammen');
});

Runner::test('Topologie: der Vertrag gibt keine Geheimnisse oder Rechnernamen preis', static function (): void {
    $contract = topologyContract();

    $forbiddenKeys = ['password', 'passwort', 'secret', 'api_key', 'apikey', 'token', 'credential', 'private_key', 'certificate_pem'];
    $walk = static function (array $data, callable $visit, string $path = '') use (&$walk): void {
        foreach ($data as $key => $value) {
            $visit((string) $key, $path);
            if (is_array($value)) {
                $walk($value, $visit, $path . '/' . $key);
            }
        }
    };
    $walk($contract, static function (string $key, string $path) use ($forbiddenKeys): void {
        Assert::false(
            in_array(strtolower($key), $forbiddenKeys, true),
            'Feld "' . $key . '" unter ' . $path . ' ist ein Geheimnis'
        );
    });

    $json = (string) json_encode($contract);
    foreach (['BEGIN PRIVATE', 'BEGIN RSA', 'PRIVATE KEY', 'Authorization:', 'Basic '] as $needle) {
        Assert::false(stripos($json, $needle) !== false, 'kein "' . $needle . '" im Vertrag');
    }

    foreach ($contract['nodes'] as $id => $node) {
        if ($node['link'] === null) {
            continue;
        }
        Assert::true(str_starts_with($node['link'], '/'), 'Verweis von ' . $id . ' ist ein interner Pfad');
        Assert::false(str_starts_with($node['link'], '//'), 'kein protokollrelativer Verweis');
        Assert::false(str_contains($node['link'], '\\'), 'kein Umweg ueber einen Backslash');
    }
});

// ------------------------------------------------------------------ Ansichtshuelle

Runner::test('Topologie: die Ansicht liefert Huelle, Startdaten als JSON und Noscript-Liste', static function (): void {
    $graph = topologyContract();
    $html = topologyRender($graph);

    Assert::contains('data-lanpa-topology', $html);
    Assert::contains('data-refresh-url="/admin/topologie/daten"', $html);
    Assert::contains('data-refresh-interval="' . $graph['refresh_interval'] . '"', $html);
    Assert::contains('data-schema-version="1.0"', $html);
    Assert::contains('<script type="application/json" data-topo-initial>', $html, 'Startdaten als nicht ausfuehrbares JSON');
    Assert::false(str_contains($html, '<script>'), 'kein ausfuehrbares Inline-Skript');
    Assert::false(str_contains($html, 'style="'), 'keine Inline-Stile (CSP verbietet sie)');
    Assert::false(str_contains($html, 'onclick'), 'keine Inline-Handler');

    foreach ([
        'data-topo-head', 'data-topo-canvas', 'data-topo-stage', 'data-topo-tooltip',
        'data-topo-panel', 'data-topo-panel-facts', 'data-topo-panel-members',
        'data-topo-panel-neighbours', 'data-topo-log-list', 'data-topo-incident-list',
        'data-topo-gap-list', 'data-topo-kpis', 'data-topo-state-filter',
        'data-topo-kind-filter', 'topo-groups__title', 'data-topo-banner',
    ] as $marker) {
        Assert::contains($marker, $html, 'Markierung ' . $marker);
    }

    foreach ([
        'mode-3d', 'mode-2d', 'rotate', 'particles', 'labels', 'focus-problems', 'collapse',
        'zoom-in', 'zoom-out', 'fit', 'reset', 'refresh', 'fullscreen', 'close-panel', 'toggle-log',
    ] as $action) {
        Assert::contains('data-topo-action="' . $action . '"', $html, 'Werkzeug ' . $action);
    }

    foreach (TopologyStatus::STATES as $state) {
        Assert::contains('data-topo-state-count="' . $state . '"', $html, 'Zaehler fuer ' . $state);
        Assert::contains('topo-dot--' . $state, $html, 'Legendenpunkt fuer ' . $state);
    }

    foreach (array_keys(LanpaTopologyService::EVIDENCE_LABELS) as $evidence) {
        Assert::contains('topo-evidence--' . $evidence, $html, 'Aussagekraft ' . $evidence);
    }

    Assert::contains('<noscript>', $html);
    Assert::contains('lanpa', $html, 'die Noscript-Liste nennt die Knoten');

    preg_match('/<script type="application\/json" data-topo-initial>(.*?)<\/script>/s', $html, $match);
    $json = json_decode($match[1] ?? '', true);
    Assert::true(is_array($json), 'eingebettetes JSON ist gueltig');
    Assert::same('1.0', $json['schema_version'] ?? '');
    Assert::true(isset($json['nodes']['core:lanpa']), 'der Kernknoten ist eingebettet');
    Assert::false(str_contains($match[1] ?? '<', '<'), 'spitze Klammern im JSON sind maskiert');
});

Runner::test('Topologie: die Ansicht stellt Stoerungen und Luecken getrennt dar', static function (): void {
    $html = topologyRender(topologyContract([
        'now' => time(),
        'database' => [
            'state' => 'error',
            'message' => 'Datenbank nicht erreichbar.',
            'measured_at' => time(),
            'available' => true,
            'data' => [],
        ],
    ]));

    Assert::contains('data-overall-state="error"', $html);
    Assert::contains('topo-incidents--open', $html);
    Assert::contains('data-topo-incident="data:mysql"', $html, 'die Stoerung steht im Band');
    Assert::contains('data-topo-focus="data:mysql"', $html, 'die Stoerung springt zum Knoten');
    Assert::contains('data-topo-gaps', $html, 'das Lueckenband existiert unabhaengig davon');
});

Runner::test('Topologie: die Ansicht bindet Renderer und Stil ohne Fremdbibliothek ein', static function (): void {
    $root = dirname(__DIR__, 2);
    $controller = (string) file_get_contents($root . '/app/Controllers/Admin/TopologyController.php');
    $view = (string) file_get_contents($root . '/views/admin/topology.php');

    Assert::contains("'pageScript' => 'admin-topology.js'", $controller, 'der Controller laedt den Renderer');
    Assert::contains("'extraStyles' => ['topology.css']", $controller, 'der Controller laedt den Stil');
    Assert::false(str_contains($view, 'cdn.'), 'kein CDN in der Ansicht');
    Assert::false(str_contains($view, 'http://'), 'keine unsicheren Fremdverweise in der Ansicht');
    Assert::false(str_contains($view, 'https://'), 'keine Fremdverweise in der Ansicht');

    $script = (string) file_get_contents($root . '/public/assets/js/admin-topology.js');
    $style = (string) file_get_contents($root . '/public/assets/css/topology.css');

    Assert::true(strlen($script) > 20_000, 'der Renderer ist vorhanden');
    Assert::true(strlen($style) > 5_000, 'der Stil ist vorhanden');
    Assert::false(str_contains($script, '.innerHTML'), 'der Renderer baut DOM ohne innerHTML');
    Assert::false(str_contains($script, 'eval('), 'der Renderer verwendet kein eval');
    Assert::false(str_contains($script, 'document.write'), 'der Renderer schreibt das Dokument nicht um');
    Assert::false(str_contains($script, 'http://'), 'der Renderer ruft keine Fremdsysteme auf');
    Assert::false(str_contains($script, 'https://'), 'der Renderer ruft keine Fremdsysteme auf');
});

// ------------------------------------------------------------- Entwurfsmodus

/**
 * PDO-Unterklasse, die den MySQL-Upsert des Einstellungsspeichers fuer SQLite
 * uebersetzt. Die Produktionsabfrage bleibt unveraendert; nur der Test kann so
 * die globale Einstellung ohne MySQL schreiben.
 */
final class TopologySettingsPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(
            'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            'ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value',
            $query
        ), $options);
    }
}

/** Einstellungsspeicher und persoenliche Ablage in einer In-Memory-Datenbank. */
function topologyVisibilityService(): TopologyVisibilityService
{
    $pdo = new TopologySettingsPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $pdo->exec(
        'CREATE TABLE admin_user_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            preference_key TEXT NOT NULL,
            preference_value TEXT NOT NULL,
            UNIQUE (username, preference_key)
        )'
    );

    return new TopologyVisibilityService(
        new SettingsService(new SettingsRepository($pdo)),
        new AdminUserPreferenceRepository($pdo)
    );
}

Runner::test('Topologie: Kennungen der Entwurfsauswahl werden bereinigt', static function (): void {
    $ids = TopologyVisibilityService::normalizeIds([
        ' data:mysql ',
        'core:lanpa',
        'data:mysql',
        42,
        7,
        '',
        '   ',
        null,
        true,
        ['verschachtelt'],
        new stdClass(),
        str_repeat('x', TopologyVisibilityService::MAX_ID_LENGTH + 1),
    ]);

    Assert::same(['42', '7', 'core:lanpa', 'data:mysql'], $ids, 'getrimmt, ohne Doppelte, sortiert, ohne Ungueltiges');
    Assert::same([], TopologyVisibilityService::normalizeIds('kein feld'));
    Assert::same([], TopologyVisibilityService::normalizeIds(null));

    $many = [];
    for ($i = 0; $i < TopologyVisibilityService::MAX_ENTRIES + 25; $i++) {
        $many[] = 'knoten:' . $i;
    }
    Assert::same(TopologyVisibilityService::MAX_ENTRIES, count(TopologyVisibilityService::normalizeIds($many)), 'die Anzahl ist begrenzt');
});

Runner::test('Topologie: Herkunft der Entwurfsauswahl hat eine Bezeichnung', static function (): void {
    Assert::same('Persönliche Einstellung', TopologyVisibilityService::sourceLabel(TopologyVisibilityService::SOURCE_PERSONAL));
    Assert::same('Globale Einstellung', TopologyVisibilityService::sourceLabel(TopologyVisibilityService::SOURCE_GLOBAL));
    Assert::same('Keine Auswahl gespeichert', TopologyVisibilityService::sourceLabel(TopologyVisibilityService::SOURCE_DEFAULT));
    Assert::same('Keine Auswahl gespeichert', TopologyVisibilityService::sourceLabel('unbekannt'), 'unbekannte Herkunft gilt als leer');
});

Runner::test('Topologie: ohne Einstellung ist nichts ausgeblendet', static function (): void {
    $service = topologyVisibilityService();
    $selection = $service->selection('redakteur');

    Assert::same('default', $selection['source']);
    Assert::same([], $selection['nodes']);
    Assert::same([], $selection['groups']);
    Assert::same('default', $service->selection(null)['source'], 'auch ohne Kontonamen');
});

Runner::test('Topologie: globale Entwurfsauswahl wird gespeichert und gelesen', static function (): void {
    $service = topologyVisibilityService();
    $saved = $service->saveGlobal(['core:lanpa', ' core:lanpa ', 'data:mysql'], ['office', 12]);

    Assert::same('global', $saved['source']);
    Assert::same(['core:lanpa', 'data:mysql'], $saved['nodes']);
    Assert::same(['12', 'office'], $saved['groups'], 'Kennungen werden als Zeichenketten gefuehrt');

    $read = $service->selection('redakteur');
    Assert::same('global', $read['source']);
    Assert::same($saved['nodes'], $read['nodes']);
    Assert::same($saved['groups'], $read['groups']);
});

Runner::test('Topologie: persoenliche Entwurfsauswahl hat Vorrang vor der globalen', static function (): void {
    $service = topologyVisibilityService();
    $service->saveGlobal(['data:mysql'], ['office']);
    $service->savePersonal('redakteur', ['core:lanpa'], []);

    $personal = $service->selection('redakteur');
    Assert::same('personal', $personal['source']);
    Assert::same(['core:lanpa'], $personal['nodes'], 'die persoenliche Auswahl gilt vollstaendig, nicht ergaenzend');
    Assert::same([], $personal['groups']);

    Assert::same('global', $service->selection('anderer')['source'], 'andere Konten sehen die globale Einstellung');
});

Runner::test('Topologie: persoenliche Entwurfsauswahl laesst sich wieder entfernen', static function (): void {
    $service = topologyVisibilityService();
    $service->saveGlobal(['data:mysql'], ['office']);
    $service->savePersonal('redakteur', ['core:lanpa'], []);

    $after = $service->clearPersonal('redakteur');
    Assert::same('global', $after['source'], 'danach gilt wieder die globale Einstellung');
    Assert::same(['data:mysql'], $after['nodes']);
    Assert::same('global', $service->selection('redakteur')['source']);

    // Zweites Loeschen ist unschaedlich.
    Assert::same('global', $service->clearPersonal('redakteur')['source']);
});

Runner::test('Topologie: Entwurfsauswahl ohne Kontonamen aendert nichts', static function (): void {
    $service = topologyVisibilityService();
    $service->saveGlobal(['data:mysql'], []);

    $result = $service->savePersonal('  ', ['core:lanpa'], []);
    Assert::same('global', $result['source'], 'ohne Konto wird nicht gespeichert');
    Assert::same(['data:mysql'], $result['nodes']);
    Assert::same('global', $service->clearPersonal('')['source']);
});

Runner::test('Topologie: beschaedigte Entwurfsauswahl wird nie zum Fehler', static function (): void {
    $service = topologyVisibilityService();
    $service->saveGlobal(['data:mysql'], []);

    // Wert von aussen (z. B. ueber den Einstellungsbereich) beschaedigen.
    $reflection = new ReflectionClass($service);
    $property = $reflection->getProperty('settings');
    $settings = $property->getValue($service);
    $settings->update([TopologyVisibilityService::GLOBAL_KEY => 'kein json']);

    $selection = $service->selection('redakteur');
    Assert::same('global', $selection['source'], 'der Eintrag bleibt erkennbar');
    Assert::same([], $selection['nodes'], 'unlesbarer Wert bedeutet: nichts ausgeblendet');
    Assert::same([], $selection['groups']);

    $settings->update([TopologyVisibilityService::GLOBAL_KEY => '"nur text"']);
    Assert::same([], $service->selection('redakteur')['nodes'], 'auch ein Skalar ist unschaedlich');

    $settings->update([TopologyVisibilityService::GLOBAL_KEY => '{"nodes":{"a":1},"groups":["office",null]}']);
    $partial = $service->selection('redakteur');
    Assert::same([], $partial['nodes'], 'Objekte statt Listen werden verworfen');
    Assert::same(['office'], $partial['groups'], 'unbrauchbare Einzelwerte fallen heraus');
});

Runner::test('Topologie: die Ansicht liefert die Entwurfsauswahl an den Renderer', static function (): void {
    $html = topologyRender(topologyContract());

    Assert::contains('data-topo-visibility', $html, 'die Auswahl liegt im Dokument');
    Assert::contains('data-save-url="/admin/topologie/entwurf"', $html, 'der Speicher-Endpunkt ist gesetzt');
    Assert::contains('data-topo-draft', $html, 'die Entwurfsleiste existiert');
    Assert::contains('data-topo-draft-source', $html, 'die Herkunft wird angezeigt');
    Assert::contains('data-topo-draft-status', $html, 'es gibt eine Statuszeile');
    Assert::contains('data-topo-action="draft"', $html, 'der Entwurfsmodus laesst sich umschalten');
    Assert::contains('data-topo-action="draft-save-global"', $html);
    Assert::contains('data-topo-action="draft-save-personal"', $html);
    Assert::contains('data-topo-action="draft-reset"', $html);
    Assert::contains('data-topo-action="draft-clear-personal"', $html);
    Assert::contains('data-topo-menu', $html, 'das Kontextmenue ist vorbereitet');
    Assert::contains('data-topo-menu-list', $html);

    preg_match('/<script type="application\/json" data-topo-visibility>(.*?)<\/script>/s', $html, $match);
    $json = json_decode($match[1] ?? '', true);
    Assert::true(is_array($json), 'die eingebettete Auswahl ist gueltiges JSON');
    Assert::same('default', $json['source'] ?? '');
    Assert::same([], $json['nodes'] ?? null);
    Assert::same([], $json['groups'] ?? null);
});

Runner::test('Topologie: der Renderer blendet per Rechtsklick ein und aus', static function (): void {
    $root = dirname(__DIR__, 2);
    $script = (string) file_get_contents($root . '/public/assets/js/admin-topology.js');

    Assert::contains("'contextmenu'", $script, 'das Kontextmenue haengt am Rechtsklick');
    Assert::contains('Einblenden', $script, 'die Aktion Einblenden existiert');
    Assert::contains('Ausblenden', $script, 'die Aktion Ausblenden existiert');
    Assert::contains('isHiddenByUser', $script, 'es gibt einen eigenen Ausblendzustand');
    Assert::contains('data-topo-draft-status', $script, 'der Renderer schreibt in die Statuszeile');
    Assert::contains('scope', $script, 'der Speicherbereich wird mitgeschickt');
    Assert::contains('URLSearchParams', $script, 'die Auswahl geht als Formulardaten an den Server');
    Assert::false(str_contains($script, '.innerHTML'), 'das Kontextmenue baut DOM ohne innerHTML');
    Assert::false(str_contains($script, 'http://'), 'keine Fremdsysteme');
    Assert::false(str_contains($script, 'https://'), 'keine Fremdsysteme');

    $style = (string) file_get_contents($root . '/public/assets/css/topology.css');
    Assert::contains('.topo-draft', $style, 'die Entwurfsleiste ist gestaltet');
    Assert::contains('.topo-menu', $style, 'das Kontextmenue ist gestaltet');
});
