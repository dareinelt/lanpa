<?php

declare(strict_types=1);

use App\Core\Request;
use App\Repositories\PhonebookRepository;
use App\Security\SsoAuth;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * @return array<string,mixed>
 */
function ssoConfig(): array
{
    return [
        'enabled' => true,
        'header' => 'X-Remote-User',
        'groups_header' => 'X-Remote-Groups',
        'trusted_proxy' => '10.0.0.2',
    ];
}

function ssoPhonebookPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE phonebook (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id TEXT NULL,
            identity_source_id INTEGER NOT NULL DEFAULT 0,
            samaccount_name TEXT NULL,
            display_name TEXT NULL,
            first_name TEXT NULL,
            last_name TEXT NULL,
            email TEXT NULL,
            department TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1
        )'
    );

    $insert = $pdo->prepare(
        'INSERT INTO phonebook (external_id, samaccount_name, display_name, email, department, active)
         VALUES (:external_id, :samaccount_name, :display_name, :email, :department, 1)'
    );
    $insert->execute([
        'external_id' => 'abc-1',
        'samaccount_name' => 'erika.muster',
        'display_name' => 'Erika Muster',
        'email' => 'erika.muster@example.internal',
        'department' => 'Verwaltung',
    ]);

    return $pdo;
}

function ssoRequest(array $server): Request
{
    return new Request('GET', '/', [], [], $server);
}

Runner::test('Benutzernamen werden normalisiert (Domain, UPN, Grossschreibung)', static function (): void {
    Assert::same('erika.muster', SsoAuth::normalizeUsername('DOMAIN\\Erika.Muster'));
    Assert::same('erika.muster', SsoAuth::normalizeUsername('Erika.Muster@example.internal'));
    Assert::same('erika.muster', SsoAuth::normalizeUsername('  Erika.Muster  '));
    Assert::same('erika.muster', SsoAuth::normalizeUsername('ERIKA.MUSTER'));
});

Runner::test('Ungültige Benutzernamen werden verworfen', static function (): void {
    Assert::null(SsoAuth::normalizeUsername(''));
    Assert::null(SsoAuth::normalizeUsername('   '));
    Assert::null(SsoAuth::normalizeUsername('un gültig'));
    Assert::null(SsoAuth::normalizeUsername('benutzer;DROP TABLE phonebook'));
});

Runner::test('Deaktiviertes SSO liefert keinen Benutzer', static function (): void {
    $config = ssoConfig();
    $config['enabled'] = false;
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), $config);
    $request = ssoRequest([
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_REMOTE_USER' => 'erika.muster',
    ]);

    Assert::null($sso->resolve($request));
});

Runner::test('Header von nicht vertrauenswürdiger Quelle wird ignoriert', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig());
    $request = ssoRequest([
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_REMOTE_USER' => 'erika.muster',
    ]);

    Assert::null($sso->resolve($request));
});

Runner::test('Gültiger Proxy-Header wird auf den Telefonbucheintrag abgebildet', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig());
    $request = ssoRequest([
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_REMOTE_USER' => 'DOMAIN\\Erika.Muster',
        'HTTP_X_REMOTE_GROUPS' => ' Verwaltung, IT ',
    ]);

    $user = $sso->resolve($request);

    Assert::true(is_array($user));
    Assert::same('erika.muster', $user['username']);
    Assert::same('Erika Muster', $user['display_name']);
    Assert::same(['verwaltung', 'it'], $user['groups']);
});

Runner::test('Unbekannter Benutzer liefert null (Fallback auf anonym)', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig());
    $request = ssoRequest([
        'REMOTE_ADDR' => '10.0.0.2',
        'HTTP_X_REMOTE_USER' => 'nicht.vorhanden',
    ]);

    Assert::null($sso->resolve($request));
});

Runner::test('Erkannte Windows-Anmeldung bleibt in der Sitzung (ohne Anmeldepflicht)', static function (): void {
    $_SESSION = [];
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig() + ['auto_login' => true, 'session_lifetime' => 3600]);

    // Ohne Anmeldung: anonym, einmaliger automatischer Versuch fuer Seitenaufrufe.
    $anonymous = ssoRequest(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_ACCEPT' => 'text/html,*/*']);
    Assert::null($sso->resolve($anonymous));
    Assert::true($sso->shouldAttempt($anonymous));
    Assert::false($sso->shouldAttempt(ssoRequest(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_ACCEPT' => 'application/json'])));

    $_SESSION[SsoAuth::ATTEMPT_KEY] = time();
    Assert::false($sso->shouldAttempt($anonymous));

    // Anmeldepunkt: Header des auth-Containers wird gemerkt.
    $user = $sso->resolveHeader(ssoRequest(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REMOTE_USER' => 'DOMAIN\\erika.muster']));
    Assert::true(is_array($user));
    $sso->remember($user);
    Assert::false(isset($_SESSION[SsoAuth::ATTEMPT_KEY]));

    // Folgeanfragen ohne Header (auch von beliebiger Adresse) nutzen die Sitzung.
    $again = $sso->resolve(ssoRequest(['REMOTE_ADDR' => '203.0.113.9']));
    Assert::true(is_array($again));
    Assert::same('erika.muster', $again['username']);
    Assert::false($sso->shouldAttempt($anonymous));

    // Abgelaufen: verworfen, erneuter Versuch erlaubt.
    $_SESSION[SsoAuth::SESSION_KEY]['at'] = time() - 7200;
    Assert::null($sso->resolve($anonymous));
    Assert::false(isset($_SESSION[SsoAuth::SESSION_KEY]));
    Assert::true($sso->shouldAttempt($anonymous));

    // Nicht mehr im Telefonbuch: verworfen.
    $_SESSION[SsoAuth::SESSION_KEY] = ['username' => 'nicht.vorhanden', 'source_key' => '', 'at' => time()];
    Assert::null($sso->resolve($anonymous));
    Assert::false(isset($_SESSION[SsoAuth::SESSION_KEY]));

    // Deaktiviertes SSO ignoriert die Sitzung.
    $_SESSION[SsoAuth::SESSION_KEY] = ['username' => 'erika.muster', 'source_key' => '', 'at' => time()];
    $disabled = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ['enabled' => false] + ssoConfig());
    Assert::null($disabled->resolve($anonymous));
    $_SESSION = [];
});

Runner::test('Automatischer Anmeldeversuch lässt sich abschalten', static function (): void {
    $_SESSION = [];
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig() + ['auto_login' => false]);
    Assert::false($sso->shouldAttempt(ssoRequest(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_ACCEPT' => 'text/html'])));
    $_SESSION = [];
});

Runner::test('Rücksprungziel der Windows-Anmeldung nur lokal', static function (): void {
    Assert::same('/seite?id=3', SsoAuth::safeTarget('/seite?id=3'));
    Assert::same('/', SsoAuth::safeTarget('https://evil.example/'));
    Assert::same('/', SsoAuth::safeTarget('//evil.example/'));
    Assert::same('/', SsoAuth::safeTarget('/\\evil.example'));
    Assert::same('/', SsoAuth::safeTarget("/x\r\nLocation: y"));
    Assert::same('/', SsoAuth::safeTarget('/sso/anmelden'));
    Assert::same('/', SsoAuth::safeTarget('/sso?ziel=/'));
    Assert::same('/ssoabc', SsoAuth::safeTarget('/ssoabc'));
    Assert::same('/sso?ziel=%2Funterseite%3Fid%3D2', SsoAuth::loginUrl('/unterseite?id=2'));
});
