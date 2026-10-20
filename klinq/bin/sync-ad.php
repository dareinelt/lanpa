#!/usr/bin/env php
<?php
/**
 * AD-Synchronisation per Kommandozeile (manuell oder per Cron).
 *
 * Aufruf: php bin/sync-ad.php [--dry-run] [--by=NAME] [--quiet]
 * Exit-Code 0 bei Erfolg, 1 bei Fehler, 2 wenn deaktiviert.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap/autoload.php';

use App\Core\ApplicationFactory;
use App\Services\Ad\EmployeeSyncService;

$options = getopt('', ['dry-run', 'by::', 'quiet']);
$container = ApplicationFactory::container(dirname(__DIR__));
$sync = $container->get(EmployeeSyncService::class);
$quiet = isset($options['quiet']);

if (!$sync->isEnabled()) {
    if (!$quiet) {
        fwrite(STDERR, "AD-Synchronisation ist deaktiviert (AD_ENABLED=false).\n");
    }
    exit(2);
}

try {
    $result = $sync->run((string) ($options['by'] ?? 'cli'), isset($options['dry-run']));
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!$quiet) {
    echo ($result['status'] === 'success' ? 'OK' : 'FEHLER') . ': ' . ($result['message'] ?? '') . "\n";
}
exit($result['status'] === 'success' ? 0 : 1);
