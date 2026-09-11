<?php

declare(strict_types=1);

use App\Services\LdapAttributeMapper;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * @return array<string,string>
 */
function testMapping(): array
{
    return [
        'display_name' => 'displayName',
        'first_name' => 'givenName',
        'last_name' => 'sn',
        'phone' => 'telephoneNumber',
        'mobile' => 'mobile',
        'email' => 'mail',
        'department' => 'department',
        'modified' => 'whenChanged',
        'unique_id' => 'objectGUID',
    ];
}

Runner::test('Nur gültige Attributnamen werden abgefragt', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping() + ['ungueltig' => 'kein attribut']);
    $attributes = $mapper->attributes();

    Assert::true(in_array('displayName', $attributes, true));
    Assert::false(in_array('kein attribut', $attributes, true));
    Assert::same(count($attributes), count(array_unique($attributes)));
});

Runner::test('AD-Datensatz wird auf interne Felder abgebildet', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $entry = [
        'dn' => 'CN=Muster,OU=Benutzer,DC=example,DC=internal',
        'displayname' => ['count' => 1, 0 => 'Erika Muster'],
        'givenname' => ['count' => 1, 0 => 'Erika'],
        'sn' => ['count' => 1, 0 => 'Muster'],
        'telephonenumber' => ['count' => 1, 0 => '+49 30 123-456'],
        'mail' => ['count' => 1, 0 => 'erika.muster@example.internal'],
        'department' => ['count' => 1, 0 => 'Verwaltung'],
        'whenchanged' => ['count' => 1, 0 => '20260310120000.0Z'],
        'objectguid' => ['count' => 1, 0 => 'abc-123'],
    ];

    $result = $mapper->map($entry);

    Assert::same('abc-123', $result['external_id']);
    Assert::same('Erika Muster', $result['display_name']);
    Assert::same('Verwaltung', $result['department']);
    Assert::same('2026-03-10 12:00:00', $result['ad_modified']);
    Assert::same('erika.muster@example.internal', $result['email']);
    Assert::null($result['mobile']);
});

Runner::test('Fehlender Anzeigename wird aus Vor- und Nachname gebildet', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $result = $mapper->map(['objectguid' => ['x1'], 'givenname' => ['Max'], 'sn' => ['Beispiel']]);

    Assert::same('Max Beispiel', $result['display_name']);
});

Runner::test('Datensätze ohne Namen werden verworfen', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());

    Assert::null($mapper->map(['objectguid' => ['x1']]));
});

Runner::test('Ohne eindeutige Kennung wird der DN als Schlüssel genutzt', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $result = $mapper->map(['displayname' => ['Erika Muster']], 'CN=Erika,DC=example,DC=internal');

    Assert::true(str_starts_with((string) $result['external_id'], 'dn:'));
});

Runner::test('Binäre objectGUID wird hexadezimal gespeichert', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $binary = "\x01\x02\x03\x04";
    $result = $mapper->map(['objectguid' => [$binary], 'displayname' => ['Erika Muster']]);

    Assert::same('01020304', $result['external_id']);
});

Runner::test('Ungültige E-Mail-Adressen werden verworfen', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $result = $mapper->map(['objectguid' => ['x1'], 'displayname' => ['Erika Muster'], 'mail' => ['keine-mail']]);

    Assert::null($result['email']);
});

Runner::test('Im AD deaktivierte Nutzer werden nicht importiert', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $result = $mapper->map([
        'objectguid' => ['x1'],
        'displayname' => ['Erika Muster'],
        'useraccountcontrol' => ['514'],
    ]);

    Assert::null($result);
});

Runner::test('Aktive AD-Nutzer werden trotz gesetztem userAccountControl importiert', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());
    $result = $mapper->map([
        'objectguid' => ['x1'],
        'displayname' => ['Erika Muster'],
        'useraccountcontrol' => ['512'],
    ]);

    Assert::same('Erika Muster', $result['display_name']);
});

Runner::test('userAccountControl wird stets mitabgefragt', static function (): void {
    $mapper = new LdapAttributeMapper(testMapping());

    Assert::true(in_array('userAccountControl', $mapper->attributes(), true));
});

Runner::test('AD-Zeitstempel werden umgewandelt', static function (): void {
    Assert::same('2026-09-02 12:00:00', LdapAttributeMapper::parseAdTimestamp('20260902120000.0Z'));
    Assert::null(LdapAttributeMapper::parseAdTimestamp(''));
    Assert::null(LdapAttributeMapper::parseAdTimestamp('kein datum'));
});
