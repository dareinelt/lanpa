<?php

declare(strict_types=1);

/**
 * Orvanta-Archivierungs-Worker (Container mail-archive).
 *
 * Prueft fuer jedes registrierte Postfach die Archivierungsrichtlinie
 * (Freigabegruppe, Schwelle, Mindestalter) und fuehrt bei Bedarf einen
 * Archivierungslauf aus (Copy -> Verify -> Commit -> Delete, siehe
 * OrvantaArchiveService). Archiviert werden nur Mitglieder der konfigurierten
 * AD-Gruppe (Standard: niemand). Mehrere
 * Instanzen sind durch die Job-Sperre in orvanta_archive_jobs ungefaehrlich.
 * Nebenbei raeumt der Worker beendete Orvanta-Sitzungen der Exchange-DAG-Hosts
 * auf (orvanta_exchange_sessions, siehe OrvantaExchangePool::purge()) und
 * pflegt die Praesenz des Nachrichtenfluss-Dashboards: eine Probe der aktiven
 * Nutzer je Zeitraster sowie das Aufraeumen alter Aktivitaetszeilen und Proben
 * (siehe OrvantaPresenceService).
 *
 * Aufruf:
 *   php scripts/orvanta_archive_worker.php          Endlosschleife (Intervall aus der Konfiguration)
 *   php scripts/orvanta_archive_worker.php --once   Ein Durchlauf (Python-Supervisor im Container)
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;

$once = in_array('--once', $argv, true);
do {
    $interval = 3600;
    try {
        Container::reset();
        $config = Container::orvantaConfig();
        $interval = $config->archivePollInterval();
        // Beendete Orvanta-Sitzungen der DAG-Hosts aufraeumen (Sitzungsaffinitaet
        // und Fair-use brauchen nur die laufenden Zuordnungen).
        Container::orvantaExchangePool()->purge();
        // Praesenz des Nachrichtenfluss-Dashboards: Probe der aktiven Nutzer
        // (hoechstens eine je Zeitraster) und Aufraeumen alter Zeilen. So
        // entsteht auch in Ruhezeiten mindestens ein Wert je Worker-Lauf.
        $presence = Container::orvantaPresence();
        $presence->sample();
        $presence->purge();
        if ($config->archiveEnabled()) {
            $service = Container::orvantaArchive();
            foreach (Container::orvantaArchiveRepository()->archives() as $archive) {
                $result = $service->maybeRun((string) $archive['user_uid']);
                if ($result['ran']) {
                    app_logger()->info('Orvanta-Archivierung ausgeführt.', [
                        'uid' => (string) $archive['user_uid'],
                        'reason' => $result['reason'],
                        'processed' => $result['processed'],
                        'deleted' => $result['deleted'],
                        'failed' => $result['failed'],
                    ]);
                }
            }
        }
    } catch (Throwable $exception) {
        app_logger()->error('Orvanta-Archivierungs-Worker fehlgeschlagen.', ['error' => $exception->getMessage()]);
        fwrite(STDERR, "Orvanta-Archivierung fehlgeschlagen; Anwendungsprotokoll prüfen.\n");
        if ($once) {
            exit(1);
        }
    }
    if (!$once) {
        sleep($interval);
    }
} while (!$once);
