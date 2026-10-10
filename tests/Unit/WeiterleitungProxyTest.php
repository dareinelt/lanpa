<?php

declare(strict_types=1);

use App\Support\TileProxy;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Vertrag des auth-Proxys fuer externe Navigationskacheln
 * (docker/auth/weiterleitung-sync.sh, eingebunden ueber docker/auth/common.conf):
 * Struktur der Konfiguration, die sich ohne laufenden Apache pruefen laesst.
 * Die Apache-Syntax selbst prueft das Skript mit apache2ctl -t und der
 * Testlauf im Image (siehe docs/weiterleitung.md).
 */
function weiterleitungFile(string $relative): string
{
    $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    Assert::true(is_string($content), $relative . ' fehlt.');

    return str_replace("\r\n", "\n", (string) $content);
}

/**
 * Anweisungen aus weiterleitung-sync.sh in der Form, in der Apache sie liest:
 * die echo-Zeilen des Skripts sind mit \" maskiert.
 */
function weiterleitungDirectives(string $relative): string
{
    return str_replace('\\"', '"', weiterleitungFile($relative));
}

/**
 * Muster einer Funktion aus weiterleitung-sync.sh (grep -E in einfachen
 * Anfuehrungszeichen).
 */
function weiterleitungPattern(string $script, string $function): string
{
    $found = preg_match(
        '/' . preg_quote($function, '/') . "\(\) \{\n[^\n]*grep -Eq '([^']+)'/",
        $script,
        $matches
    );
    Assert::same(1, $found, 'Muster von ' . $function . '() nicht gefunden.');

    return $matches[1];
}

Runner::test('Weiterleitung: Konfiguration wird unbedingt und vor dem Catch-all eingebunden', function (): void {
    $common = weiterleitungFile('docker/auth/common.conf');
    $include = strpos($common, 'IncludeOptional /etc/apache2/intranet/weiterleitung.conf');
    $catchAll = strpos($common, 'ProxyPass / http://app/');

    Assert::true($include !== false, 'weiterleitung.conf muss eingebunden werden.');
    Assert::true(
        $catchAll !== false && $include < $catchAll,
        'weiterleitung.conf muss vor "ProxyPass /" stehen, damit die <Location>-Bloecke greifen.'
    );

    // Die Ziele stehen im Adminbereich der Anwendung; der Container braucht
    // dafuer keine Umgebungsvariable.
    Assert::false(str_contains($common, 'NAV_PROXY'), 'Die Weiterleitung darf nicht von Variablen abhaengen.');
    Assert::false(str_contains($common, '<IfDefine NAV_PROXY>'), 'Die Weiterleitung ist nicht optional.');
});

Runner::test('Weiterleitung: Skript und Apache-Module liegen im Image', function (): void {
    $dockerfile = weiterleitungFile('docker/auth/Dockerfile');

    Assert::contains('proxy_html substitute xml2enc', $dockerfile);
    Assert::contains('COPY docker/auth/weiterleitung-sync.sh /usr/local/bin/weiterleitung-sync.sh', $dockerfile);
    Assert::contains('/usr/local/bin/weiterleitung-sync.sh /usr/local/bin/backend-watch.sh', $dockerfile);

    $entrypoint = weiterleitungFile('docker/auth/entrypoint.sh');
    Assert::contains('/usr/local/bin/weiterleitung-sync.sh once || true', $entrypoint);
    Assert::contains('/usr/local/bin/weiterleitung-sync.sh loop &', $entrypoint);
});

Runner::test('Weiterleitung: erzeugte Konfiguration spiegelt das Ziel und tarnt den Host', function (): void {
    $script = weiterleitungFile('docker/auth/weiterleitung-sync.sh');
    $conf = weiterleitungDirectives('docker/auth/weiterleitung-sync.sh');

    Assert::same(1, preg_match('/^[\x09\x0A\x20-\x7E]*$/', $script), 'weiterleitung-sync.sh enthaelt Nicht-ASCII-Zeichen.');
    Assert::contains('ProxyPass ${origin}/ upgrade=websocket timeout=600 retry=3', $conf);
    Assert::contains('ProxyPassReverse ${origin}/', $conf);
    // Cookies gehoeren dem Proxy-Host, nicht dem Ziel.
    Assert::contains('ProxyPassReverseCookiePath / ${path}', $conf);
    Assert::contains('Header edit Set-Cookie "(?i);[[:space:]]*domain=[^;]*" ""', $conf);
    // Umleitungen des Ziels bleiben im Adressraum des Proxys.
    Assert::contains('Header edit Location "^https?://${host}/" "${path}"', $conf);
    Assert::contains('Header edit Location "^/(?!weiterleitung/${id}/)" "${path}"', $conf);
    // Die Zielanwendung darf von der Weiterleitung nichts mitbekommen.
    Assert::contains('ProxyAddHeaders On', $conf);
    Assert::contains('RequestHeader set Host "${host}"', $conf);
    Assert::contains('RequestHeader set X-Forwarded-Prefix "${path%/}"', $conf);
    Assert::contains('RequestHeader unset X-Remote-User', $conf);
    Assert::contains('RequestHeader unset X-Remote-Source', $conf);
    // Adressen im HTML, CSS und JavaScript werden auf den Praefix umgeschrieben.
    Assert::contains('ProxyHTMLEnable On', $conf);
    Assert::contains('ProxyHTMLURLMap / ${path}', $conf);
    Assert::contains('AddOutputFilterByType SUBSTITUTE text/css application/javascript', $conf);
    Assert::contains('Substitute "s|https://${host}/|${path}|i"', $conf);
    // Ohne unkomprimierte Antworten findet kein Rewriting statt.
    Assert::contains('SetEnv no-gzip 1', $conf);
    Assert::contains('RequestHeader unset Accept-Encoding', $conf);
    // Kein Ziel hinterlegt: Hinweisseite statt Weiterleitung an die Anwendung.
    Assert::contains('ProxyPass "!"', $conf);
    Assert::contains('ErrorDocument 404 ${UNAVAILABLE_PATH}', $conf);
    foreach (['500', '502', '503'] as $status) {
        Assert::contains('ErrorDocument ' . $status . ' ${UNAVAILABLE_PATH}', $conf);
    }
    // Die Hinweisseite und jedes Ziel sind ohne Windows-Anmeldung erreichbar.
    Assert::same(2, substr_count($conf, 'AuthType None'), 'Hinweisseite und Ziele ohne NTLM.');
    Assert::same(2, substr_count($conf, 'Require all granted'));
});

Runner::test('Weiterleitung: Zertifikat des Ziels wird geprueft, Reload nur nach Syntaxpruefung', function (): void {
    $script = weiterleitungDirectives('docker/auth/weiterleitung-sync.sh');

    Assert::contains('SSLProxyEngine On', $script);
    Assert::contains('SSLProxyVerify require', $script);
    Assert::contains('SSLProxyCACertificateFile /etc/ssl/certs/ca-certificates.crt', $script);
    Assert::contains('SSLProxyCheckPeerName On', $script);
    // Ausnahme nur ausdruecklich per Umgebungsvariable des Containers.
    Assert::contains('SSL_VERIFY="${NAV_PROXY_SSL_VERIFY:-require}"', $script);
    Assert::contains('SSLProxyVerify none', $script);

    $test = strpos($script, 'apache2ctl -t >/dev/null 2>&1');
    $reload = strpos($script, 'apache2ctl graceful');
    Assert::true($test !== false && $reload !== false && $test < $reload, 'Reload erst nach apache2ctl -t.');
    Assert::contains('cmp -s "$work/conf" "$CONF_FILE"', $script);
    // Zielverzeichnis anlegen und Schreibfehler melden, statt Erfolg zu behaupten.
    Assert::contains('mkdir -p "$conf_dir"', $script);
    Assert::contains('konnte nicht nach ${CONF_FILE} geschrieben werden', $script);
    Assert::contains('-H "@${work}/header"', $script);
    Assert::contains('X-Intranet-Sso-Token', $script);
});

Runner::test('Weiterleitung: Adressmuster stimmen mit App\\Support\\TileProxy ueberein', function (): void {
    $script = weiterleitungFile('docker/auth/weiterleitung-sync.sh');
    $reflection = new ReflectionClass(TileProxy::class);

    // Ziel-URL: die Anwendung vergibt den Verweis, der Proxy muss ihn bedienen.
    $php = (string) $reflection->getConstant('PROXYABLE_URL');
    $php = str_replace(['\x23', '\s'], ['#', '[:space:]'], substr($php, 1, -1));
    Assert::same($php, weiterleitungPattern($script, 'url_ok'));

    // Oeffentlicher Praefix der Kacheln.
    Assert::same(
        '^' . (string) $reflection->getConstant('PATH_PREFIX') . '[0-9]+/$',
        weiterleitungPattern($script, 'path_ok')
    );
    Assert::contains(
        'UNAVAILABLE_PATH="' . (string) $reflection->getConstant('UNAVAILABLE_PATH') . '"',
        $script
    );
});

Runner::test('Weiterleitung: Hinweisseite /weiterleitung-nicht-verfuegbar ist registriert', function (): void {
    Assert::contains(
        "\$router->get('/weiterleitung-nicht-verfuegbar', [WeiterleitungController::class, 'unavailable']);",
        weiterleitungFile('public/index.php')
    );
    Assert::true(method_exists(\App\Controllers\WeiterleitungController::class, 'unavailable'));
    Assert::true(is_file(dirname(__DIR__, 2) . '/views/weiterleitung/unavailable.php'));
});
