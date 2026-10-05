<?php

declare(strict_types=1);

/**
 * Orvanta-Archivierungs-Worker (Container mail-archive).
 *
 * Prueft fuer jedes registrierte Postfach die Archivierungsrichtlinie
 * (Schwelle, Mindestalter) und fuehrt bei Bedarf einen Archivierungslauf aus
 * (Copy -> Verify -> Commit -> Delete, siehe OrvantaArchiveService). Mehrere
 * Instanzen sind durch die Job-Sperre in orvanta_archive_jobs ungefaehrlich.
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
