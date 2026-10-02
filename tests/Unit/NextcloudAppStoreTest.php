<?php

declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\Office\NextcloudAppStoreService;
use App\Services\Office\OfficeConfigService;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function nextcloudAppStore(object $probe, array $settings = []): NextcloudAppStoreService
{
    return new NextcloudAppStoreService(
        new SettingsService(new SettingsRepository(officePdo($settings))),
        officeConfig(),
        $probe
    );
}

Runner::test('App-Store: standardmaessig sichtbar, im Adminbereich abschaltbar', static function (): void {
    Assert::same('1', NextcloudAppStoreService::defaults()[NextcloudAppStoreService::SETTING]);
    Assert::same('1', OfficeConfigService::defaults()[NextcloudAppStoreService::SETTING] ?? null, 'Default muss in die Einstellungen einfliessen.');

    Assert::true(nextcloudAppStore(new FakeOfficeProbe())->enabled());
    Assert::false(nextcloudAppStore(new FakeOfficeProbe(), [NextcloudAppStoreService::SETTING => '0'])->enabled());

    Assert::same('0', NextcloudAppStoreService::validate([])['values'][NextcloudAppStoreService::SETTING], 'Nicht angehakt = ausgeblendet.');
    Assert::same('1', NextcloudAppStoreService::validate([NextcloudAppStoreService::SETTING => '1'])['values'][NextcloudAppStoreService::SETTING]);

    $off = nextcloudAppStore(new FakeOfficeProbe(), [NextcloudAppStoreService::SETTING => '0']);
    Assert::true($off->inSync(['enabled' => false]));
    Assert::false($off->inSync(['enabled' => true]));
    Assert::false($off->inSync(['enabled' => 'false']), 'Nur echte Wahrheitswerte gelten.');
    Assert::false($off->inSync(null));
});

Runner::test('App-Store: Uebergabe an Nextcloud ist signiert und an den Inhalt gebunden', static function (): void {
    $url = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/appstore';
    $probe = new FakeOfficeProbe();
    $probe->responses[$url] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'App-Store ausgeblendet.'])];
    $service = nextcloudAppStore($probe, [NextcloudAppStoreService::SETTING => '0']);

    $result = $service->pushToNextcloud();
    Assert::true($result['ok'], $result['message']);

    $request = $probe->requests[0];
    Assert::same(['version' => 1, 'enabled' => false], json_decode((string) $request['body'], true));
    $claims = OfficeJwt::decode(substr($request['headers']['Authorization'], 7), 'test-secret-0123456789');
    Assert::same(OfficeJwt::APPSTORE_AUDIENCE, $claims['aud'] ?? null);
    Assert::same(hash('sha256', (string) $request['body']), $claims['body'] ?? null, 'Token muss an den Inhalt gebunden sein.');

    $probe->responses[$url] = ['status' => 401, 'error' => null, 'body' => ''];
    Assert::contains('JWT-Secret', $service->pushToNextcloud()['message']);
    $probe->responses[$url] = ['status' => 404, 'error' => null, 'body' => ''];
    Assert::contains('veraltet', $service->pushToNextcloud()['message']);
});

Runner::test('App-Store: Gesundheitspruefung ueberträgt bei Abweichung erneut (nur informativ)', static function (): void {
    $secret = 'test-secret-0123456789';
    $diagnosticsUrl = 'GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics';
    $appStoreUrl = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/appstore';

    $probeWith = static function (?bool $enabled) use ($secret, $diagnosticsUrl): FakeOfficeProbe {
        $probe = FakeOfficeProbe::healthy($secret);
        $body = json_decode($probe->responses[$diagnosticsUrl]['body'], true);
        if ($enabled !== null) {
            $body['appstore'] = ['enabled' => $enabled];
        }
        $probe->responses[$diagnosticsUrl]['body'] = json_encode($body);

        return $probe;
    };
    $health = static fn (FakeOfficeProbe $probe, array $settings): OfficeHealthService => new OfficeHealthService(
        officeConfig(), $probe, officeTempDir() . '/health.json', 30, null, null, null, null, null, nextcloudAppStore($probe, $settings)
    );
    $hidden = [NextcloudAppStoreService::SETTING => '0'];

    // Stand identisch: keine Uebertragung.
    $probe = $probeWith(false);
    $result = $health($probe, $hidden)->check();
    Assert::same(OfficeHealthService::OK, $result['components']['appstore']['status']);
    Assert::true(empty($result['diagnostics']['appstore_pushed']));
    Assert::same([], array_filter($probe->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_ends_with($r['url'], '/api/appstore')));

    // Abweichung (z. B. von Hand wieder eingeschaltet): wird neu uebertragen.
    $probe = $probeWith(true);
    $probe->responses[$appStoreUrl] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'App-Store ausgeblendet.'])];
    $result = $health($probe, $hidden)->check();
    Assert::same(OfficeHealthService::OK, $result['components']['appstore']['status']);
    Assert::true(!empty($result['diagnostics']['appstore_pushed']));
    Assert::contains('Ausgeblendet', $result['components']['appstore']['message']);

    // Uebertragung schlaegt fehl: Warnung, Office bleibt verfuegbar.
    $probe = $probeWith(true);
    $result = $health($probe, $hidden)->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['appstore']['status']);
    Assert::same('ok', $result['state'], 'Nur informativ.');

    // Veraltete Nextcloud-App ohne App-Store-Stand in der Diagnose.
    $result = $health($probeWith(null), [])->check();
    Assert::same(OfficeHealthService::WARN, $result['components']['appstore']['status']);
    Assert::contains('veraltet', $result['components']['appstore']['message']);
});
