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
 *
 * Snapshot-Speicher (Dateiversionen):
 *   php scripts/storage_sync.php snapshots [--path=<Teilpfad>] [--limit=<n>]
 *   php scripts/storage_sync.php snapshot-status
 *   php scripts/storage_sync.php snapshot-prune   Aufbewahrung sofort anwenden
 *   php scripts/storage_sync.php snapshot-retry   fehlgeschlagene Sicherungen erneut einplanen
 *   php scripts/storage_sync.php snapshot-restore --id=<uid>
 *   php scripts/storage_sync.php snapshot-rebuild Katalog aus der Freigabe neu aufbauen
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
    case 'snapshots':
        $path = null;
        $limit = 50;
        foreach (array_slice($argv, 2) as $argument) {
            if (preg_match('/^--path=(.*)$/', $argument, $match) === 1) {
                $path = $match[1];
            } elseif (preg_match('/^--limit=(\d+)$/', $argument, $match) === 1) {
                $limit = max(1, min(1000, (int) $match[1]));
            }
        }
        exit($agent->snapshotList($path, $limit));
    case 'snapshot-status':
        exit($agent->snapshotStatus());
    case 'snapshot-prune':
        exit($agent->snapshotPrune());
    case 'snapshot-retry':
        exit($agent->snapshotRetry());
    case 'snapshot-restore':
        $uid = '';
        foreach (array_slice($argv, 2) as $argument) {
            if (preg_match('/^--id=([0-9a-f]{40})$/', $argument, $match) === 1) {
                $uid = $match[1];
            }
        }
        if ($uid === '') {
            fwrite(STDERR, 'Aufruf: php scripts/storage_sync.php snapshot-restore --id=<uid>' . PHP_EOL);
            exit(1);
        }
        exit($agent->snapshotRestore($uid));
    case 'snapshot-rebuild':
        exit($agent->snapshotRebuild());
    default:
        fwrite(STDERR, 'Aufruf: php scripts/storage_sync.php monitor|sync|recall|restore --target=<id> [--full] [--keep-paused]|resume|snapshots|snapshot-status|snapshot-prune|snapshot-retry|snapshot-restore --id=<uid>|snapshot-rebuild' . PHP_EOL);
        exit(1);
}
