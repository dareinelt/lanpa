<?php

declare(strict_types=1);

/**
 * Container storage-sync (Speicher-Tiering und HA-Synchronisation).
 *
 * Aufruf:
 *   php scripts/storage_sync.php monitor          Einbindung, Messwerte, HA-Status
 *   php scripts/storage_sync.php sync             Abgleich, Synchronisation, Auslagerung
 *   php scripts/storage_sync.php recall           Rueckholung auf Anforderung von Nextcloud
 *   php scripts/storage_sync.php recall-one <id>  (intern) eine Rueckholung
 *   php scripts/storage_sync.php restore --target=<id> [--full]
 *                                                 Daten aus einem Speicherziel wiederherstellen
 *                                                 (--keep-paused: Synchronisation bleibt angehalten)
 *   php scripts/storage_sync.php resume           angehaltene Synchronisation fortsetzen
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Services\Storage\Agent\Agent;

$command = $argv[1] ?? '';
$agent = new Agent();

switch ($command) {
    case 'monitor':
        $agent->monitor();
        // no break (endlos)
    case 'sync':
        $agent->sync();
        // no break (endlos)
    case 'recall':
        $agent->recall();
        // no break (endlos)
    case 'recall-one':
        exit($agent->recallOne((string) ($argv[2] ?? '')));
    case 'restore':
        $options = [];
        foreach (array_slice($argv, 2) as $argument) {
            if (preg_match('/^--target=(\d+)$/', $argument, $match) === 1) {
                $options['target'] = (int) $match[1];
            } elseif ($argument === '--full') {
                $options['full'] = true;
            } elseif ($argument === '--keep-paused') {
                $options['keep_paused'] = true;
            }
        }
        if (!isset($options['target'])) {
            fwrite(STDERR, 'Aufruf: php scripts/storage_sync.php restore --target=<id> [--full] [--keep-paused]' . PHP_EOL);
            exit(1);
        }
        exit($agent->restore($options['target'], !empty($options['full']), !empty($options['keep_paused'])));
    case 'resume':
        exit($agent->resume());
    default:
        fwrite(STDERR, 'Aufruf: php scripts/storage_sync.php monitor|sync|recall|restore --target=<id> [--full] [--keep-paused]|resume' . PHP_EOL);
        exit(1);
}
