<?php

declare(strict_types=1);

/**
 * Einmalige Active-Directory-Synchronisation.
 *
 * Aufruf: php scripts/sync_ad.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;
use App\Services\LdapClient;

if (!LdapClient::isSupported()) {
    fwrite(STDERR, 'Die PHP-Erweiterung "ldap" ist nicht installiert.' . PHP_EOL);
    exit(2);
}

if (!Container::settings()->isLdapConfigured()) {
    fwrite(STDERR, 'LDAP ist nicht vollständig konfiguriert (Host und Base DN erforderlich).' . PHP_EOL);
    exit(3);
}

$result = Container::adSync()->run();

if ($result['status'] === 'success') {
    fwrite(STDOUT, sprintf(
        'Synchronisation erfolgreich: %d aktualisiert, %d deaktiviert.%s',
        $result['processed'],
        $result['deactivated'],
        PHP_EOL
    ));
    exit(0);
}

fwrite(STDERR, 'Synchronisation fehlgeschlagen. Der letzte gültige Datenbestand bleibt erhalten.' . PHP_EOL);
exit(1);
