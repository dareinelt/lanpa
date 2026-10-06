<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Vertrag des auth-Proxys fuer LLMInt unter /ki/ (docker/auth/llmint.conf):
 * Struktur der Konfiguration, die sich ohne laufenden Apache pruefen laesst.
 * Die Apache-Syntax selbst wird im Image geprueft (apache2ctl -t).
 */
function llmintFile(string $relative): string
{
    $content = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    Assert::true(is_string($content), $relative . ' fehlt.');

    return str_replace("\r\n", "\n", (string) $content);
}

Runner::test('LLMInt-Proxy: nur mit -D LLMINT eingebunden, vor dem Catch-all an app', function (): void {
    $common = llmintFile('docker/auth/common.conf');
    $include = strpos($common, "<IfDefine LLMINT>\n    Include /etc/apache2/intranet/llmint.conf\n</IfDefine>");
    $catchAll = strpos($common, 'ProxyPass / http://app/');

    Assert::true($include !== false, 'llmint.conf muss ueber <IfDefine LLMINT> eingebunden werden.');
    Assert::true($catchAll !== false && $include < $catchAll, 'llmint.conf muss vor "ProxyPass /" stehen.');
    Assert::contains('COPY docker/auth/llmint.conf /etc/apache2/intranet/llmint.conf', llmintFile('docker/auth/Dockerfile'));
});

Runner::test('LLMInt-Proxy: Weiterleitung, Streaming, Cookies und Header nach Vertrag', function (): void {
    $conf = llmintFile('docker/auth/llmint.conf');

    Assert::contains('RewriteRule ^${LLMINT_PATH}$ ${LLMINT_PATH}/ [R=301,L]', $conf);
    Assert::contains('ProxyPass ${LLMINT_UPSTREAM}/ timeout=3600 flushpackets=on retry=5', $conf);
    Assert::contains('ProxyPassReverse ${LLMINT_UPSTREAM}/', $conf);
    Assert::contains('ProxyPassReverseCookiePath / ${LLMINT_PATH}/', $conf);
    Assert::contains('SetEnv no-gzip 1', $conf);
    Assert::contains('RequestHeader set X-Forwarded-Prefix "${LLMINT_PATH}"', $conf);
    Assert::contains('RequestHeader unset X-Forwarded-For', $conf);
    Assert::contains('RequestHeader unset X-Forwarded-Host', $conf);
    Assert::contains('RequestHeader unset X-Remote-User', $conf);
    Assert::contains('RequestHeader unset X-Remote-Source', $conf);
    foreach (['500', '502', '503'] as $status) {
        Assert::contains('ErrorDocument ' . $status . ' /ki-nicht-verfuegbar', $conf);
    }
    // Keine ASCII-fremden Zeichen (Konvention der Apache-Konfiguration).
    Assert::same(1, preg_match('/^[\x09\x0A\x20-\x7E]*$/', $conf), 'llmint.conf enthaelt Nicht-ASCII-Zeichen.');
});

Runner::test('LLMInt-Proxy: SSO nur an sso.php der Hauptinstanz, nach <Location /ki/>', function (): void {
    $conf = llmintFile('docker/auth/llmint.conf');
    $base = strpos($conf, '<Location ${LLMINT_PATH}/>');
    $sso = strpos($conf, '<Location ${LLMINT_PATH}/sso.php>');

    Assert::true($base !== false && $sso !== false && $base < $sso, 'sso.php muss nach <Location /ki/> stehen (Header unset -> set).');

    $guard = strpos($conf, "<IfDefine SSO_NTLM>\n<IfDefine !SSO_WORKER>");
    Assert::true($guard !== false && $guard < $sso, 'sso.php-Anmeldung nur mit SSO_NTLM und ohne SSO_WORKER.');

    $block = substr($conf, $sso, (int) strpos($conf, '</Location>', $sso) - $sso);
    Assert::contains('AuthType GSSAPI', $block);
    Assert::contains('GssapiAllowedMech ntlmssp', $block);
    Assert::contains('GssapiConnectionBound On', $block);
    Assert::contains('Require valid-user', $block);
    Assert::contains('ErrorDocument 401 ${LLMINT_PATH}/sso_fallback.php', $block);
    Assert::contains('ErrorDocument 500 ${LLMINT_PATH}/sso_fallback.php', $block);
    Assert::contains('RequestHeader set X-Remote-User "expr=%{REMOTE_USER}"', $block);
    Assert::false(str_contains($block, 'X-Remote-Source'), 'sso.php darf X-Remote-Source nie setzen.');
    Assert::same(1, substr_count($conf, 'RequestHeader set X-Remote-User'), 'X-Remote-User nur an sso.php.');
});

Runner::test('LLMInt-Proxy: Standard aus, Variablen in Compose, Workern und .env.example', function (): void {
    $compose = llmintFile('docker-compose.yml');
    Assert::contains('LLMINT_ENABLED: ${LLMINT_ENABLED:-false}', $compose);
    Assert::contains('LLMINT_UPSTREAM: ${LLMINT_UPSTREAM:-}', $compose);
    Assert::contains('LLMINT_PATH: ${LLMINT_PATH:-/ki}', $compose);

    $workers = llmintFile('scripts/sso-domains.sh');
    Assert::contains('LLMINT_ENABLED: \${LLMINT_ENABLED:-false}', $workers);
    Assert::contains('LLMINT_UPSTREAM: \${LLMINT_UPSTREAM:-}', $workers);

    $override = llmintFile('docker-compose.llmint.yml');
    Assert::contains('name: ${LLMINT_PROXY_NETWORK:-llmint-proxy}', $override);
    Assert::contains('external: true', $override);

    $entrypoint = llmintFile('docker/auth/entrypoint.sh');
    Assert::contains(': "${LLMINT_ENABLED:=false}"', $entrypoint);
    Assert::contains(': "${LLMINT_PATH:=/ki}"', $entrypoint);
    Assert::contains('APACHE_DEFINES="${APACHE_DEFINES} -D LLMINT"', $entrypoint);

    Assert::contains("\nLLMINT_ENABLED=false\n", llmintFile('.env.example'));
});

Runner::test('LLMInt-Proxy: Hinweisseite /ki-nicht-verfuegbar ist registriert', function (): void {
    Assert::contains("\$router->get('/ki-nicht-verfuegbar', [KiController::class, 'unavailable']);", llmintFile('public/index.php'));
    Assert::true(method_exists(\App\Controllers\KiController::class, 'unavailable'));
    Assert::true(is_file(dirname(__DIR__, 2) . '/views/ki/unavailable.php'));
});
