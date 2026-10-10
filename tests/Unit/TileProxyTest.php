<?php

declare(strict_types=1);

use App\Support\TileProxy;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Entscheidung, ob eine externe Navigationskachel ueber den Reverse-Proxy des
 * auth-Containers laeuft (app/Support/TileProxy.php). Der Aufbau des
 * Adressraums muss mit docker/auth/weiterleitung-sync.sh uebereinstimmen.
 *
 * @param array<string,mixed> $overrides
 *
 * @return array<string,mixed>
 */
function tileProxyItem(array $overrides = []): array
{
    return array_merge([
        'id' => 12,
        'type' => 'external',
        'url' => 'https://ziel.example.com/portal/',
        'proxy_enabled' => 1,
        'proxy_bypass_networks' => '',
    ], $overrides);
}

Runner::test('TileProxy: nur proxyfaehige Ziele werden gespiegelt', function (): void {
    Assert::true(TileProxy::isProxyableUrl('https://ziel.example.com/portal/'));
    Assert::true(TileProxy::isProxyableUrl('http://10.0.0.5:8080/app/index.php'));
    Assert::true(TileProxy::isProxyableUrl('https://ziel.example.com'));
    Assert::true(TileProxy::isProxyableUrl(' https://ziel.example.com/a%20b '));
    Assert::true(TileProxy::isProxyableUrl('https://ziel.example.com/app?x=1'));
    Assert::true(TileProxy::isProxyableUrl('https://ziel.example.com/app#top'));
    Assert::false(TileProxy::isProxyableUrl('https://ziel.example.com/app?x=1 y'));
    Assert::false(TileProxy::isProxyableUrl('file:///etc/passwd'));
    Assert::false(TileProxy::isProxyableUrl('//ziel.example.com/app'));
    Assert::false(TileProxy::isProxyableUrl('javascript:alert(1)'));
    Assert::false(TileProxy::isProxyableUrl('https://ziel.example.com/a b'));
    Assert::false(TileProxy::isProxyableUrl(''));
    Assert::same('/weiterleitung/12/', TileProxy::path(12));
});

Runner::test('TileProxy: Adresse enthaelt den Zielpfad der Anwendung', function (): void {
    // Zielpfad bleibt unveraendert; Verzeichnisse bekommen einen Schraegstrich.
    Assert::same('/weiterleitung/12/portal/', TileProxy::url(12, 'https://ziel.example.com/portal'));
    Assert::same('/weiterleitung/12/portal/', TileProxy::url(12, 'https://ziel.example.com/portal/'));
    Assert::same('/weiterleitung/12/', TileProxy::url(12, 'https://ziel.example.com/'));
    Assert::same('/weiterleitung/12/', TileProxy::url(12, 'https://ziel.example.com'));
    // Datei im letzten Segment: kein zusaetzlicher Schraegstrich.
    Assert::same('/weiterleitung/12/app/index.php', TileProxy::url(12, 'http://10.0.0.5:8080/app/index.php'));
    // Abfrage und Fragment bleiben erhalten.
    Assert::same('/weiterleitung/12/portal/?x=1', TileProxy::url(12, 'https://ziel.example.com/portal?x=1'));
    Assert::same('/weiterleitung/12/portal/#top', TileProxy::url(12, 'https://ziel.example.com/portal#top'));
});

Runner::test('TileProxy: Weiterleitung nur bei externen Kacheln mit Haken', function (): void {
    Assert::true(TileProxy::applies(tileProxyItem()));
    Assert::false(TileProxy::applies(tileProxyItem(['proxy_enabled' => 0])));
    Assert::false(TileProxy::applies(tileProxyItem(['type' => 'internal'])));
    Assert::false(TileProxy::applies([]));
});

Runner::test('TileProxy: Ziel ist der Weiterleitungspfad, bei Ausnahmenetz die Original-URL', function (): void {
    Assert::same('/weiterleitung/12/portal/', TileProxy::target(tileProxyItem(), '203.0.113.9'));
    // Ohne Haken bzw. bei nicht proxyfaehigem Ziel: klassischer Aufruf.
    Assert::null(TileProxy::target(tileProxyItem(['proxy_enabled' => 0]), '203.0.113.9'));
    Assert::null(TileProxy::target(tileProxyItem(['url' => 'javascript:alert(1)']), '203.0.113.9'));
    Assert::null(TileProxy::target(tileProxyItem(['id' => 0]), '203.0.113.9'));
});

Runner::test('TileProxy: Ausnahmenetze (CIDR) rufen das Ziel direkt auf', function (): void {
    $item = tileProxyItem(['proxy_bypass_networks' => '10.0.0.0/24, 192.168.0.0/16']);

    Assert::same(['10.0.0.0/24', '192.168.0.0/16'], TileProxy::bypassNetworks($item));
    Assert::null(TileProxy::target($item, '10.0.0.5'));
    Assert::null(TileProxy::target($item, '192.168.9.9'));
    Assert::same('/weiterleitung/12/portal/', TileProxy::target($item, '203.0.113.5'));
    // Unbekannte Adresse: weiterleiten, damit das Ziel ueberhaupt erreichbar ist.
    Assert::same('/weiterleitung/12/portal/', TileProxy::target($item, null));
    Assert::same('/weiterleitung/12/portal/', TileProxy::target($item, ''));
});

Runner::test('TileProxy: decorate ergaenzt proxy_url je Kachel', function (): void {
    $items = TileProxy::decorate([
        tileProxyItem(['id' => 1, 'proxy_bypass_networks' => '10.0.0.0/8']),
        tileProxyItem(['id' => 2]),
        tileProxyItem(['id' => 3, 'type' => 'internal']),
    ], '10.1.2.3');

    Assert::null($items[0]['proxy_url']);
    Assert::same('/weiterleitung/2/portal/', $items[1]['proxy_url']);
    Assert::null($items[2]['proxy_url']);
});
