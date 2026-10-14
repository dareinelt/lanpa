<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Die Anwendung liefert die Instanz-Konfiguration als "NAME=base64(wert)" je
 * Zeile (App\Controllers\InternalController::lines()). Wird eine Zeile mit
 * IFS='=' gelesen, verschluckt die Shell bei base64-Werten mit genau einem
 * '=' am Ende dieses Zeichen (z. B. MVZINTSZ -> TVZaSU5UU1o=). base64 -d
 * scheitert dann an der fehlenden Auffuellung und der Wert fehlt. Genau so
 * verlor eine zusaetzliche Domaene ihren Namen und ihr Beitrittskennwort: die
 * Instanz startete als WORKGROUP ohne Kerberos und der Browser fragte endlos
 * nach Zugangsdaten. Deshalb wird erst am ersten '=' getrennt.
 */
function authConfigScript(string $relative): string
{
    $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    Assert::true(is_string($content), $relative . ' fehlt.');

    return str_replace("\r\n", "\n", (string) $content);
}

Runner::test('Instanz-Konfiguration: Zeile wird am ersten = getrennt', function (): void {
    $script = authConfigScript('docker/auth/entrypoint.sh');

    Assert::contains('while IFS= read -r line || [ -n "$line" ]; do', $script);
    Assert::contains('name="${line%%=*}"', $script);
    Assert::contains('value="${line#*=}"', $script);
    Assert::false(
        str_contains($script, "IFS='=' read"),
        'Die Zeile darf nicht mit IFS=\'=\' gelesen werden.'
    );

    // Nur bekannte Namen uebernehmen (kein eval) bleibt bestehen.
    Assert::contains('SSO_JOIN_PASSWORD|SSO_ROUTES) ;;', $script);
});

Runner::test('Zertifikats- und Weiterleitungsskript trennen am ersten =', function (): void {
    $tls = authConfigScript('docker/auth/tls-sync.sh');
    Assert::contains('name="${line%%=*}"', $tls);
    Assert::contains('value="${line#*=}"', $tls);
    Assert::false(
        str_contains($tls, "IFS='=' read"),
        'tls-sync.sh darf nicht mit IFS=\'=\' lesen.'
    );

    $weiterleitung = authConfigScript('docker/auth/weiterleitung-sync.sh');
    Assert::contains('if [ "${line%%=*}" = "$2" ]; then', $weiterleitung);
    Assert::contains('printf \'%s\' "${line#*=}"', $weiterleitung);
    Assert::false(
        str_contains($weiterleitung, "IFS='=' read"),
        'weiterleitung-sync.sh darf nicht mit IFS=\'=\' lesen.'
    );
});

Runner::test('Nextcloud-Aufbau trennt die LDAP-Konfiguration am ersten =', function (): void {
    $script = authConfigScript('docker/nextcloud/hooks/intranet-setup.sh');

    Assert::contains('key="${line%%=*}"', $script);
    Assert::contains('value="${line#*=}"', $script);
    Assert::false(
        str_contains($script, "IFS='=' read"),
        'intranet-setup.sh darf nicht mit IFS=\'=\' lesen.'
    );
});
