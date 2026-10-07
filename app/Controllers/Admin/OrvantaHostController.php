<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Services\Orvanta\OrvantaException;
use App\Services\Orvanta\OrvantaExchangePool;
use PDOException;
use Throwable;

/**
 * Adminbereich "Office → Orvanta – DAG-Hosts": Uebersicht und Verwaltung der
 * Exchange-Hosts einer Database Availability Group (DAG).
 *
 *   GET  /admin/office/orvanta/hosts            Kacheluebersicht der Hosts
 *   POST /admin/office/orvanta/hosts            Hosts ergaenzen (bestaetigte DAG-Zugehoerigkeit)
 *   POST /admin/office/orvanta/hosts/status     Host aktivieren / in Wartung nehmen
 *   POST /admin/office/orvanta/hosts/loeschen   Host entfernen
 *   POST /admin/office/orvanta/hosts/pruefen    Verbindungstest (einzeln oder alle)
 *   POST /admin/office/orvanta/hosts/pruefpostfach  Postfach fuer den Verbindungstest
 *   GET  /admin/office/orvanta/hosts/daten      Kachelwerte als JSON (Live-Aktualisierung)
 *
 * Der im Adminbereich unter Office → Orvanta eingetragene Exchange-Server ist
 * immer der primaere Host. Weitere Hosts lassen sich nur zu einer bereits
 * eingerichteten und getesteten Konfiguration ergaenzen; ihre Zugehoerigkeit
 * zur selben DAG muss der Admin bestaetigen. Alle schreibenden Aktionen
 * pruefen das CSRF-Token als Erstes.
 */
final class OrvantaHostController extends AdminController
{
    public const BASE = '/admin/office/orvanta/hosts';

    public function index(Request $request): Response
    {
        return $this->render();
    }

    /**
     * Ergaenzt Hosts der DAG. Die Zugehoerigkeit zur selben DAG bestaetigt der
     * Admin mit Kontrollkaestchen und Rueckfrage (Ja/Abbrechen).
     */
    public function add(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $config = Container::orvantaConfig();
        if (!$config->isEnabled()) {
            Session::flash('error', 'Orvanta ist nicht aktiviert. Bitte zuerst unter Office → Orvanta die Exchange-Anbindung einrichten und testen.');

            return $this->redirect(self::BASE);
        }
        if ($config->isDemo()) {
            Session::flash('error', 'Der Demo-Modus verwendet keine echten Exchange-Hosts. Bitte zuerst einen Exchange-Server eintragen.');

            return $this->redirect(self::BASE);
        }
        if (empty($request->post['dag_confirmed'])) {
            Session::flash('error', 'Bitte bestätigen Sie, dass die angegebenen Hosts Mitglieder derselben Exchange-DAG sind.');

            return $this->redirect(self::BASE);
        }

        $repository = Container::orvantaExchangeHostRepository();
        try {
            $existing = $repository->hosts();
        } catch (PDOException) {
            Session::flash('error', 'Die Hostliste fehlt. Bitte die Datenbankmigrationen ausführen (php scripts/migrate.php, Migration 043).');

            return $this->redirect(self::BASE);
        }

        $parsed = OrvantaExchangePool::parseHostList((string) $request->input('hosts', ''));
        $errors = $parsed['errors'];
        $known = [];
        foreach ($existing as $row) {
            $known[strtolower((string) $row['host'])] = true;
        }
        $add = [];
        foreach ($parsed['hosts'] as $entry) {
            if (isset($known[$entry['host']])) {
                $errors[] = 'Der Host „' . $entry['host'] . '“ ist bereits eingetragen.';
                continue;
            }
            $known[$entry['host']] = true;
            $add[] = $entry;
        }
        if ($add === [] && $errors === []) {
            $errors[] = 'Bitte mindestens einen Host angeben (ein Hostname je Zeile).';
        }
        if (count($existing) + count($add) > OrvantaExchangePool::MAX_HOSTS) {
            $errors[] = 'Eine Exchange-DAG umfasst höchstens ' . OrvantaExchangePool::MAX_HOSTS . ' Hosts.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                Session::flash('error', $error);
            }

            return $this->redirect(self::BASE);
        }

        $sort = $repository->nextSortOrder();
        try {
            foreach ($add as $entry) {
                $repository->insert($entry['host'], $entry['url'], false, $sort++);
            }
        } catch (PDOException $exception) {
            app_logger()->error('Orvanta: Hosts der Exchange-DAG konnten nicht gespeichert werden.', ['error' => $exception->getMessage()]);
            Session::flash('error', 'Die Hosts konnten nicht gespeichert werden.');

            return $this->redirect(self::BASE);
        }

        app_logger()->info('Orvanta: Hosts der Exchange-DAG ergänzt.', [
            'admin' => Container::auth()->username(),
            'dag_confirmed' => true,
            'hosts' => array_column($add, 'host'),
        ]);
        Session::flash('success', count($add) . ' Host(s) ergänzt. Neue Sitzungen werden nach Fair-use, Anzahl der Sitzungen und mittlerer Antwortzeit verteilt; fällt ein Host aus, wechseln die Sitzungen automatisch. Bitte die Verbindung zu jedem Host prüfen.');

        return $this->redirect(self::BASE);
    }

    /**
     * Host aktivieren bzw. in Wartung nehmen. Es muss mindestens ein Host aktiv
     * bleiben, sonst waere Orvanta nicht mehr erreichbar.
     */
    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $repository = Container::orvantaExchangeHostRepository();
        $host = $this->findHost($request->inputInt('id', 0));
        if ($host === null) {
            Session::flash('error', 'Der Host wurde nicht gefunden.');

            return $this->redirect(self::BASE);
        }
        $active = (int) $host['active'] !== 1;
        if (!$active) {
            $remaining = 0;
            try {
                foreach ($repository->hosts() as $row) {
                    if ((int) $row['active'] === 1 && (int) $row['id'] !== (int) $host['id']) {
                        $remaining++;
                    }
                }
            } catch (PDOException) {
                $remaining = 1;
            }
            if ($remaining === 0) {
                Session::flash('error', 'Der letzte aktive Host kann nicht in Wartung genommen werden.');

                return $this->redirect(self::BASE);
            }
        }
        $repository->setActive((int) $host['id'], $active);
        app_logger()->info('Orvanta: Exchange-Host ' . ($active ? 'aktiviert' : 'in Wartung genommen') . '.', [
            'admin' => Container::auth()->username(),
            'host' => (string) $host['host'],
        ]);
        Session::flash('success', $active
            ? 'Der Host „' . $host['host'] . '“ ist wieder aktiv.'
            : 'Der Host „' . $host['host'] . '“ ist in Wartung: neue Sitzungen weichen auf die übrigen Hosts aus, bestehende werden beim nächsten Aufruf umgeleitet.');

        return $this->redirect(self::BASE);
    }

    /**
     * Entfernt einen Host aus der DAG. Der primaere Host (Office → Orvanta)
     * bleibt, solange er dort eingetragen ist.
     */
    public function remove(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $host = $this->findHost($request->inputInt('id', 0));
        if ($host === null) {
            Session::flash('error', 'Der Host wurde nicht gefunden.');

            return $this->redirect(self::BASE);
        }
        if ((int) $host['is_primary'] === 1) {
            Session::flash('error', 'Der konfigurierte Exchange-Server ist immer der primäre Host. Er lässt sich nur unter Office → Orvanta ändern.');

            return $this->redirect(self::BASE);
        }
        Container::orvantaExchangeHostRepository()->deleteHost((int) $host['id'], (string) $host['host']);
        app_logger()->info('Orvanta: Exchange-Host aus der DAG entfernt.', [
            'admin' => Container::auth()->username(),
            'host' => (string) $host['host'],
        ]);
        Session::flash('success', 'Der Host „' . $host['host'] . '“ wurde entfernt. Laufende Sitzungen werden beim nächsten Aufruf auf die übrigen Hosts verteilt.');

        return $this->redirect(self::BASE);
    }

    /**
     * Verbindungstest eines Hosts oder aller Hosts. Die gemessene Antwortzeit
     * fliesst in die Lastverteilung ein, ein Fehler markiert den Host als
     * gestoert.
     */
    public function check(Request $request): Response
    {
        $this->requireValidCsrf($request);
        if (!Container::orvantaConfig()->isEnabled()) {
            Session::flash('error', 'Orvanta ist nicht aktiviert.');

            return $this->redirect(self::BASE);
        }
        $id = $request->inputInt('id', 0);
        $targets = [];
        if ($id > 0) {
            $host = $this->findHost($id);
            if ($host === null) {
                Session::flash('error', 'Der Host wurde nicht gefunden.');

                return $this->redirect(self::BASE);
            }
            $targets[] = $host;
        } else {
            try {
                $targets = Container::orvantaExchangeHostRepository()->hosts();
            } catch (PDOException) {
                Session::flash('error', 'Die Hostliste fehlt. Bitte die Datenbankmigrationen ausführen (php scripts/migrate.php, Migration 043).');

                return $this->redirect(self::BASE);
            }
        }
        if ($targets === []) {
            Session::flash('error', 'Es ist kein Host eingetragen.');

            return $this->redirect(self::BASE);
        }

        @set_time_limit(count($targets) * 30 + 30);
        $pool = Container::orvantaExchangePool();
        $exchange = Container::orvantaExchange();
        $mailbox = Container::orvantaConfig()->testMailbox();
        $ok = 0;
        $failed = 0;
        $errors = [];
        foreach ($targets as $host) {
            try {
                $result = $exchange->testHost($host, $mailbox);
                $pool->recordSuccess($host, (int) $result['latency_ms']);
                $ok++;
            } catch (OrvantaException $exception) {
                $pool->recordFailure($host, $exception->getMessage());
                $failed++;
                $errors[] = 'Host „' . $host['host'] . '“: ' . $exception->getMessage();
            } catch (Throwable $exception) {
                $pool->recordFailure($host, $exception->getMessage());
                $failed++;
                $errors[] = 'Host „' . $host['host'] . '“: ' . $exception->getMessage();
            }
        }

        app_logger()->info('Orvanta: Verbindungstest der DAG-Hosts ausgeführt.', [
            'admin' => Container::auth()->username(),
            'ok' => $ok,
            'failed' => $failed,
        ]);
        if ($failed > 0) {
            foreach (array_slice($errors, 0, 3) as $error) {
                Session::flash('error', $error);
            }
        }
        if ($ok > 0) {
            Session::flash('success', $ok . ' von ' . count($targets) . ' Host(s) erreichbar.');
        }

        return $this->redirect(self::BASE);
    }

    /**
     * Postfach fuer den Verbindungstest der Hosts festlegen (leer = Posteingang
     * des Dienstkontos). Hat das Dienstkonto kein eigenes Postfach, schlaegt
     * der Test sonst auf jedem Host fehl.
     */
    public function testMailbox(Request $request): Response
    {
        $this->requireValidCsrf($request);
        try {
            Container::orvantaConfig()->saveTestMailbox((string) $request->input('test_mailbox', ''));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $error) {
                Session::flash('error', $error);
            }

            return $this->redirect(self::BASE);
        } catch (PDOException $exception) {
            app_logger()->error('Orvanta: Prüfpostfach der DAG-Hosts konnte nicht gespeichert werden.', ['error' => $exception->getMessage()]);
            Session::flash('error', 'Das Prüfpostfach konnte nicht gespeichert werden.');

            return $this->redirect(self::BASE);
        }
        $mailbox = Container::orvantaConfig()->testMailbox();
        app_logger()->info('Orvanta: Prüfpostfach der DAG-Hosts geändert.', [
            'admin' => Container::auth()->username(),
            'mailbox' => $mailbox,
        ]);
        Session::flash('success', $mailbox !== ''
            ? 'Der Verbindungstest verwendet jetzt das Postfach „' . $mailbox . '“.'
            : 'Der Verbindungstest verwendet jetzt den Posteingang des Dienstkontos.');

        return $this->redirect(self::BASE);
    }

    /**
     * Kachelwerte als JSON fuer die Live-Aktualisierung des Dashboards.
     */
    public function data(Request $request): Response
    {
        $overview = Container::orvantaExchangePool()->overview();

        return Response::json($overview, 200)->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findHost(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return Container::orvantaExchangeHostRepository()->find($id);
        } catch (PDOException) {
            return null;
        }
    }

    private function render(int $status = 200): Response
    {
        $config = Container::orvantaConfig();
        $pool = Container::orvantaExchangePool();
        $repository = Container::orvantaExchangeHostRepository();
        $tablesMissing = false;
        try {
            $repository->hosts();
        } catch (PDOException) {
            $tablesMissing = true;
        }

        return $this->adminView('admin.orvanta-hosts', [
            'pageTitle' => 'Office – Orvanta: DAG-Hosts',
            'activeNav' => 'office_orvanta_hosts',
            'pageScript' => 'admin-orvanta-hosts.js',
            'overview' => $pool->overview(),
            'tablesMissing' => $tablesMissing,
            'orvantaEnabled' => $config->isEnabled(),
            'orvantaDemo' => $config->isDemo(),
            'primaryHost' => $config->get('exchange_host'),
            'ewsUrl' => $config->ewsUrl(),
            'testMailbox' => $config->testMailbox(),
            'serviceUser' => $config->get('exchange_service_user'),
            'maxHosts' => OrvantaExchangePool::MAX_HOSTS,
            'sessionTtl' => OrvantaExchangePool::SESSION_TTL,
            'refreshInterval' => max(5, min(120, $config->pollInterval())),
            'base' => self::BASE,
        ], $status);
    }
}
