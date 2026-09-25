<?php

declare(strict_types=1);

use App\Contracts\OfficeProbeInterface;
use App\Repositories\SettingsRepository;
use App\Services\Office\OfficeBackupService;
use App\Services\Office\OfficeConfigService;
use App\Services\Office\OfficeHealthService;
use App\Services\Office\OfficeJwt;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

final class FakeOfficeProbe implements OfficeProbeInterface
{
    /** @var array<string,array{status:int,body:string,error:?string}> */
    public array $responses = [];

    /** @var array<string,?string> */
    public array $tcpReplies = [];

    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 4): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $key = $method . ' ' . strtok($url, '?');

        return $this->responses[$key] ?? ['status' => 0, 'body' => '', 'error' => 'Connection refused'];
    }

    public function tcp(string $host, int $port, string $payload = '', int $timeout = 3, int $readBytes = 64): ?string
    {
        return $this->tcpReplies[$host . ':' . $port] ?? null;
    }

    public static function healthy(string $secret): self
    {
        $probe = new self();
        $probe->responses['GET http://nextcloud/office/status.php'] = ['status' => 200, 'error' => null,
            'body' => json_encode(['installed' => true, 'maintenance' => false, 'needsDbUpgrade' => false, 'versionstring' => '34.0.4'])];
        $probe->responses['GET http://eurooffice/healthcheck'] = ['status' => 200, 'body' => 'true', 'error' => null];
        $probe->responses['GET http://eurooffice/web-apps/apps/api/documents/api.js'] = ['status' => 200, 'body' => '/* api */', 'error' => null];
        $probe->responses['POST http://eurooffice/command'] = ['status' => 200, 'body' => '{"error":0,"version":"9.3.4.37"}', 'error' => null];
        $probe->responses['GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics'] = ['status' => 200, 'error' => null,
            'body' => json_encode([
                'ok' => true,
                'connector' => ['installed' => true, 'enabled' => true, 'version' => '11.0.5', 'jwt_configured' => true, 'app_config_overrides' => []],
                'apps' => ['user_ldap' => true, 'user_saml' => false],
                'check' => ['ok' => true, 'error' => '', 'version' => '9.3.4.37'],
            ])];
        $probe->tcpReplies['nextcloud-redis:6379'] = "-NOAUTH Authentication required.\r\n";
        $probe->tcpReplies['nextcloud-db:5432'] = 'N';

        return $probe;
    }
}

function officePdo(array $settings = []): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key VARCHAR(64) NOT NULL UNIQUE,
        setting_value TEXT NULL,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $insert = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($settings as $key => $value) {
        $insert->execute([$key, $value]);
    }

    return $pdo;
}

function officeConfig(array $settings = [], array $config = []): OfficeConfigService
{
    return new OfficeConfigService(
        new SettingsService(new SettingsRepository(officePdo($settings))),
        $config + [
            'enabled' => true,
            'jwt_secret' => 'test-secret-0123456789',
            'jwt_header' => 'AuthorizationJwt',
            'public_path' => '/office/',
            'eurooffice_public_path' => '/eurooffice/',
            'nextcloud_internal_url' => 'http://nextcloud/office/',
            'eurooffice_internal_url' => 'http://eurooffice/',
            'redis_host' => 'nextcloud-redis',
            'redis_port' => 6379,
            'postgres_host' => 'nextcloud-db',
            'postgres_port' => 5432,
            'timeout' => 2,
            'entry_lifetime' => 3600,
        ]
    );
}

function officeTempDir(): string
{
    $dir = sys_get_temp_dir() . '/office-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);

    return $dir;
}

function officeHealth(OfficeProbeInterface $probe, ?OfficeConfigService $config = null, ?string $cache = null): OfficeHealthService
{
    return new OfficeHealthService($config ?? officeConfig(), $probe, $cache ?? officeTempDir() . '/health.json', 30);
}

Runner::test('Office-JWT: HS256 wird erzeugt und verifiziert', static function (): void {
    $token = OfficeJwt::encode(['c' => 'version'], 'geheim');
    Assert::same(['c' => 'version'], OfficeJwt::decode($token, 'geheim'));
    Assert::null(OfficeJwt::decode($token, 'falsch'), 'Falsches Secret muss abgelehnt werden.');
    Assert::null(OfficeJwt::decode($token . 'x', 'geheim'), 'Manipulierte Signatur muss abgelehnt werden.');

    $diag = OfficeJwt::decode(OfficeJwt::diagnosticsToken('geheim', time()), 'geheim');
    Assert::same('intranet_integration', $diag['aud'] ?? null);
    Assert::true(($diag['exp'] - $diag['iat']) <= 120, 'Diagnose-Token muss kurzlebig sein.');
    Assert::null(OfficeJwt::decode(OfficeJwt::diagnosticsToken('geheim', time() - 3600), 'geheim'), 'Abgelaufenes Token muss abgelehnt werden.');
});

Runner::test('Office-Einstieg: nur Ziele unterhalb von /office/ (kein offener Redirect)', static function (): void {
    $config = officeConfig();
    Assert::same('/office/', $config->entryTarget(null));
    Assert::same('/office/apps/files/?dir=/Projekte', $config->entryTarget('/office/apps/files/?dir=/Projekte'));
    Assert::same('/office/', $config->entryTarget('https://evil.example/office/'));
    Assert::same('/office/', $config->entryTarget('//evil.example/office/'));
    Assert::same('/office/', $config->entryTarget('/office/../admin'));
    Assert::same('/office/', $config->entryTarget('/office/%2e%2e/admin'));
    Assert::same('/office/', $config->entryTarget("/office/\r\nSet-Cookie: x"));
    Assert::same('/office/', $config->entryTarget('/officex/'));
    Assert::same('/office/', $config->entryTarget('/admin'));
});

Runner::test('Office-Einstellungen: Validierung', static function (): void {
    $ok = OfficeConfigService::validate([
        'office_footer_enabled' => '1',
        'office_footer_text' => "  Intranet <b>Stadt</b>  ",
        'office_footer_transparency' => '30',
        'office_footer_home_url' => '/',
        'office_direct_access' => 'redirect',
    ]);
    Assert::same([], $ok['errors']);
    Assert::same('1', $ok['values']['office_footer_enabled']);
    Assert::same('0', $ok['values']['office_footer_show_back']);
    Assert::same('redirect', $ok['values']['office_direct_access']);

    $bad = OfficeConfigService::validate([
        'office_footer_transparency' => '95',
        'office_footer_home_url' => 'javascript:alert(1)',
        'office_direct_access' => 'egal',
    ]);
    Assert::true(isset($bad['errors']['office_footer_transparency']));
    Assert::true(isset($bad['errors']['office_footer_home_url']));
    Assert::true(isset($bad['errors']['office_direct_access']));

    Assert::true(OfficeConfigService::isSafeHomeUrl('https://intranet.example.internal/'));
    Assert::false(OfficeConfigService::isSafeHomeUrl('//evil.example'));
    Assert::false(OfficeConfigService::isSafeHomeUrl('/\\evil.example'));
});

Runner::test('Office-Fusszeile: Konfiguration fuer Nextcloud', static function (): void {
    $config = officeConfig([
        'site_title' => 'Stadtverwaltung',
        'office_footer_transparency' => '25',
        'office_footer_show_back' => '0',
    ]);
    $payload = $config->footerPayload(['color_primary' => '#123456'], true, '42');

    Assert::true($payload['enabled']);
    Assert::same('Stadtverwaltung', $payload['text'], 'Leerer Fusszeilentext faellt auf den Seitentitel zurueck.');
    Assert::same(25, $payload['transparency']);
    Assert::false($payload['show_back']);
    Assert::same('/logo', $payload['logo_url']);
    Assert::same('#123456', $payload['colors']['primary']);
    Assert::same('/assets/css/office-footer.css?v=42', $payload['assets']['css']);
    Assert::same('/assets/js/office-footer.js?v=42', $payload['assets']['js']);
    Assert::false(str_contains(json_encode($payload), 'test-secret'), 'Secrets duerfen nie ausgeliefert werden.');

    $disabled = officeConfig([], ['enabled' => false])->footerPayload([], false, '1');
    Assert::false($disabled['enabled']);
    Assert::same('', $disabled['logo_url']);
});

Runner::test('Office-Health: alle Komponenten in Ordnung', static function (): void {
    $probe = FakeOfficeProbe::healthy('test-secret-0123456789');
    $result = officeHealth($probe)->check(true);

    Assert::same('ok', $result['state']);
    Assert::true($result['available']);
    foreach ($result['components'] as $name => $component) {
        Assert::same('ok', $component['status'], 'Komponente ' . $name . ': ' . $component['message']);
    }
    Assert::same('9.3.4.37', $result['diagnostics']['eurooffice_version']);

    // Der Versionsbefehl ist mit dem gemeinsamen Secret signiert.
    $command = null;
    $diagnostics = null;
    foreach ($probe->requests as $request) {
        if (str_ends_with($request['url'], '/command')) {
            $command = $request;
        }
        if (str_contains($request['url'], '/api/diagnostics')) {
            $diagnostics = $request;
        }
    }
    Assert::true($command !== null);
    $body = json_decode((string) $command['body'], true);
    Assert::same(['c' => 'version'], OfficeJwt::decode((string) $body['token'], 'test-secret-0123456789'));
    Assert::true(str_starts_with($command['headers']['AuthorizationJwt'] ?? '', 'Bearer '));
    Assert::true(str_ends_with($diagnostics['url'], '?check=1'), 'Vollstaendige Pruefung fordert die Connector-Pruefung an.');
    $token = substr((string) ($diagnostics['headers']['Authorization'] ?? ''), 7);
    Assert::same('intranet_integration', OfficeJwt::decode($token, 'test-secret-0123456789')['aud'] ?? null);
});

Runner::test('Office-Health: DocumentServer ausgefallen -> nicht verfuegbar', static function (): void {
    $probe = FakeOfficeProbe::healthy('x');
    unset($probe->responses['GET http://eurooffice/healthcheck'], $probe->responses['POST http://eurooffice/command']);
    $result = officeHealth($probe)->check();

    Assert::same('down', $result['state']);
    Assert::false($result['available']);
    Assert::same('error', $result['components']['eurooffice']['status']);
});

Runner::test('Office-Health: falsches JWT-Secret wird erkannt', static function (): void {
    $probe = FakeOfficeProbe::healthy('x');
    $probe->responses['POST http://eurooffice/command'] = ['status' => 200, 'body' => '{"error":6}', 'error' => null];
    $probe->responses['GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics'] = ['status' => 401, 'body' => '{"ok":false}', 'error' => null];
    $result = officeHealth($probe)->check();

    Assert::same('error', $result['components']['eurooffice_jwt']['status']);
    Assert::contains('Secret', $result['components']['eurooffice_jwt']['message']);
    Assert::same('error', $result['components']['connector']['status']);
    Assert::same('down', $result['state']);
});

Runner::test('Office-Health: Wartungsmodus und App-Overrides ergeben Warnungen', static function (): void {
    $probe = FakeOfficeProbe::healthy('x');
    $probe->responses['GET http://nextcloud/office/status.php']['body'] = json_encode(['installed' => true, 'maintenance' => true, 'versionstring' => '34.0.4']);
    $probe->responses['GET http://nextcloud/office/index.php/apps/intranet_integration/api/diagnostics']['body'] = json_encode([
        'ok' => true,
        'connector' => ['installed' => true, 'enabled' => true, 'version' => '11.0.5', 'jwt_configured' => true, 'app_config_overrides' => ['DocumentServerUrl']],
    ]);
    $result = officeHealth($probe)->check();

    Assert::same('warn', $result['components']['nextcloud']['status']);
    Assert::same('warn', $result['components']['connector']['status']);
    Assert::contains('DocumentServerUrl', $result['components']['connector']['message']);
    Assert::same('degraded', $result['state']);
    Assert::true($result['available']);
});

Runner::test('Office-Health: ohne Aktivierung keine Netzwerkzugriffe, Ergebnis wird zwischengespeichert', static function (): void {
    $probe = FakeOfficeProbe::healthy('x');
    $disabled = officeHealth($probe, officeConfig([], ['enabled' => false]));
    Assert::same('disabled', $disabled->publicSummary()['state']);
    Assert::same([], $probe->requests);

    $cache = officeTempDir() . '/health.json';
    $service = officeHealth($probe, null, $cache);
    Assert::same('ok', $service->publicSummary()['state']);
    $count = count($probe->requests);
    Assert::same('Verfügbar', $service->publicSummary()['label']);
    Assert::same($count, count($probe->requests), 'Zweite Abfrage muss aus dem Cache kommen.');
    Assert::false(str_contains((string) file_get_contents($cache), 'test-secret'), 'Cache darf keine Secrets enthalten.');
    @unlink($cache);
});

Runner::test('Office-Sicherung: Auftrag und Status ueber das Austauschverzeichnis', static function (): void {
    $dir = officeTempDir();
    $service = new OfficeBackupService($dir);

    Assert::false($service->status()['available']);
    $failed = false;
    try {
        $service->request('backup');
    } catch (RuntimeException) {
        $failed = true;
    }
    Assert::true($failed, 'Ohne Agent darf kein Auftrag angenommen werden.');

    file_put_contents($dir . '/status.json', json_encode([
        'state' => 'idle', 'action' => '', 'message' => '', 'request_id' => '', 'updated_at' => '2026-01-01T00:00:00Z',
        'retention' => 7, 'encryption' => true,
        'backups' => [
            ['name' => 'office-20260101-020000.tar.enc', 'size' => 1024, 'created' => '2026-01-01T02:00:00Z', 'encrypted' => true],
            ['name' => 'office-20260102-020000.tar.enc', 'size' => 2048, 'created' => '2026-01-02T02:00:00Z', 'encrypted' => true],
        ],
    ]));

    $status = $service->status();
    Assert::true($status['available']);
    Assert::same(7, $status['retention']);
    Assert::same('office-20260102-020000.tar.enc', $status['backups'][0]['name'], 'Neueste Sicherung zuerst.');

    $id = $service->request('backup');
    $request = json_decode((string) file_get_contents($dir . '/request.json'), true);
    Assert::same('backup', $request['action']);
    Assert::same($id, $request['id']);
    Assert::true($service->status()['pending']);

    $duplicate = false;
    try {
        $service->request('backup');
    } catch (RuntimeException) {
        $duplicate = true;
    }
    Assert::true($duplicate, 'Ein zweiter Auftrag darf nicht angenommen werden.');

    $invalid = false;
    try {
        $service->request('restore');
    } catch (RuntimeException) {
        $invalid = true;
    }
    Assert::true($invalid, 'Wiederherstellung ist ueber die Weboberflaeche nicht vorgesehen.');

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

Runner::test('Darstellung des Kachel-Status ist wählbar und versteht Altwerte', static function (): void {
    Assert::same('full', officeConfig()->tileStatusMode());
    Assert::same('compact', officeConfig(['office_tile_status' => 'compact'])->tileStatusMode());
    Assert::same('problems', officeConfig(['office_tile_status' => 'problems'])->tileStatusMode());
    Assert::same('full', officeConfig(['office_tile_status' => '1'])->tileStatusMode());
    Assert::same('off', officeConfig(['office_tile_status' => '0'])->tileStatusMode());
    Assert::same('full', officeConfig(['office_tile_status' => 'unbekannt'])->tileStatusMode());
    Assert::false(officeConfig(['office_tile_status' => 'off'])->tileStatusEnabled());
    Assert::true(officeConfig(['office_tile_status' => 'problems'])->tileStatusEnabled());
    Assert::false(OfficeConfigService::isTileStatusMode('<script>'));
});
