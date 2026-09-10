<?php

declare(strict_types=1);

use App\Support\Validator;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('URL-Validierung akzeptiert http(s) und interne Pfade', static function (): void {
    Assert::true(Validator::isSafeUrl('https://dms.example.internal'));
    Assert::true(Validator::isSafeUrl('http://intranet.example.internal/pfad?a=1'));
    Assert::true(Validator::isSafeUrl('/telefonliste'));
});

Runner::test('URL-Validierung weist gefährliche Eingaben ab', static function (): void {
    Assert::false(Validator::isSafeUrl('javascript:alert(1)'));
    Assert::false(Validator::isSafeUrl('data:text/html;base64,PHNjcmlwdD4='));
    Assert::false(Validator::isSafeUrl('//evil.example.com'));
    Assert::false(Validator::isSafeUrl('ftp://files.example.internal'));
    Assert::false(Validator::isSafeUrl(''));
    Assert::false(Validator::isSafeUrl("https://example.internal/\x00evil"));
    Assert::false(Validator::isSafeUrl("https://exa\nmple.internal/"));
});

Runner::test('Farbwerte werden geprüft und normalisiert', static function (): void {
    Assert::true(Validator::isHexColor('#abc'));
    Assert::true(Validator::isHexColor('#1F4E79'));
    Assert::false(Validator::isHexColor('rot'));
    Assert::false(Validator::isHexColor('#12345'));
    Assert::same('#aabbcc', Validator::normalizeHexColor('#ABC'));
    Assert::same('#1f4e79', Validator::normalizeHexColor('#1F4E79'));
    Assert::null(Validator::normalizeHexColor('#zzz'));
});

Runner::test('Telefonnummern werden auf Ziffern reduziert', static function (): void {
    Assert::same('4930123456', Validator::normalizePhone('+49 (30) 123-456'));
    Assert::same('', Validator::normalizePhone(null));
});

Runner::test('Notfallnummern erlauben Stern und Raute', static function (): void {
    Assert::true(Validator::isPhoneNumber('112'));
    Assert::true(Validator::isPhoneNumber('*112#'));
    Assert::true(Validator::isPhoneNumber('+49 (30) 123-456#'));
    Assert::false(Validator::isPhoneNumber('abc'));
});

Runner::test('Freitext wird bereinigt und begrenzt', static function (): void {
    Assert::same('Test', Validator::cleanText("  Test\x07  "));
    Assert::same('abcde', Validator::cleanText('abcdefghij', 5));
});

Runner::test('LDAP-Filter und Attribute werden validiert', static function (): void {
    Assert::true(Validator::isLdapFilter('(&(objectClass=user)(objectCategory=person))'));
    Assert::false(Validator::isLdapFilter('objectClass=user'));
    Assert::false(Validator::isLdapFilter('(&(objectClass=user)'));
    Assert::true(Validator::isLdapAttribute('telephoneNumber'));
    Assert::false(Validator::isLdapAttribute('tele phone'));
    Assert::false(Validator::isLdapAttribute('1attribut'));
});

Runner::test('Hostnamen und Ports werden validiert', static function (): void {
    Assert::true(Validator::isHostname('dc01.example.internal'));
    Assert::true(Validator::isHostname('192.0.2.10'));
    Assert::false(Validator::isHostname('kein hostname'));
    Assert::true(Validator::isPort(636));
    Assert::false(Validator::isPort(0));
    Assert::false(Validator::isPort(70000));
});

Runner::test('Aufzählungswerte werden geprüft', static function (): void {
    Assert::true(Validator::isNavigationType('internal'));
    Assert::false(Validator::isNavigationType('script'));
    Assert::true(Validator::isDescriptionMode('both'));
    Assert::false(Validator::isDescriptionMode('popup'));
});
