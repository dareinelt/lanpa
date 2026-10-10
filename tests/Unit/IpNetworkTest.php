<?php

declare(strict_types=1);

use App\Support\IpNetwork;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('IpNetwork: Liste zerlegen, normalisieren und Dubletten entfernen', function (): void {
    Assert::same(
        ['10.0.0.0/24', '192.168.1.5/32', '2001:db8::/32'],
        IpNetwork::parseList("10.0.0.5/24 192.168.1.5,\n10.0.0.0/24;2001:db8:0:0::1/32")
    );
    Assert::same([], IpNetwork::parseList('   '));
    Assert::same(['10.0.0.0/8'], IpNetwork::parseList('10.1.2.3/8'));
});

Runner::test('IpNetwork: ungueltige Angaben werden verworfen bzw. gemeldet', function (): void {
    Assert::same(['10.0.0.0/24'], IpNetwork::parseList('10.0.0.0/24 kaputt 300.1.1.1 10.0.0.0/33'));
    Assert::same(
        ['networks' => [], 'invalid' => ['kaputt', '10.0.0.0/33']],
        IpNetwork::parseListDetailed('kaputt 10.0.0.0/33')
    );
    Assert::null(IpNetwork::normalize(''));
    Assert::null(IpNetwork::normalize('1.2.3.4/-1'));
    Assert::null(IpNetwork::normalize('1.2.3.4/x'));
    Assert::null(IpNetwork::normalize('999.0.0.1/24'));
});

Runner::test('IpNetwork: Adressen liegen im Netz (IPv4 und IPv6)', function (): void {
    Assert::true(IpNetwork::contains('10.0.0.0/24', '10.0.0.255'));
    Assert::false(IpNetwork::contains('10.0.0.0/24', '10.0.1.0'));
    // Einzeladresse ohne Praefix.
    Assert::true(IpNetwork::contains('10.0.0.7', '10.0.0.7'));
    Assert::false(IpNetwork::contains('10.0.0.7', '10.0.0.8'));
    Assert::true(IpNetwork::contains('2001:db8::/32', '2001:db8:1234::9'));
    Assert::false(IpNetwork::contains('2001:db8::/32', '2001:db9::1'));
    // Familien werden nie vermischt.
    Assert::false(IpNetwork::contains('0.0.0.0/0', '2001:db8::1'));
    Assert::false(IpNetwork::contains('::/0', '10.0.0.1'));
    // Alles-Netz.
    Assert::true(IpNetwork::contains('0.0.0.0/0', '10.0.0.1'));
});

Runner::test('IpNetwork: matchesAny und unbekannte Adressen', function (): void {
    Assert::false(IpNetwork::matchesAny([], '10.0.0.1'));
    Assert::true(IpNetwork::matchesAny(['10.0.0.0/24', '192.168.0.0/16'], '192.168.5.5'));
    Assert::false(IpNetwork::matchesAny(['10.0.0.0/24'], '192.168.5.5'));
    Assert::false(IpNetwork::matchesAny(['10.0.0.0/24'], ''));
    Assert::false(IpNetwork::matchesAny(['10.0.0.0/24'], 'keine-adresse'));
});
