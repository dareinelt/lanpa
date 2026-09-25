<?php

declare(strict_types=1);

use App\Repositories\PhonebookRepository;
use App\Security\SsoAuth;
use App\Services\Office\OfficeJwt;
use OCA\IntranetIntegration\Service\TokenVerifier;
use Tests\Support\Assert;
use Tests\Support\FakeAdGroupStore;
use Tests\Support\Runner;

require_once BASE_PATH . '/docker/nextcloud/apps/intranet_integration/lib/Service/TokenVerifier.php';

/**
 * @param array<string,mixed> $overrides
 */
function fakeSsoConfig(array $overrides = []): array
{
    return $overrides + [
        'enabled' => false,
        'header' => 'X-Remote-User',
        'groups_header' => 'X-Remote-Groups',
        'trusted_proxy' => '',
        'fake_user' => 'Erika.Muster',
        'fake_display_name' => '',
        'fake_email' => '',
        'fake_groups' => 'GG-Office-Basis, GG-Controlling',
        'fake_allowed' => true,
    ];
}

/**
 * @return array{username:string,display_name:string,email:string}
 */
function officeSsoUser(): array
{
    return ['username' => 'erika.muster', 'display_name' => 'Erika Muster', 'email' => 'erika.muster@example.internal'];
}

/**
 * @return array<string,mixed>
 */
function ssoTokenFromUrl(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $parts = explode('.', (string) ($query['token'] ?? ''));

    return (array) json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
}

Runner::test('Simulierte SSO-Anmeldung nutzt den Telefonbucheintrag ohne Header und Proxy', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), fakeSsoConfig(), new FakeAdGroupStore([1 => ['verwaltung']]));

    $user = $sso->resolve(ssoRequest(['REMOTE_ADDR' => '192.0.2.10']));

    Assert::true($sso->isEnabled());
    Assert::true($sso->isFake());
    Assert::same('erika.muster', $user['username'] ?? null);
    Assert::same('Erika Muster', $user['display_name'] ?? null);
    Assert::same('erika.muster@example.internal', $user['email'] ?? null);
    Assert::same(1, $user['id'] ?? null);
    Assert::true((bool) ($user['fake'] ?? false));
    Assert::same(['gg-office-basis', 'gg-controlling', 'verwaltung'], $user['groups'] ?? null);
});

Runner::test('Simulierte SSO-Anmeldung ohne Telefonbucheintrag liefert Testbenutzer', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), fakeSsoConfig([
        'fake_user' => 'test.user',
        'fake_display_name' => 'Test Benutzer',
        'fake_email' => 'test@example.internal',
    ]));

    $user = $sso->resolve(ssoRequest([]));

    Assert::same(0, $user['id'] ?? null);
    Assert::same('Test Benutzer', $user['display_name'] ?? null);
    Assert::same('test@example.internal', $user['email'] ?? null);
    Assert::same(['gg-office-basis', 'gg-controlling'], $user['groups'] ?? null);
});

Runner::test('Simulierte SSO-Anmeldung ist in Produktion und bei ungültigem Namen wirkungslos', static function (): void {
    $production = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), fakeSsoConfig(['fake_allowed' => false]));
    Assert::false($production->isEnabled());
    Assert::null($production->resolve(ssoRequest([])));

    $invalid = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), fakeSsoConfig(['fake_user' => 'un gültig']));
    Assert::false($invalid->isFake());
    Assert::null($invalid->resolve(ssoRequest([])));
});

Runner::test('Echte SSO-Anmeldung liefert E-Mail und keine Testkennung', static function (): void {
    $sso = new SsoAuth(new PhonebookRepository(ssoPhonebookPdo()), ssoConfig());
    $user = $sso->resolve(ssoRequest(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_REMOTE_USER' => 'DOMAIN\\Erika.Muster']));

    Assert::same('erika.muster@example.internal', $user['email'] ?? null);
    Assert::false((bool) ($user['fake'] ?? true));
});

Runner::test('Office-Einstieg reicht den Benutzer per signiertem Einmal-Token an Nextcloud weiter', static function (): void {
    $office = officeConfig();
    $url = (string) $office->ssoEntryUrl(officeSsoUser(), '/office/index.php/apps/files/', 1_700_000_000);

    Assert::true(str_starts_with($url, '/office/index.php/apps/intranet_integration/sso?token='));

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $claims = OfficeJwt::decode((string) $query['token'], OfficeJwt::ssoKey('test-secret-0123456789'), 1_700_000_010);
    Assert::same(OfficeJwt::SSO_AUDIENCE, $claims['aud'] ?? null);
    Assert::same('erika.muster', $claims['sub'] ?? null);
    Assert::same('Erika Muster', $claims['name'] ?? null);
    Assert::same('/office/index.php/apps/files/', $claims['target'] ?? null);
    Assert::same(1_700_000_060, $claims['exp'] ?? null);
    Assert::same(1, preg_match('/^[a-f0-9]{32}$/', (string) ($claims['jti'] ?? '')));

    // Nicht mit dem Euro-Office-Secret selbst gueltig (Domaenentrennung).
    Assert::null(OfficeJwt::decode((string) $query['token'], 'test-secret-0123456789', 1_700_000_010));
});

Runner::test('Office-Einstieg: Ziel wird bereinigt, ohne Secret keine Weitergabe', static function (): void {
    $claims = ssoTokenFromUrl((string) officeConfig()->ssoEntryUrl(officeSsoUser(), 'https://evil.example/office/'));
    Assert::same('/office/', $claims['target'] ?? null);

    Assert::null(officeConfig([], ['jwt_secret' => ''])->ssoEntryUrl(officeSsoUser(), '/office/'));
    Assert::null(officeConfig()->ssoEntryUrl(['username' => ''], '/office/'));
});

Runner::test('Nextcloud-App akzeptiert das Anmelde-Token des Intranets', static function (): void {
    $verifier = new TokenVerifier();
    $secret = 'test-secret-0123456789';
    $now = time();
    $url = (string) officeConfig()->ssoEntryUrl(officeSsoUser(), '/office/index.php/apps/files/', $now);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = (string) $query['token'];

    $claims = $verifier->claims($token, TokenVerifier::ssoKey($secret), TokenVerifier::SSO_AUDIENCE, $now + 5);
    Assert::same('erika.muster', $claims['sub'] ?? null);

    // Abgelaufen, falsche Audience, falscher Schluessel, Diagnose-Token.
    Assert::null($verifier->claims($token, TokenVerifier::ssoKey($secret), TokenVerifier::SSO_AUDIENCE, $now + 61));
    Assert::null($verifier->claims($token, TokenVerifier::ssoKey($secret), TokenVerifier::AUDIENCE, $now + 5));
    Assert::null($verifier->claims($token, $secret, TokenVerifier::SSO_AUDIENCE, $now + 5));
    Assert::false($verifier->verify($token, TokenVerifier::ssoKey($secret), $now + 5));
    Assert::null($verifier->claims(OfficeJwt::diagnosticsToken($secret, $now), TokenVerifier::ssoKey($secret), TokenVerifier::SSO_AUDIENCE, $now));
    Assert::true($verifier->verify(OfficeJwt::diagnosticsToken($secret, $now), $secret, $now));
});
