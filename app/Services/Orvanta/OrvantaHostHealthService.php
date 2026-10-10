<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use Throwable;

/**
 * Selbstheilung des Host-Status der Exchange-DAG.
 *
 * Der Status eines Hosts entsteht aus echten Orvanta-Antworten
 * (OrvantaExchangePool::recordSuccess()/recordFailure()). Antwortet ein Host
 * nicht mehr – etwa nach einem VM-Snapshot, Neustart oder kurzen Netzausfall –
 * steht er auf „Gestoert“. Nach dem Wiederanlauf erhaelt er ohne Orvanta-
 * Verkehr keine neue Antwort mehr, weil die Sitzungsaffinitaet die Benutzer
 * auf andere Hosts der DAG gebunden hat. Der Status bliebe dann stehen, bis
 * ihn ein Administrator mit „Verbindung testen“ von Hand zuruecksetzt.
 *
 * Deshalb prueft refresh() gestoerte Hosts mit veraltetem Zustand
 * (OrvantaExchangePool::staleHosts()) selbst nach und uebernimmt das Ergebnis
 * in die Statusfuehrung – mit demselben Pruefweg wie der manuelle
 * Verbindungstest (Posteingang des Pruefpostfachs, OrvantaConfigService::
 * testMailbox()). Aufgerufen wird die Pruefung beim Aufbau der Ansichten, die
 * den Host-Status zeigen (Nachrichtenfluss-Karte, Topologie, DAG-Hosts), also
 * genau dann, wenn der Zustand sichtbar sein muss.
 *
 * Zwei Grenzen halten den Aufwand klein:
 * - Nur bereits gestoerte Hosts werden geprueft. Eine Pruefung kann damit
 *   keine neue Stoerung erzeugen; sie heilt oder bestaetigt einen Zustand.
 * - Je Aufruf hoechstens MAX_CHECKS Hosts (aelteste Pruefung zuerst) und je
 *   Host hoechstens ein Durchgang pro AUTO_CHECK_INTERVAL, damit parallele
 *   Ansichten und mehrere Administratoren Exchange nicht belasten.
 */
final class OrvantaHostHealthService
{
    /** Hoechstzahl der Hosts, die ein Durchgang nachprueft. */
    public const MAX_CHECKS = 4;

    public function __construct(
        private readonly OrvantaExchangePool $pool,
        private readonly OrvantaExchangeService $exchange,
        private readonly OrvantaConfigService $config
    ) {
    }

    /**
     * Prueft gestoerte Hosts mit veraltetem Status nach und schreibt das
     * Ergebnis in die Statusfuehrung zurueck.
     *
     * @return list<array{host:string,ok:bool,message:string}> Ergebnis je
     *         geprueftem Host (leer, wenn nichts zu tun war)
     */
    public function refresh(): array
    {
        if (!$this->config->isEnabled() || $this->config->isDemo()) {
            return [];
        }
        $hosts = array_slice($this->pool->staleHosts(), 0, self::MAX_CHECKS);
        if ($hosts === []) {
            return [];
        }
        // Mehrere Hosts koennen je bis zum Zeitlimit des Transports brauchen.
        @set_time_limit(count($hosts) * ($this->config->transportOptions()['timeout'] + 10) + 30);
        $mailbox = $this->config->testMailbox();
        $results = [];
        foreach ($hosts as $host) {
            try {
                $result = $this->exchange->testHost($host, $mailbox);
                $this->pool->recordSuccess($host, (int) $result['latency_ms']);
                $results[] = ['host' => (string) $host['host'], 'ok' => true, 'message' => (string) $result['message']];
            } catch (Throwable $exception) {
                $this->pool->recordFailure($host, $exception->getMessage());
                $results[] = ['host' => (string) $host['host'], 'ok' => false, 'message' => $exception->getMessage()];
            }
        }
        $healed = array_column(array_filter($results, static fn (array $result): bool => $result['ok']), 'host');
        if ($healed !== []) {
            app_logger()->info('Orvanta: gestörte DAG-Hosts automatisch erneut geprüft.', [
                'hosts' => $healed,
                'ok' => count($healed),
                'failed' => count($results) - count($healed),
            ]);
        }

        return $results;
    }
}
