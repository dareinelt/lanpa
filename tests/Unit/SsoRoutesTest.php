<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Vertrag der HAProxy-Verteilung in docker/auth/sso-routes.sh: eine weitere
 * Domaene ohne erreichbare Anmelde-Instanz darf nicht stillschweigend von der
 * Hauptinstanz bedient werden. Die Hauptinstanz gehoert zu einer anderen
 * Domaene, ihre GSSAPI-Pruefung schlaegt fehl und der Browser fragt endlos
 * nach Zugangsdaten - ohne Hinweis im Protokoll. Geprueft wird die erzeugte
 * Konfiguration (Struktur und Reihenfolge); die Syntax selbst prueft das
 * Image mit haproxy -c (siehe docs/... und README, Abschnitt Windows-Anmeldung).
 */
function ssoRoutesFile(string $relative): string
{
    $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    Assert::true(is_string($content), $relative . ' fehlt.');

    return str_replace("\r\n", "\n", (string) $content);
}

Runner::test('SSO-Routen: Ausfall einer weiteren Domaene wird nicht an die Hauptinstanz geleitet', function (): void {
    $script = ssoRoutesFile('docker/auth/sso-routes.sh');

    Assert::contains('use_backend down_${key} if', $script);
    Assert::contains('!{ nbsrv(src_${key}) gt 0 }', $script);
    Assert::contains('http-request set-log-level warning', $script);
    Assert::contains('http-request return status 503', $script);
    Assert::contains('backend down_${key}', $script);

    // Die Ausfall-Regel nennt die fehlende Instanz und zeigt einen Hinweis
    // statt des Anmeldedialogs der falschen Domaene.
    Assert::contains('<code>${service}</code>', $script);
    Assert::contains('./scripts/sso-domains.sh', $script);
});

Runner::test('SSO-Routen: eigene Instanz, Ausfall-Regel und Hauptinstanz in dieser Reihenfolge', function (): void {
    $script = ssoRoutesFile('docker/auth/sso-routes.sh');

    $healthy = strpos($script, '"$host_rules" "$net_rules"');
    $down = strpos($script, '"$down_rules"');
    $primary = strpos($script, "\n    default_backend primary\n");

    Assert::true(
        $healthy !== false && $down !== false && $primary !== false,
        'Ausgabe der Regeln und default_backend primary muessen im Skript stehen.'
    );
    Assert::true(
        $healthy < $down && $down < $primary,
        'default_backend primary darf erst nach den Regeln der weiteren Domaenen greifen.'
    );
});

Runner::test('SSO-Routen: alle Anmeldepfade sind der Ausfall-Regel bekannt', function (): void {
    $script = ssoRoutesFile('docker/auth/sso-routes.sh');

    // Pfade mit AuthType GSSAPI: common.conf, llmint.conf, office.conf.
    Assert::contains('login_paths="/sso/anmelden /sso/laufwerke"', $script);
    Assert::contains('${LLMINT_PATH}/sso.php', $script);
    Assert::contains('/office/index.php/apps/user_saml/saml/login', $script);

    // Ohne diese Pfade wuerde der Anmeldedialog der Hauptinstanz erscheinen.
    Assert::contains('{ path_beg ${login_paths} }', $script);
});

Runner::test('SSO-Domaenen: fehlender Dienst wird beim Erzeugen gemeldet', function (): void {
    $script = ssoRoutesFile('scripts/sso-domains.sh');

    Assert::contains('docker compose config --services', $script);
    Assert::contains('FEHLER: nicht im Compose-Projekt: ${missing}', $script);
    Assert::contains('COMPOSE_FILE=docker-compose.yml:docker-compose.sso.yml', $script);
    Assert::contains('exit 1', $script);
});
