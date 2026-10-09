<?php

declare(strict_types=1);

use App\Core\Request;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\ClientAddress;
use Tests\Support\Assert;
use Tests\Support\FakeAdminUserStore;
use Tests\Support\Runner;

Runner::test('CSRF-Token ist stabil und wird geprüft', static function (): void {
    $_SESSION = [];
    $token = Csrf::token();

    Assert::same(64, strlen($token));
    Assert::same($token, Csrf::token());
    Assert::true(Csrf::isValid($token));
    Assert::false(Csrf::isValid('falsch'));
    Assert::false(Csrf::isValid(null));
    Assert::false(Csrf::isValid(''));
});

Runner::test('CSRF-Token wird nach Rotation neu erzeugt', static function (): void {
    $_SESSION = [];
    $first = Csrf::token();
    Csrf::rotate();

    Assert::false(Csrf::isValid($first));
    Assert::false($first === Csrf::token());
});

Runner::test('CSRF-Feld enthält den Token als verstecktes Eingabefeld', static function (): void {
    $_SESSION = [];
    $field = Csrf::field();

    Assert::contains('name="_token"', $field);
    Assert::contains(Csrf::token(), $field);
});

Runner::test('Anmeldung mit korrektem Passwort ist erfolgreich', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 1,
        'username' => 'admin',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'role' => 'admin',
        'active' => 1,
    ]);
    $auth = new Auth($store);

    Assert::true($auth->attempt('admin', 'sicheres-passwort'));
    Assert::true($auth->check());
    Assert::same(1, $auth->id());
    Assert::same('admin', $auth->username());
    Assert::same('admin', $auth->role());
    Assert::true($auth->isAdmin());
    Assert::same(1, $store->logins);
});

Runner::test('Benutzer der Gruppe Redaktion werden nicht als Administrator erkannt', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 2,
        'username' => 'redakteur',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'role' => 'redaktion',
        'active' => 1,
    ]);
    $auth = new Auth($store);

    Assert::true($auth->attempt('redakteur', 'sicheres-passwort'));
    Assert::same('redaktion', $auth->role());
    Assert::false($auth->isAdmin());
});

Runner::test('Lokale KAEP-Rechte werden bei jeder Anfrage erneut geprüft', static function (): void {
    $_SESSION = [];
    $user = ['id' => 8, 'username' => 'kaep', 'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1];
    $auth = new Auth(new FakeAdminUserStore($user));
    Assert::true($auth->attempt('kaep', 'sicheres-passwort'));

    $auth = new Auth(new FakeAdminUserStore(array_replace($user, ['role' => 'kaep'])));
    Assert::true($auth->check());
    Assert::same('kaep', $auth->role());
    Assert::false($auth->isAdmin());

    $auth = new Auth(new FakeAdminUserStore(array_replace($user, ['role' => 'redaktion'])));
    Assert::true($auth->check());
    Assert::same('redaktion', $auth->role());

    $auth = new Auth(new FakeAdminUserStore());
    Assert::false($auth->check());
    Assert::null($auth->role());
    Assert::null($auth->id());
});

Runner::test('Ein neu angelegtes Konto gleichen Namens übernimmt keine alte Sitzung', static function (): void {
    $_SESSION = [];
    $user = ['id' => 8, 'username' => 'kaep', 'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT), 'role' => 'kaep', 'active' => 1];
    $auth = new Auth(new FakeAdminUserStore($user));
    Assert::true($auth->attempt('kaep', 'sicheres-passwort'));
    $replacement = new Auth(new FakeAdminUserStore(array_replace($user, ['id' => 9])));
    Assert::false($replacement->check());
    Assert::null($replacement->id());
});

Runner::test('Falsches Passwort und unbekannter Benutzer werden abgelehnt', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 1,
        'username' => 'admin',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'active' => 1,
    ]);
    $auth = new Auth($store);

    Assert::false($auth->attempt('admin', 'falsch'));
    Assert::false($auth->attempt('unbekannt', 'sicheres-passwort'));
    Assert::false($auth->check());
});

Runner::test('Nach fünf Fehlversuchen wird die Anmeldung gesperrt', static function (): void {
    $_SESSION = [];
    $auth = new Auth(new FakeAdminUserStore());

    for ($i = 0; $i < Auth::MAX_ATTEMPTS; $i++) {
        $auth->attempt('admin', 'falsch');
    }

    Assert::true($auth->isLockedOut());
    Assert::true($auth->lockedForSeconds() > 0);
});

Runner::test('Abmelden entfernt die Sitzungsdaten', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 7,
        'username' => 'admin',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'active' => 1,
    ]);
    $auth = new Auth($store);
    $auth->attempt('admin', 'sicheres-passwort');
    $auth->logout();

    Assert::false($auth->check());
    Assert::null($auth->id());
});

Runner::test('Abgelaufene Sitzungen werden beendet', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 3,
        'username' => 'admin',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'active' => 1,
    ]);
    $auth = new Auth($store, 60);
    $auth->attempt('admin', 'sicheres-passwort');
    $_SESSION['_admin_last_activity'] = time() - 120;

    Assert::false($auth->check());
});

Runner::test('Ablauf der Admin-Anmeldung behält CSRF-Token (offene Orvanta-Seiten)', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 4,
        'username' => 'admin',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'active' => 1,
    ]);
    $auth = new Auth($store, 60);
    $auth->attempt('admin', 'sicheres-passwort');
    $token = Csrf::token();
    $_SESSION['_admin_last_activity'] = time() - 120;

    Assert::false($auth->check());
    Assert::null($auth->id());
    Assert::true(Csrf::isValid($token));
});

Runner::test('Explizites Abmelden rotiert das CSRF-Token', static function (): void {
    $_SESSION = [];
    $store = new FakeAdminUserStore([
        'id' => 5,
        'username' => 'admin',
        'password_hash' => password_hash('sicheres-passwort', PASSWORD_DEFAULT),
        'active' => 1,
    ]);
    $auth = new Auth($store);
    $auth->attempt('admin', 'sicheres-passwort');
    $token = Csrf::token();
    $auth->logout();

    Assert::false(Csrf::isValid($token));
});

Runner::test('Client-Adresse stammt aus dem Aufruf (X-Forwarded-For, sonst REMOTE_ADDR)', static function (): void {
    // mod_proxy haengt die Adresse des Clients an eine mitgeschickte Liste an;
    // nur der letzte Eintrag stammt vom Proxy selbst.
    $proxied = new Request('GET', '/office/orvanta', [], [], [
        'REMOTE_ADDR' => '172.18.0.9',
        'HTTP_X_FORWARDED_FOR' => '10.20.30.40, 172.18.0.5',
    ]);
    Assert::same('172.18.0.5', $proxied->clientIp(), 'Der Proxy ergaenzt die Adresse des Clients am Ende.');

    $ohneVorwerts = new Request('GET', '/office/orvanta', [], [], [
        'REMOTE_ADDR' => '172.18.0.9',
        'HTTP_X_FORWARDED_FOR' => '172.18.0.5',
    ]);
    Assert::same('172.18.0.5', $ohneVorwerts->clientIp(), 'Ohne eigene Werte steht der Client allein im Kopf.');

    $direct = new Request('GET', '/office/orvanta', [], [], ['REMOTE_ADDR' => '192.168.7.9']);
    Assert::same('192.168.7.9', $direct->clientIp(), 'Ohne Proxy gilt REMOTE_ADDR.');

    $unbrauchbar = new Request('GET', '/office/orvanta', [], [], [
        'REMOTE_ADDR' => '192.168.7.9',
        'HTTP_X_FORWARDED_FOR' => 'unbekannt',
    ]);
    Assert::same('192.168.7.9', $unbrauchbar->clientIp(), 'Unbrauchbare Werte fallen auf REMOTE_ADDR zurueck.');

    $ohne = new Request('GET', '/office/orvanta', [], [], ['REMOTE_ADDR' => '']);
    Assert::same('', $ohne->clientIp());
    Assert::same(['ip' => '', 'host' => ''], ClientAddress::from($ohne), 'Ohne Adresse bleibt auch der Name leer.');
});

Runner::test('Client-Hostname kommt nur zu einer gueltigen Adresse', static function (): void {
    Assert::same('', ClientAddress::hostname(''));
    Assert::same('', ClientAddress::hostname('unbekannt'));

    $lokal = ClientAddress::from(new Request('GET', '/', [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    Assert::same('127.0.0.1', $lokal['ip']);
    Assert::false($lokal['host'] === $lokal['ip'], 'Der Name ist nie die Adresse selbst.');
});
