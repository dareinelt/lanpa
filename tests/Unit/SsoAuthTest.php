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
