<?php

declare(strict_types=1);

/**
 * Dauerlaeufer fuer die AD-Synchronisation (Container "sync").
 * Das Intervall wird bei jedem Durchlauf frisch aus den Einstellungen gelesen.
 *
 * Aufruf: php scripts/sync_worker.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Core\Container;
use App\Services\LdapClient;

$defaultInterval = 3600;

fwrite(STDOUT, 'AD-Synchronisationsdienst gestartet.' . PHP_EOL);

while (true) {
    $interval = $defaultInterval;

    try {
        // Container zuruecksetzen, damit Konfigurationsaenderungen wirksam werden.
        Container::reset();
        $settings = Container::settings();
        $interval = max(60, min(86400, $settings->int('ldap_sync_interval', $defaultInterval)));

        if (!LdapClient::isSupported()) {
            fwrite(STDERR, 'Die PHP-Erweiterung "ldap" fehlt – Synchronisation wird übersprungen.' . PHP_EOL);
        } elseif (!$settings->isLdapConfigured()) {
            fwrite(STDOUT, 'LDAP ist noch nicht konfiguriert – warte auf Konfiguration.' . PHP_EOL);
        } else {
            $result = Container::adSync()->run();
            fwrite(
                $result['status'] === 'success' ? STDOUT : STDERR,
                sprintf(
                    '[%s] Synchronisation: %s (%d aktualisiert, %d deaktiviert)%s',
                    date('c'),
                    $result['status'],
                    $result['processed'],
                    $result['deactivated'],
                    PHP_EOL
                )
            );
        }
    } catch (Throwable $exception) {
        // Der Dienst darf nie sterben – Fehler werden protokolliert.
        app_logger()->error('Synchronisationsdienst: unerwarteter Fehler.', ['error' => $exception->getMessage()]);
        fwrite(STDERR, '[' . date('c') . '] Fehler im Synchronisationsdienst.' . PHP_EOL);
    }

    sleep($interval);
}
