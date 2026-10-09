<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use Throwable;

/**
 * Adminbereich "Office → Orvanta – Nachrichtenfluss": Dashboard ueber alle am
 * Nachrichtenfluss beteiligten Elemente und Endpunkte.
 *
 *   GET  /admin/office/orvanta/nachrichtenfluss              Dashboard
 *   GET  /admin/office/orvanta/nachrichtenfluss/daten        Kennzahlen als JSON (Live-Aktualisierung)
 *   POST /admin/office/orvanta/nachrichtenfluss/quellen/pruefen  Verbindungstest je Identitaetsquelle
 *
 * Die Seite ist lesend. Die einzige Aktion ist der Verbindungstest der
 * Identitaetsquellen ueber den IMAP-/SMTP-Proxy; er prueft das CSRF-Token
 * zuerst und schreibt das Ergebnis in den Quellenzustand.
 *
 * Konzept und Datenquellen: docs/orvanta-nachrichtenfluss.md
 */
final class OrvantaFlowController extends AdminController
{
    public const BASE = '/admin/office/orvanta/nachrichtenfluss';

    /** Hoechstzahl der Quellen, die in einem Durchgang geprueft werden. */
    public const MAX_CHECK_SOURCES = 16;

    public function index(Request $request): Response
    {
        $config = Container::orvantaConfig();
        $flow = Container::orvantaFlow()->overview(true);

        return $this->adminView('admin.orvanta-flow', [
            'pageTitle' => 'Office – Orvanta: Nachrichtenfluss',
            'activeNav' => 'office_orvanta_flow',
            'pageScript' => 'admin-orvanta-flow.js',
            'flow' => $flow,
            'base' => self::BASE,
            'orvantaEnabled' => $config->isEnabled(),
            'orvantaDemo' => $config->isDemo(),
            'refreshInterval' => $this->refreshInterval(),
            'checkableSources' => $this->checkableSources(),
            'checkLimit' => self::MAX_CHECK_SOURCES,
        ]);
    }

    /**
     * Kennzahlen und Knoten als JSON. Das Dashboard aktualisiert damit seine
     * Werte, ohne die Seite neu zu laden. Bewusst kein Caching, damit die
     * Anzeige immer den aktuellen Zustand zeigt.
     */
    public function data(Request $request): Response
    {
        return Response::json(Container::orvantaFlow()->overview(true), 200)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Verbindungstest der Identitaetsquellen. Geprueft wird je Quelle das
     * erste aktive Postfach; das Ergebnis landet im Quellenzustand und wird
     * damit im Dashboard sichtbar.
     */
    public function checkSources(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $proxy = Container::mailProxy();
        $sourceId = $request->inputInt('source', 0);

        try {
            $sources = $proxy->sources();
        } catch (Throwable $exception) {
            app_logger()->error('Nachrichtenfluss: Identitätsquellen konnten nicht gelesen werden.', ['error' => $exception->getMessage()]);
            Session::flash('error', 'Die Identitätsquellen konnten nicht gelesen werden.');

            return $this->redirect(self::BASE);
        }

        $active = 0;
        foreach ($sources as $source) {
            if (!empty($source['active'])) {
                $active++;
            }
        }
        if ($active === 0) {
            Session::flash('error', 'Es ist keine aktive Identitätsquelle eingerichtet. Bitte zuerst unter Office → SMTP-/IMAP-Proxy eine Quelle anlegen.');

            return $this->redirect(self::BASE);
        }
        if ($active > self::MAX_CHECK_SOURCES && $sourceId <= 0) {
            Session::flash('error', 'Es sind mehr als ' . self::MAX_CHECK_SOURCES . ' aktive Identitätsquellen eingerichtet. Bitte die Quellen einzeln prüfen.');

            return $this->redirect(self::BASE);
        }

        @set_time_limit($active * 30 + 30);

        try {
            $result = $proxy->testSources($sourceId > 0 ? $sourceId : null);
        } catch (Throwable $exception) {
            app_logger()->error('Nachrichtenfluss: Verbindungstest der Identitätsquellen fehlgeschlagen.', ['error' => $exception->getMessage()]);
            Session::flash('error', 'Der Verbindungstest konnte nicht ausgeführt werden: ' . $exception->getMessage());

            return $this->redirect(self::BASE);
        }

        $ok = 0;
        $failed = 0;
        $unchecked = 0;
        foreach ($result as $entry) {
            if (empty($entry['checked'])) {
                $unchecked++;
                continue;
            }
            if (!empty($entry['ok'])) {
                $ok++;
                continue;
            }
            $failed++;
            Session::flash('error', 'Identitätsquelle „' . $entry['label'] . '“: ' . $entry['message']);
        }

        app_logger()->info('Nachrichtenfluss: Verbindungstest der Identitätsquellen ausgeführt.', [
            'admin' => Container::auth()->username(),
            'source' => $sourceId > 0 ? $sourceId : null,
            'ok' => $ok,
            'failed' => $failed,
            'unchecked' => $unchecked,
        ]);

        if ($ok > 0) {
            Session::flash('success', $ok . ' von ' . ($ok + $failed) . ' geprüften Identitätsquelle(n) erreichbar.');
        }
        if ($unchecked > 0) {
            Session::flash('error', $unchecked . ' Identitätsquelle(n) haben kein aktives Postfach zum Prüfen. Bitte dort zuerst ein Postfach aktivieren.');
        }

        return $this->redirect(self::BASE);
    }

    /**
     * Aktive Identitaetsquellen, die sich einzeln pruefen lassen. Quelle 0 ist
     * die Primaerquelle aus den Orvanta-Einstellungen.
     *
     * @return list<array{id:int,label:string}>
     */
    private function checkableSources(): array
    {
        try {
            $sources = Container::mailProxy()->sources();
        } catch (Throwable) {
            return [];
        }
        $list = [];
        foreach ($sources as $source) {
            if (empty($source['active'])) {
                continue;
            }
            $id = (int) $source['id'];
            $label = trim((string) ($source['label'] ?? ''));
            $list[] = [
                'id' => $id,
                'label' => ($label !== '' ? $label : 'Quelle ' . $id) . ($id === 0 ? ' (primär)' : ''),
            ];
        }

        return $list;
    }

    private function refreshInterval(): int
    {
        try {
            return max(30, min(600, Container::orvantaConfig()->pollInterval() * 3));
        } catch (Throwable) {
            return 120;
        }
    }
}
