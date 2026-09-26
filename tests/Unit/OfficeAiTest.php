<?php

declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\Office\OfficeAiService;
use App\Services\Office\OfficeConfigService;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * @return array{ai:OfficeAiService,settings:SettingsService,office:OfficeConfigService,dir:string}
 */
function officeAi(object $probe, array $settings = [], array $config = []): array
{
    $settingsService = new SettingsService(new SettingsRepository(officePdo($settings)));
    $officeConfig = $config + [
        'enabled' => true,
        'jwt_secret' => 'test-secret-0123456789',
        'nextcloud_internal_url' => 'http://nextcloud/office/',
        'eurooffice_internal_url' => 'http://eurooffice/',
        'redis_host' => 'nextcloud-redis',
        'redis_port' => 6379,
        'postgres_host' => 'nextcloud-db',
        'postgres_port' => 5432,
        'timeout' => 2,
    ];
    $dir = officeTempDir() . '/office-ai';
    $office = new OfficeConfigService($settingsService, $officeConfig);

    return [
        'ai' => new OfficeAiService($settingsService, $office, $probe, $officeConfig + ['ai_config_dir' => $dir]),
        'settings' => $settingsService,
        'office' => $office,
        'dir' => $dir,
    ];
}

const OFFICE_AI_ACTIVE = [
    'office_ai_enabled' => '1',
    'office_ai_url' => 'http://ki-server:11434/v1',
    'office_ai_model' => 'llama3.1:8b',
];

Runner::test('Lokale KI: standardmaessig aktiviert, ohne Adresse aber inaktiv', static function (): void {
    Assert::same('1', OfficeAiService::defaults()['office_ai_enabled']);
    Assert::same('1', OfficeConfigService::defaults()['office_ai_enabled'] ?? null, 'KI-Defaults muessen in die Einstellungen einfliessen.');

    $ai = officeAi(new FakeOfficeProbe())['ai'];
    Assert::true($ai->enabled());
    Assert::false($ai->isActive(), 'Ohne Adresse/Modell darf nichts weitergereicht werden.');
    Assert::same([], $ai->euroOfficeRuntimeConfig());
    Assert::false($ai->nextcloudPayload()['enabled']);
});

Runner::test('Lokale KI: Validierung der Admin-Eingaben', static function (): void {
    $result = OfficeAiService::validate(['office_ai_enabled' => '1', 'office_ai_url' => '', 'office_ai_model' => '', 'office_ai_timeout' => '120']);
    Assert::true(isset($result['errors']['office_ai_url'], $result['errors']['office_ai_model']), 'Aktiv ohne Adresse/Modell ist unzulaessig.');

    $result = OfficeAiService::validate(['office_ai_url' => '', 'office_ai_model' => '', 'office_ai_timeout' => '120']);
    Assert::same([], $result['errors'], 'Deaktiviert darf leer sein.');
    Assert::same('0', $result['values']['office_ai_enabled']);
    Assert::false(array_key_exists('office_ai_api_key', $result['values']), 'Ohne Eingabe bleibt der Schluessel unveraendert.');

    foreach (['ftp://ki/v1', 'http://user:pw@ki/v1', 'http://ki/v1?x=1', 'http://ki/v1#a', 'javascript:alert(1)', 'http:///v1'] as $url) {
        Assert::false(OfficeAiService::isValidUrl($url), $url . ' muss abgelehnt werden.');
    }
    Assert::true(OfficeAiService::isValidUrl('https://ki.example.local:8443/v1'));

    $result = OfficeAiService::validate(['office_ai_url' => 'http://ki-server:11434/v1/', 'office_ai_timeout' => '5', 'office_ai_model' => 'bad model'] + OFFICE_AI_ACTIVE);
    Assert::true(isset($result['errors']['office_ai_timeout'], $result['errors']['office_ai_model']));
    Assert::false(isset($result['errors']['office_ai_url']));
    Assert::same('http://ki-server:11434/v1', $result['values']['office_ai_url'], 'Schraegstrich am Ende wird entfernt.');

    $result = OfficeAiService::validate(OFFICE_AI_ACTIVE + ['office_ai_timeout' => '60', 'office_ai_api_key' => 'sk-123']);
    Assert::same('sk-123', $result['values']['office_ai_api_key']);
    $result = OfficeAiService::validate(OFFICE_AI_ACTIVE + ['office_ai_timeout' => '60', 'office_ai_api_key' => 'x', 'office_ai_api_key_clear' => '1']);
    Assert::same('', $result['values']['office_ai_api_key'], 'Entfernen hat Vorrang.');
});

Runner::test('Lokale KI: Audio- und Bildfunktionen in Nextcloud nur nach Freigabe', static function (): void {
    Assert::same('0', OfficeAiService::defaults()['office_ai_audio']);
    Assert::same('0', OfficeAiService::defaults()['office_ai_images']);

    $result = OfficeAiService::validate(OFFICE_AI_ACTIVE + ['office_ai_timeout' => '60']);
    Assert::same('0', $result['values']['office_ai_audio']);
    Assert::same('0', $result['values']['office_ai_images']);
    $result = OfficeAiService::validate(OFFICE_AI_ACTIVE + ['office_ai_timeout' => '60', 'office_ai_audio' => '1', 'office_ai_images' => '1']);
    Assert::same('1', $result['values']['office_ai_audio']);
    Assert::same('1', $result['values']['office_ai_images']);

    $off = officeAi(new FakeOfficeProbe(), OFFICE_AI_ACTIVE)['ai'];
    $audio = officeAi(new FakeOfficeProbe(), ['office_ai_audio' => '1'] + OFFICE_AI_ACTIVE)['ai'];
    $payload = $audio->nextcloudPayload();
    Assert::true($payload['audio']);
    Assert::false($payload['images']);
    Assert::true($off->fingerprint() !== $audio->fingerprint(), 'Umschalten muss neu uebertragen werden.');
    Assert::same([], array_diff_key($audio->euroOfficeRuntimeConfig()['aiSettings'], $off->euroOfficeRuntimeConfig()['aiSettings']), 'Betrifft nur Nextcloud.');
});

Runner::test('Lokale KI: Euro-Office-Laufzeitkonfiguration (Servermodus des KI-Plugins)', static function (): void {
    $ai = officeAi(new FakeOfficeProbe(), OFFICE_AI_ACTIVE + ['office_ai_api_key' => 'sk-geheim', 'office_ai_timeout' => '90'])['ai'];
    $settings = $ai->euroOfficeRuntimeConfig()['aiSettings'] ?? null;
    Assert::true(is_array($settings));
    Assert::same('90s', $settings['timeout']);

    $provider = $settings['providers'][OfficeAiService::EO_PROVIDER] ?? null;
    Assert::same('http://ki-server:11434/v1', $provider['url'] ?? null);
    Assert::same('sk-geheim', $provider['key'] ?? null, 'Schluessel liegt nur serverseitig in runtime.json.');
    Assert::same('llama3.1:8b', $provider['models'][0]['id'] ?? null);

    foreach (['Chat', 'Summarization', 'Translation', 'TextAnalyze'] as $action) {
        Assert::same('llama3.1:8b', $settings['actions'][$action]['model'] ?? null, $action . ' muss belegt sein (Servermodus).');
    }
    Assert::same(OfficeAiService::EO_PROVIDER, $settings['models'][0]['provider'] ?? null);
});

Runner::test('Lokale KI: runtime.json wird atomar geschrieben und nur bei Aenderung ersetzt', static function (): void {
    $env = officeAi(new FakeOfficeProbe(), OFFICE_AI_ACTIVE);
    $file = $env['ai']->runtimeFile();

    $first = $env['ai']->writeEuroOfficeConfig();
    Assert::true($first['ok'] && $first['changed'], $first['message']);
    $data = json_decode((string) file_get_contents($file), true);
    Assert::same('llama3.1:8b', $data['aiSettings']['actions']['Chat']['model'] ?? null);
    Assert::same('0644', substr(sprintf('%o', fileperms($file)), -4), 'DocumentServer (anderer Benutzer) muss lesen koennen.');

    $second = $env['ai']->writeEuroOfficeConfig();
    Assert::true($second['ok'] && !$second['changed'], 'Unveraenderter Stand darf nicht neu geschrieben werden.');

    $disabled = officeAi(new FakeOfficeProbe(), ['office_ai_enabled' => '0'] + OFFICE_AI_ACTIVE, ['ai_config_dir' => dirname($file)]);
    $third = $disabled['ai']->writeEuroOfficeConfig();
    Assert::true($third['changed']);
    Assert::same("{}\n", (string) file_get_contents($file), 'Deaktiviert: keine KI-Vorgabe.');
    Assert::same([$file], glob(dirname($file) . '/{,.}*.json*', GLOB_BRACE) ?: [], 'Keine Temp-Dateien.');
});

Runner::test('Lokale KI: Nextcloud-Uebergabe ist signiert und an den Inhalt gebunden', static function (): void {
    $probe = new FakeOfficeProbe();
    $probe->responses['POST http://nextcloud/office/index.php/apps/intranet_integration/api/ai'] = ['status' => 200, 'error' => null,
        'body' => json_encode(['ok' => true, 'message' => 'KI für alle Nextcloud-Benutzer eingerichtet.'])];
    $ai = officeAi($probe, OFFICE_AI_ACTIVE + ['office_ai_api_key' => 'sk-db'])['ai'];

    $result = $ai->pushToNextcloud();
    Assert::true($result['ok'], $result['message']);

    $request = $probe->requests[0];
    $payload = json_decode((string) $request['body'], true);
    Assert::same(true, $payload['enabled']);
    Assert::same('sk-db', $payload['api_key']);
    Assert::same(false, $payload['audio'], 'Audio ist standardmaessig ausgeblendet.');
    Assert::same(false, $payload['images'], 'Bilder sind standardmaessig ausgeblendet.');
    Assert::same($ai->fingerprint(), $payload['fingerprint']);
    Assert::true(preg_match('/^[a-f0-9]{64}$/', $payload['fingerprint']) === 1);

    $token = substr($request['headers']['Authorization'], 7);
    $claims = OfficeJwt::decode($token, 'test-secret-0123456789');
    Assert::same(OfficeJwt::AI_AUDIENCE, $claims['aud'] ?? null);
    Assert::same(hash('sha256', (string) $request['body']), $claims['body'] ?? null);
    Assert::true(($claims['exp'] - $claims['iat']) <= 120);
});

Runner::test('Lokale KI: Secret hat Vorrang, Fingerabdruck aendert sich mit dem Stand', static function (): void {
    $fromDb = officeAi(new FakeOfficeProbe(), OFFICE_AI_ACTIVE + ['office_ai_api_key' => 'sk-db']);
    $fromSecret = officeAi(new FakeOfficeProbe(), OFFICE_AI_ACTIVE + ['office_ai_api_key' => 'sk-db'], ['ai_api_key' => 'sk-secret']);
    Assert::same('sk-secret', $fromSecret['ai']->apiKey());
    Assert::true($fromSecret['ai']->apiKeyFromSecret());
    Assert::false($fromDb['ai']->apiKeyFromSecret());
    Assert::false($fromDb['ai']->fingerprint() === $fromSecret['ai']->fingerprint());

    $otherModel = officeAi(new FakeOfficeProbe(), ['office_ai_model' => 'qwen2.5:7b', 'office_ai_api_key' => 'sk-db'] + OFFICE_AI_ACTIVE);
    Assert::false($fromDb['ai']->fingerprint() === $otherModel['ai']->fingerprint());
    Assert::false(in_array('office_ai_api_key', array_keys($fromDb['ai']->formValues()), true), 'Schluessel nie im Formular.');
});

Runner::test('Lokale KI: Endpunktpruefung erkennt fehlendes Modell und Zugriffsfehler', static function (): void {
    $probe = new FakeOfficeProbe();
    $ai = officeAi($probe, OFFICE_AI_ACTIVE + ['office_ai_api_key' => 'sk-1'])['ai'];

    $probe->responses['GET http://ki-server:11434/v1/models'] = ['status' => 200, 'error' => null,
        'body' => json_encode(['object' => 'list', 'data' => [['id' => 'llama3.1:8b'], ['id' => 'qwen2.5:7b']]])];
    Assert::true($ai->testEndpoint()['ok']);
    Assert::same('Bearer sk-1', $probe->requests[0]['headers']['Authorization'] ?? null);

    $probe->responses['GET http://ki-server:11434/v1/models']['body'] = json_encode(['data' => [['id' => 'qwen2.5:7b']]]);
    $result = $ai->testEndpoint();
    Assert::false($result['ok']);
    Assert::contains('qwen2.5:7b', $result['message'], 'Verfuegbare Modelle werden genannt.');

    $probe->responses['GET http://ki-server:11434/v1/models'] = ['status' => 401, 'body' => '', 'error' => null];
    Assert::contains('API-Schlüssel', $ai->testEndpoint()['message']);
});

Runner::test('Lokale KI: Statuspruefung gleicht ab, ohne den Office-Status zu beeinflussen', static function (): void {
    $probe = FakeOfficeProbe::healthy('test-secret-0123456789');
    $pushUrl = 'POST http://nextcloud/office/index.php/apps/intranet_integration/api/ai';
    $probe->responses[$pushUrl] = ['status' => 200, 'error' => null, 'body' => json_encode(['ok' => true, 'message' => 'ok'])];
    $env = officeAi($probe, OFFICE_AI_ACTIVE);
    $health = new OfficeHealthService($env['office'], $probe, officeTempDir() . '/health.json', 30, $env['ai']);

    $result = $health->check();
    Assert::same('ok', $result['state'], 'KI-Zeile ist nur informativ.');
    Assert::same('ok', $result['components']['ai']['status'] ?? null, (string) ($result['components']['ai']['message'] ?? ''));
    Assert::true(is_file($env['ai']->runtimeFile()), 'runtime.json wird selbstheilend geschrieben.');
    Assert::same(1, count(array_filter($probe->requests, static fn (array $r): bool => $r['method'] . ' ' . $r['url'] === $pushUrl)),
        'Ohne passenden Fingerabdruck in Nextcloud wird uebertragen.');

    // Nextcloud meldet den aktuellen Stand -> keine erneute Uebertragung.
    $diagKey = 'GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics';
    $diag = json_decode($probe->responses[$diagKey]['body'], true);
    $diag['ai'] = ['fingerprint' => $env['ai']->fingerprint(), 'error' => ''];
    $probe->responses[$diagKey]['body'] = json_encode($diag);
    $probe->requests = [];
    $health->check();
    Assert::same(0, count(array_filter($probe->requests, static fn (array $r): bool => $r['method'] . ' ' . $r['url'] === $pushUrl)));

    // Nicht eingerichtet: Warnung in der Zeile, Office bleibt "ok".
    $unset = officeAi($probe, ['office_ai_url' => ''] + OFFICE_AI_ACTIVE);
    $result = (new OfficeHealthService($unset['office'], $probe, officeTempDir() . '/health.json', 30, $unset['ai']))->check();
    Assert::same('warn', $result['components']['ai']['status']);
    Assert::same('ok', $result['state']);
});
