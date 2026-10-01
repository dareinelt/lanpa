<?php

declare(strict_types=1);

use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\Office\OfficeTrustedDomainsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function officeTrustedDomains(object $probe, array $config = [], array $dynamic = []): OfficeTrustedDomainsService
{
    return new OfficeTrustedDomainsService(
        officeConfig(),
        $probe,
        $config + ['app_url' => 'https://intranet.firma.local:8443', 'extra_trusted_domains' => '', 'spn_hosts' => ''],
        static fn (): array => $dynamic
    );
}

Runner::test('Trusted Domains: Hostnamen werden normalisiert, dedupliziert und sortiert', static function (): void {
    Assert::same('intranet.firma.local', OfficeTrustedDomainsService::normalizeHost(' Intranet.Firma.Local:8443 '));
    Assert::same('intranet.firma.local', OfficeTrustedDomainsService::normalizeHost('https://intranet.firma.local:8443/office/'));
    Assert::same('*.firma.local', OfficeTrustedDomainsService::normalizeHost('*.firma.local'));
    Assert::same('10.0.0.5', OfficeTrustedDomainsService::normalizeHost('10.0.0.5:8080'));
    Assert::same('fd00::1', OfficeTrustedDomainsService::normalizeHost('[fd00::1]'));
    Assert::null(OfficeTrustedDomainsService::normalizeHost('localhost'), 'localhost ist in Nextcloud immer erlaubt.');
    Assert::null(OfficeTrustedDomainsService::normalizeHost('*'), 'Pauschales Wildcard darf nicht uebernommen werden.');
    Assert::null(OfficeTrustedDomainsService::normalizeHost('bad host'));
    Assert::null(OfficeTrustedDomainsService::normalizeHost('-bad.local'));
    Assert::null(OfficeTrustedDomainsService::normalizeHost(''));

    Assert::same('khwf.de', OfficeTrustedDomainsService::dnsDomainFromBaseDn('OU=Users,DC=khwf,DC=de'));
    Assert::same('ad.firma.local', OfficeTrustedDomainsService::dnsDomainFromBaseDn('dc=ad, dc=firma, dc=local'));
    Assert::same('', OfficeTrustedDomainsService::dnsDomainFromBaseDn('ou=nur,o=ohne-dc'));

    $service = officeTrustedDomains(new FakeOfficeProbe(), [
        'extra_trusted_domains' => 'intranet, INTRANET.firma.local;10.0.0.5',
        'spn_hosts' => 'portal.firma.local',
    ], ['lanpa-sso.firma.local', 'intranet.khwf.de', 'localhost', '', 'intranet.firma.local']);

    Assert::same(
        ['10.0.0.5', 'intranet', 'intranet.firma.local', 'intranet.khwf.de', 'lanpa-sso.firma.local', 'nextcloud', 'portal.firma.local'],
        $service->domains()
    );
    Assert::true($service->inSync(['nextcloud', 'portal.firma.local', 'intranet.khwf.de', 'lanpa-sso.firma.local', 'INTRANET.firma.local', 'intranet', '10.0.0.5']), 'Reihenfolge und Schreibweise duerfen abweichen.');
    Assert::false($service->inSync(['nextcloud', 'intranet.firma.local']));
    Assert::false($service->inSync(null));
});

Runner::test('Trusted Domains: Uebergabe an Nextcloud ist signiert und an den Inhalt gebunden', static function (): void {
    $probe = new FakeOfficeProbe();
    $probe->responses['POST http://nextcloud/office/index.php/apps/intranet_integration/api/hosts'] = [
        'status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'Hostnamen uebernommen.']),
    ];
    $service = officeTrustedDomains($probe, [], ['intranet.khwf.de']);

    $result = $service->pushToNextcloud();
    Assert::true($result['ok'], $result['message']);

    $request = $probe->requests[0];
    Assert::same('POST', $request['method']);
    $payload = json_decode((string) $request['body'], true);
    Assert::same(['intranet.firma.local', 'intranet.khwf.de', 'nextcloud'], $payload['domains']);

    $claims = OfficeJwt::decode(substr($request['headers']['Authorization'], 7), 'test-secret-0123456789');
    Assert::same(OfficeJwt::HOSTS_AUDIENCE, $claims['aud'] ?? null);
    Assert::same(hash('sha256', (string) $request['body']), $claims['body'] ?? null, 'Token muss an den Inhalt gebunden sein.');

    $probe->responses['POST http://nextcloud/office/index.php/apps/intranet_integration/api/hosts'] = ['status' => 404, 'error' => null, 'body' => ''];
    Assert::false($service->pushToNextcloud()['ok']);
    Assert::contains('veraltet', $service->pushToNextcloud()['message']);
});

Runner::test('Trusted Domains: Gesundheitspruefung ueberträgt bei Abweichung erneut', static function (): void {
    $secret = 'test-secret-0123456789';
    $diagnosticsUrl = 'GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics';
    $hostsUrl = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/hosts';

    $withHosts = static function (FakeOfficeProbe $probe, ?array $hosts) use ($diagnosticsUrl): FakeOfficeProbe {
        $body = json_decode($probe->responses[$diagnosticsUrl]['body'], true);
        unset($body['hosts']);
        if ($hosts !== null) {
            $body['hosts'] = ['trusted_domains' => $hosts];
        }
        $probe->responses[$diagnosticsUrl]['body'] = json_encode($body);

        return $probe;
    };
    $health = static fn (FakeOfficeProbe $probe, array $dynamic): OfficeHealthService => new OfficeHealthService(
        officeConfig(), $probe, officeTempDir() . '/health.json', 30, null, officeTrustedDomains($probe, [], $dynamic)
    );

    // Stand identisch: keine Uebertragung.
    $probe = $withHosts(FakeOfficeProbe::healthy($secret), ['nextcloud', 'intranet.firma.local', 'intranet.khwf.de']);
    $result = $health($probe, ['intranet.khwf.de'])->check();
    Assert::same(OfficeHealthService::OK, $result['components']['hosts']['status']);
    Assert::same('ok', $result['state']);
    Assert::true(empty($result['diagnostics']['hosts_pushed']));
    Assert::same([], array_filter($probe->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_ends_with($r['url'], '/api/hosts')));

    // Neuer Hostname nach Domaenenbeitritt: wird nachgetragen.
    $probe = $withHosts(FakeOfficeProbe::healthy($secret), ['nextcloud', 'intranet.firma.local']);
    $probe->responses[$hostsUrl] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'Hostnamen uebernommen.'])];
    $result = $health($probe, ['lanpa-sso.khwf.de'])->check();
    Assert::same(OfficeHealthService::OK, $result['components']['hosts']['status']);
    Assert::true(!empty($result['diagnostics']['hosts_pushed']));
    Assert::contains('lanpa-sso.khwf.de', $result['components']['hosts']['message']);
    Assert::same(['intranet.firma.local', 'lanpa-sso.khwf.de', 'nextcloud'], $result['diagnostics']['hosts_expected']);

    // Uebertragung schlaegt fehl: Office bleibt nutzbar, aber eingeschraenkt.
    $probe = $withHosts(FakeOfficeProbe::healthy($secret), ['nextcloud']);
    $result = $health($probe, [])->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['hosts']['status']);
    Assert::same('degraded', $result['state']);
    Assert::true($result['available']);

    // Veraltete Nextcloud-App ohne Hostnamen in der Diagnose.
    $probe = $withHosts(FakeOfficeProbe::healthy($secret), null);
    $result = $health($probe, [])->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['hosts']['status']);
    Assert::contains('veraltet', $result['components']['hosts']['message']);

    // Ohne Dienst keine Komponente (bestehendes Verhalten).
    $result = officeHealth(FakeOfficeProbe::healthy($secret))->check();
    Assert::false(isset($result['components']['hosts']));
});
