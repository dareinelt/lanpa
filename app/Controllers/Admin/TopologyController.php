<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Adminbereich "System → Topologie": Gesamtuebersicht der Anwendung lanpa.
 *
 *   GET  /admin/topologie         Gesamtansicht (eigener Tab, Vollbild, 2D/3D)
 *   GET  /admin/topologie/daten   Graph als JSON (Live-Aktualisierung)
 *   POST /admin/topologie/entwurf Auswahl der angezeigten Bausteine speichern
 *
 * Die Ansicht ist rein lesend: sie sammelt vorhandene Zustaende und zeigt sie
 * an. Sie loest keine Sicherung aus, aendert keine Konfiguration und ruft
 * keine Wartungsaktion auf. Einzige Ausnahme ist der Entwurfsmodus, der die
 * Anzeigeauswahl global oder persoenlich ablegt (POST, CSRF).
 *
 * Konzept und Datenvertrag: docs/admin-topologie-referenz.md
 */
final class TopologyController extends AdminController
{
    public const BASE = '/admin/topologie';

    /** Untergrenze des Aktualisierungsintervalls in Sekunden. */
    private const MIN_INTERVAL = 60;

    /** Obergrenze des Aktualisierungsintervalls in Sekunden. */
    private const MAX_INTERVAL = 600;

    /**
     * Gesamtansicht: Huelle mit eingebettetem Startzustand. Danach laedt die
     * Ansicht ihre Daten ueber den JSON-Endpunkt nach.
     */
    public function index(Request $request): Response
    {
        $topology = Container::lanpaTopology();
        $topology->setRefreshInterval($this->refreshInterval());

        $auth = Container::auth();

        return $this->adminView('admin.topology', [
            'pageTitle' => 'Topologie',
            'titleSuffix' => 'System',
            'pageScript' => 'admin-topology.js',
            'extraStyles' => ['topology.css'],
            'graph' => $topology->overview(),
            'base' => self::BASE,
            'visibility' => Container::topologyVisibility()->selection($auth->username()),
            'visibilityPersonalAvailable' => (string) ($auth->username() ?? '') !== '',
        ], 200, 'layouts.editor')->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Graph als JSON. Bewusst ohne Zwischenspeicherung, damit die Anzeige den
     * aktuellen Zustand zeigt.
     */
    public function data(Request $request): Response
    {
        $topology = Container::lanpaTopology();
        $topology->setRefreshInterval($this->refreshInterval());

        return Response::json($topology->overview(), 200)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Entwurfsmodus: Auswahl der angezeigten Bausteine ablegen.
     *
     * POST /admin/topologie/entwurf
     *   _token           CSRF-Token des Formulars
     *   scope            global | personal | clear
     *   nodes[]          ausgeblendete Bausteine (Knotenkennungen)
     *   groups[]         ausgeblendete Modulgruppen
     *
     * Antwort: die danach wirksame Auswahl als JSON. Damit zeigt die Ansicht
     * genau das an, was gespeichert wurde, ohne die Seite neu zu laden.
     * "clear" entfernt die persoenliche Auswahl; danach gilt wieder die
     * globale Einstellung.
     */
    public function saveVisibility(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $visibility = Container::topologyVisibility();
        $username = (string) (Container::auth()->username() ?? '');
        $nodes = $request->post['nodes'] ?? [];
        $groups = $request->post['groups'] ?? [];

        $selection = match ((string) ($request->input('scope') ?? '')) {
            'global' => $visibility->saveGlobal($nodes, $groups),
            'personal' => $visibility->savePersonal($username, $nodes, $groups),
            'clear' => $visibility->clearPersonal($username),
            default => null,
        };

        if ($selection === null) {
            return Response::json([
                'ok' => false,
                'error' => 'Unbekannter Speicherbereich.',
                'selection' => $visibility->selection($username),
            ], 422)->withHeader('Cache-Control', 'no-store');
        }

        return Response::json(['ok' => true, 'selection' => $selection], 200)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Aktualisierungsintervall in Sekunden. Es richtet sich nach dem
     * Abfrageintervall der Orvanta-Ueberwachung, damit die Topologie nicht
     * haeufiger prueft als die uebrigen Ansichten.
     */
    private function refreshInterval(): int
    {
        try {
            $seconds = Container::orvantaConfig()->pollInterval() * 3;
        } catch (Throwable) {
            return 120;
        }

        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $seconds));
    }
}