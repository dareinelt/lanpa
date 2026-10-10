<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\SourceNetworks;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('SourceNetworks: Einstellung lesen', function (): void {
    Assert::same([], SourceNetworks::fromSetting(''), 'Leer bedeutet keine bekannten Netze');
    Assert::same([], SourceNetworks::fromSetting('   '), 'Nur Leerzeichen');
    Assert::same([], SourceNetworks::fromSetting('none'), 'none bedeutet keine bekannten Netze');
    Assert::same(
        ['192.168.200.0/21', '10.0.0.0/8'],
        SourceNetworks::fromSetting("192.168.200.0/21\n10.0.0.0/8"),
        'Ein Netz je Zeile'
    );
    Assert::same(
        ['192.168.200.0/21'],
        SourceNetworks::fromSetting('192.168.200.0/21 192.168.200.0/21'),
        'Dubletten werden entfernt'
    );
    Assert::same(
        ['192.168.200.0/21'],
        SourceNetworks::fromSetting('192.168.204.5/21'),
        'Adresse wird auf die Netzgrenze gerundet'
    );
    Assert::same(['10.0.0.0/8'], SourceNetworks::fromSetting('10.0.0.0/8 kaputt'), 'Ungueltige Angaben werden verworfen');
});

Runner::test('SourceNetworks: Bereich eines Netzes errechnen', function (): void {
    Assert::same(
        [
            'network' => '192.168.200.0/21',
            'first' => '192.168.200.0',
            'last' => '192.168.207.255',
            'addresses' => 2048,
            'host_bits' => 11,
        ],
        SourceNetworks::describe('192.168.200.0/21'),
        'Ein /21 umfasst acht /24-Netze'
    );
    Assert::same(
        [
            'network' => '192.168.204.0/24',
            'first' => '192.168.204.0',
            'last' => '192.168.204.255',
            'addresses' => 256,
            'host_bits' => 8,
        ],
        SourceNetworks::describe('192.168.204.0/24'),
        'Ein /24 umfasst 256 Adressen'
    );
    Assert::same('10.0.0.0/24', SourceNetworks::describe('10.0.0.9/24')['network'] ?? null, 'Normalisierte Schreibweise');
    Assert::null(SourceNetworks::describe('kaputt'), 'Ungueltige Angabe');
});

Runner::test('SourceNetworks: enthaltene Netze werden zusammengefasst', function (): void {
    $known = SourceNetworks::fromSetting('192.168.200.0/21');

    Assert::same(
        ['192.168.200.0/21' => 12, '10.20.0.0/24' => 7],
        SourceNetworks::merge(['192.168.204.0/24' => 5, '192.168.206.0/24' => 7, '10.20.0.0/24' => 7], $known),
        'Beide /24-Netze des /21 werden summiert, fremde Netze bleiben einzeln'
    );
    Assert::same(
        ['192.168.200.0/21' => 3, 'lokal' => 4, 'weitere' => 1],
        SourceNetworks::merge(['192.168.200.0/24' => 3, 'lokal' => 4, 'weitere' => 1], $known),
        'lokal und weitere bleiben unveraendert'
    );
    Assert::same(
        ['192.168.200.0/21' => 2, '192.168.208.0/24' => 5],
        SourceNetworks::merge(['192.168.207.0/24' => 2, '192.168.208.0/24' => 5], $known),
        'Das Netz endet bei 192.168.207.255'
    );
    Assert::same(
        ['192.168.204.0/24' => 5],
        SourceNetworks::merge(['192.168.204.0/24' => 5], []),
        'Ohne bekannte Netze bleibt alles einzeln'
    );
    Assert::same(
        ['10.0.0.0/8' => 2],
        SourceNetworks::merge(['10.1.0.0/16' => 2], ['10.0.0.0/8']),
        'Ein weites Netz fasst auch groessere gemeldete Netze zusammen'
    );
});

Runner::test('SourceNetworks: genauestes Netz gewinnt', function (): void {
    Assert::same(
        '192.168.200.0/21',
        SourceNetworks::group('192.168.204.0/24', ['192.168.200.0/21', '192.168.0.0/16']),
        'Das laengste Praefix gewinnt'
    );
    Assert::same(
        '192.168.204.0/24',
        SourceNetworks::group('192.168.204.0/24', ['192.168.200.0/21', '192.168.204.0/24']),
        'Ein gleich grosses Netz passt ebenfalls'
    );
    Assert::null(SourceNetworks::group('10.20.0.0/24', ['192.168.200.0/21']), 'Fremdes Netz bleibt einzeln');
    Assert::null(SourceNetworks::group('lokal', ['192.168.200.0/21']), 'lokal ist kein Netz');
    Assert::null(SourceNetworks::group('weitere', ['0.0.0.0/0']), 'weitere ist kein Netz');
    Assert::null(
        SourceNetworks::group('2001:db8:1234::/64', ['192.168.200.0/21']),
        'IPv4-Netze enthalten keine IPv6-Netze'
    );
    Assert::same(
        '0.0.0.0/0',
        SourceNetworks::group('192.168.204.0/24', ['0.0.0.0/0']),
        'Ein Alles-Netz passt auf jedes IPv4-Netz'
    );
});

Runner::test('SourceNetworks: Adminseite zeigt Bereiche und Wirkung', function (): void {
    $empty = View::render('admin.source-networks', [
        'value' => '',
        'networks' => [],
        'errors' => [],
        'preview' => ['recorded_at' => null, 'rows' => [], 'merged' => []],
    ]);
    Assert::contains('Bekannte Quellnetze', $empty);
    Assert::contains('name="auth_known_source_networks"', $empty);
    Assert::contains('placeholder="192.168.200.0/21"', $empty);
    Assert::contains('Es sind keine bekannten Quellnetze eingetragen.', $empty);
    Assert::contains('Es liegen noch keine Messwerte des auth-Containers vor.', $empty);

    $html = View::render('admin.source-networks', [
        'value' => "192.168.200.0/21\n2001:db8::/32",
        'networks' => [SourceNetworks::describe('192.168.200.0/21'), SourceNetworks::describe('2001:db8::/32')],
        'errors' => ['auth_known_source_networks' => '„kaputt“ ist kein gültiges Netz (Beispiel: 192.168.200.0/21).'],
        'preview' => [
            'recorded_at' => '2026-10-01 10:00:00',
            'rows' => [
                ['network' => '192.168.204.0/24', 'count' => 5, 'target' => '192.168.200.0/21'],
                ['network' => '10.20.0.0/24', 'count' => 3, 'target' => null],
            ],
            'merged' => [
                ['network' => '192.168.200.0/21', 'count' => 5, 'share' => 62.5],
                ['network' => '10.20.0.0/24', 'count' => 3, 'share' => 37.5],
            ],
        ],
    ]);
    Assert::contains('192.168.200.0', $html);
    Assert::contains('192.168.207.255', $html);
    Assert::contains('>2.048<', $html);
    Assert::contains('>2^96<', $html);
    Assert::contains('aria-invalid="true"', $html);
    Assert::contains('„kaputt“ ist kein gültiges Netz', $html);
    Assert::contains('Probe vom 2026-10-01 10:00:00', $html);
    Assert::contains('– (bleibt einzeln)', $html);
    Assert::contains('>62,5 %<', $html);
});
