<?php

declare(strict_types=1);

/**
 * Speicher-Tiering-Status fuer den SNMP-Dienst (docker/snmp/check_status.sh).
 *
 * Aufruf: php scripts/storage_status.php <storage_ha|storage_sync|storage_hot_fill|
 *                                         storage_cold_fill|storage_metrics|storage_targets>
 *
 * Ausgabe: erste Zeile = extOutput, weitere Zeilen fuer NET-SNMP-EXTEND-MIB.
 * Exit-Code: 0 = OK, 1 = Warnung, 2 = kritisch, 3 = inaktiv/unbekannt.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;

$check = (string) ($argv[1] ?? '');

try {
    $result = Container::storage()->snmp($check);
} catch (Throwable $e) {
    fwrite(STDOUT, $check . ': Status nicht lesbar (Datenbank nicht erreichbar?)' . PHP_EOL);
    exit(2);
}

fwrite(STDOUT, implode(PHP_EOL, $result['lines']) . PHP_EOL);
exit($result['exit']);
