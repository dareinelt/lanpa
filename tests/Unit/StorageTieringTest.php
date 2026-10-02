<?php

declare(strict_types=1);

use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\SettingsRepository;
use App\Repositories\StorageRepository;
use App\Security\SecretBox;
use App\Services\SettingsService;
use App\Services\Storage\StorageHealth;
use App\Services\Storage\StorageService;
use App\Services\Storage\StorageSettings;
use Tests\Support\Assert;
use Tests\Support\Runner;

function storagePdo(): PDO
{
    $pdo = officePdo();
    $pdo->exec('CREATE TABLE storage_targets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        label TEXT NOT NULL,
        unc_path TEXT NOT NULL UNIQUE,
        username TEXT NOT NULL DEFAULT \'\',
        password TEXT NULL,
        domain TEXT NOT NULL DEFAULT \'\',
        smb_version TEXT NOT NULL DEFAULT \'auto\',
        kind TEXT NOT NULL DEFAULT \'smb\',
        s3_endpoint TEXT NOT NULL DEFAULT \'\',
        s3_region TEXT NOT NULL DEFAULT \'\',
        s3_bucket TEXT NOT NULL DEFAULT \'\',
        s3_prefix TEXT NOT NULL DEFAULT \'\',
        s3_path_style INTEGER NOT NULL DEFAULT 1,
        s3_verify_tls INTEGER NOT NULL DEFAULT 1,
        capacity_bytes INTEGER NOT NULL DEFAULT 0,
        is_primary INTEGER NOT NULL DEFAULT 0,
        active INTEGER NOT NULL DEFAULT 1
    )');

    return $pdo;
}

function storageService(PDO $pdo): StorageService
{
    return new StorageService(
        new StorageRepository($pdo),
        new SettingsService(new SettingsRepository($pdo)),
        new SecretBox(sys_get_temp_dir() . '/lanpa-storage-' . bin2hex(random_bytes(6)) . '/keys/secrets.key'),
        true
    );
}

/**
 * @return array<string,mixed>
 */
function storageOverviewFixture(bool $offline): array
{
    $settings = new StorageSettings(['storage_enabled' => '1', 'storage_local_limit_mb' => '1024']);
    $targets = [[
        'id' => 7, 'label' => 'NAS <A>', 'unc_path' => '\\\\nas01\\backup', 'username' => 'svc', 'domain' => 'FIRMA',
        'smb_version' => 'auto', 'has_password' => true, 'is_primary' => true, 'active' => true,
        'state' => $offline ? 'offline' : 'online', 'message' => $offline ? 'Host nicht erreichbar' : '',
        'total_bytes' => 1000, 'free_bytes' => 100, 'fill' => StorageHealth::fill(1000, 100, 85, 95),
        'read_bps' => 1048576, 'write_bps' => 0, 'read_iops' => 2.5, 'write_iops' => 1.0, 'in_sync' => !$offline,
        'pending_files' => $offline ? 3 : 0, 'pending_bytes' => 300, 'lag_seconds' => 600, 'synced_files' => 10,
        'synced_bytes' => 4096, 'state_since' => '', 'last_sync_at' => '',
    ]];
    $health = StorageHealth::evaluate(true, $targets, 5, 5, ['sync_state' => 'idle', 'pending_files' => 0], 900);
    $forecast = StorageHealth::forecast([[0, 0], [86400, 100 * StorageSettings::MIB]], 1000 * StorageSettings::MIB, null, 86400);

    return [
        'settings' => $settings,
        'office_enabled' => true,
        'status' => ['bytes_total' => 5000, 'files_total' => 12, 'last_sync_at' => null],
        'targets' => $targets,
        'health' => $health,
        'local' => [
            'total_bytes' => 2000, 'free_bytes' => 500, 'used_bytes' => 1500,
            'fill' => StorageHealth::fill(2000, 500, 85, 95), 'limit_bytes' => 0, 'limit_auto' => false, 'bytes_local' => 10,
            'read_bps' => 0, 'write_bps' => 0, 'read_iops' => 0.0, 'write_iops' => 0.0, 'metrics_source' => 'block',
        ],
        'mode' => 'normal',
        'mode_reason' => '',
        'forecast' => $forecast,
        'forecast_text' => StorageService::forecastText($forecast, 1000 * StorageSettings::MIB),
    ];
}

Runner::test('Speicher-Tiering: Einstellungen werden geprueft', static function (): void {
    $result = StorageSettings::validate(['storage_local_days' => '0', 'storage_fill_warn_percent' => '90', 'storage_fill_crit_percent' => '80']);
    Assert::true(isset($result['errors']['storage_local_days']));
    Assert::true(isset($result['errors']['storage_fill_crit_percent']));

    $ok = StorageSettings::validate(['storage_enabled' => '1', 'storage_local_limit_mb' => '2048']);
    Assert::same([], $ok['errors']);
    Assert::same('1', $ok['values']['storage_enabled']);
    Assert::same(2048 * StorageSettings::MIB, (new StorageSettings($ok['values']))->localLimitBytes());
});

Runner::test('Speicher-Tiering: Ausfall des Cold-Tiers wird als kritisch gemeldet', static function (): void {
    $overview = storageOverviewFixture(true);
    Assert::true($overview['health']['remote_unavailable']);
    Assert::same('critical', $overview['health']['ha']['state']);
    Assert::contains('Cold-Tier', $overview['health']['ha']['message']);

    $alert = storageService(storagePdo())->dashboardAlert($overview);
    Assert::same('error', $alert['level'] ?? null);
    Assert::contains('ausschließlich im Hot-Tier', $alert['message']);
    Assert::contains('reicht noch', $alert['forecast']);
});

Runner::test('Speicher-Tiering: HA meldet erst nach vollstaendigem Abgleich synchron', static function (): void {
    $targets = storageOverviewFixture(false)['targets'];
    $targets[] = array_replace($targets[0], ['id' => 8, 'label' => 'NAS B', 'in_sync' => false, 'lag_seconds' => 30]);
    $health = StorageHealth::evaluate(true, $targets, 5, 5, ['pending_files' => 1], 900);
    Assert::same('degraded', $health['ha']['state']);
    Assert::same(1, $health['ha']['exit']);
    Assert::contains('noch nicht vollständig synchron', $health['ha']['message']);
    Assert::same('syncing', $health['sync']['state']);
    $targets[1]['in_sync'] = true;
    $health = StorageHealth::evaluate(true, $targets, 5, 5, ['pending_files' => 0], 900);
    Assert::same('ok', $health['ha']['state']);
    Assert::same('in_sync', $health['sync']['state']);
});

Runner::test('Speicher-Tiering: Live-Daten enthalten alle dynamischen Anzeigewerte ohne Zugangsdaten', static function (): void {
    $overview = storageOverviewFixture(true);
    $overview['status'] += ['bytes_local' => 1000, 'bytes_evicted' => 4000, 'files_evicted' => 8,
        'pending_files' => 3, 'recalls_active' => 1, 'recalls_total' => 12, 'recalls_failed' => 2];
    $service = storageService(storagePdo());
    $data = $service->liveData($overview);
    Assert::same(0, $data['online']);
    Assert::same(1, $data['active']);
    Assert::same('–', $data['last_sync']);
    Assert::same($overview['forecast_text'], $data['forecast']);
    Assert::same($service->dashboardAlert($overview), $data['alert']);
    Assert::same('4,9 KB', $data['inventory']['bytes']);
    Assert::same('8', $data['inventory']['evicted_files']);
    Assert::same(2, $data['inventory']['recalls_failed']);
    Assert::same('Host nicht erreichbar', $data['targets'][0]['message']);
    Assert::same('100 B', $data['targets'][0]['free']);
    Assert::same('1.000 B', $data['targets'][0]['total']);
    Assert::same('10', $data['targets'][0]['synced_files']);
    Assert::same('4,0 KB', $data['targets'][0]['synced_bytes']);
    Assert::same(3, $data['targets'][0]['pending']);
    Assert::same('10 min', $data['targets'][0]['lag']);
    foreach (['username', 'domain', 'password', 'has_password', 'unc_path'] as $key) {
        Assert::false(array_key_exists($key, $data['targets'][0]));
    }
    $overview['targets'][0]['state'] = 'online';
    $overview['health'] = StorageHealth::evaluate(true, $overview['targets'], 5, 5, ['pending_files' => 0], 900);
    Assert::null($service->liveData($overview)['alert']);
});

Runner::test('Speicher-Tiering: Speicherziel wird geprueft und Kennwort verschluesselt', static function (): void {
    $service = storageService(storagePdo());
    $values = $service->validateTarget([
        'label' => 'NAS', 'unc_path' => '\\\\nas01\\backup\\lanpa', 'username' => 'svc', 'domain' => 'FIRMA',
        'password' => 'geheim', 'smb_version' => '3.0',
    ], null);
    Assert::same('\\\\nas01\\backup\\lanpa', $values['unc_path']);
    Assert::true(SecretBox::isEncrypted((string) $values['password']));
    Assert::same('//nas01/backup/lanpa', StorageService::mountDevice($values['unc_path']));

    try {
        $service->validateTarget(['label' => '', 'unc_path' => 'nas01', 'username' => 'a,b', 'smb_version' => '1.0'], null);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::same(['label', 'unc_path', 'username', 'smb_version'], array_keys($exception->errors()));
    }
});

Runner::test('Speicher-Tiering: Adminseite und Zielformular werden ohne Inline-Styles gerendert', static function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $overview = storageOverviewFixture(true);
    $html = View::render('admin.storage', [
        'overview' => $overview,
        'alert' => storageService(storagePdo())->dashboardAlert($overview),
        'events' => [['created_at' => '2026-10-01 10:00:00', 'level' => 'warning', 'message' => 'Ziel offline', 'target_label' => 'NAS']],
        'errors' => [],
        'values' => [],
    ]);
    Assert::contains('Hot-Tier (lokales Storage)', $html);
    Assert::contains('Cold-Tier (SMB-/S3-Tier)', $html);
    Assert::contains('data-fill="target-7"', $html);
    Assert::contains('NAS &lt;A&gt;', $html);
    Assert::contains('aria-label="Füllstand NAS &lt;A&gt;"', $html);
    Assert::contains('class="storage-target"', $html);
    Assert::contains('data-live="forecast"', $html);
    Assert::contains('data-confirm-deletes hidden', $html);
    Assert::false(str_contains($html, 'style="'));

    $form = View::render('admin.storage_target', [
        'target' => ['id' => 7, 'label' => 'NAS', 'unc_path' => '\\\\nas01\\backup', 'username' => 'svc', 'domain' => '',
            'password' => 'enc', 'smb_version' => '3.0', 'is_primary' => 1, 'active' => 1],
        'versions' => StorageService::SMB_VERSIONS,
        'errors' => ['unc_path' => 'Falsch'],
        'values' => [],
    ]);
    Assert::contains('Ein Kennwort ist gespeichert', $form);
    Assert::contains('<option value="3.0" selected>', $form);
    Assert::contains('id="unc_path-error"', $form);
    Assert::false(str_contains($form, 'value="enc"'));
});

Runner::test('Speicher-Tiering: leere Ansicht und Validierungsfehler bleiben bedienbar', static function (): void {
    $overview = storageOverviewFixture(false);
    $overview['targets'] = [];
    $overview['local']['fill'] = ['percent' => null, 'state' => 'disabled'];
    $overview['health']['sync']['state'] = 'blocked';
    $html = View::render('admin.storage', [
        'overview' => $overview, 'alert' => null, 'events' => [],
        'errors' => ['storage_local_days' => 'Ungültige Anzahl'],
        'values' => ['storage_local_days' => '0'],
    ]);
    Assert::contains('Noch kein Speicherziel eingerichtet', $html);
    Assert::contains('aria-valuetext="Keine Messwerte"', $html);
    Assert::contains('data-storage-alert role="alert" hidden', $html);
    Assert::false(str_contains($html, 'data-confirm-deletes hidden'));
    Assert::contains('Löschungen übernehmen', $html);
    Assert::contains('aria-invalid="true" aria-describedby="storage_local_days-error"', $html);
    Assert::contains('Ungültige Anzahl', $html);
    Assert::contains('value="0"', $html);
    foreach (array_keys(StorageSettings::NUMERIC) as $key) {
        Assert::contains('name="' . $key . '"', $html);
    }
    Assert::contains('<noscript>', $html);
});

Runner::test('Speicher-Tiering: S3-Ziel wird geprueft, normalisiert und Secret verschluesselt', static function (): void {
    $pdo = storagePdo();
    $service = storageService($pdo);
    $input = [
        'label' => 'MinIO', 'kind' => 's3', 's3_endpoint' => ' HTTPS://MinIO.firma.local:9000/ ', 's3_region' => 'EU-Central-1',
        's3_bucket' => 'Lanpa-Cold', 's3_prefix' => '/intranet/prod/', 's3_access_key' => 'AKIAEXAMPLE',
        's3_secret_key' => 'geheim/Secret+Key', 's3_path_style' => true, 's3_verify_tls' => false, 'capacity_gb' => '500',
    ];
    $values = $service->validateTarget($input, null);
    Assert::same('s3', $values['kind']);
    Assert::same('https://minio.firma.local:9000', $values['s3_endpoint']);
    Assert::same('eu-central-1', $values['s3_region']);
    Assert::same('lanpa-cold', $values['s3_bucket']);
    Assert::same('intranet/prod', $values['s3_prefix']);
    Assert::same('s3://minio.firma.local:9000/lanpa-cold/intranet/prod', $values['unc_path']);
    Assert::same('AKIAEXAMPLE', $values['username']);
    Assert::same(1, $values['s3_path_style']);
    Assert::same(0, $values['s3_verify_tls']);
    Assert::same(500 * 1073741824, $values['capacity_bytes']);
    Assert::true(SecretBox::isEncrypted((string) $values['password']));

    // Gleicher Ort darf nicht doppelt angelegt werden; Secret beim Bearbeiten optional.
    $id = (new StorageRepository($pdo))->createTarget($values);
    $existing = $service->target($id);
    Assert::same('s3', $existing['kind']);
    $kept = $service->validateTarget([
        'label' => 'MinIO', 'kind' => 's3', 's3_endpoint' => 'https://minio.firma.local:9000', 's3_bucket' => 'lanpa-cold',
        's3_prefix' => 'intranet/prod', 's3_access_key' => 'AKIAEXAMPLE', 's3_secret_key' => '',
    ], $existing);
    Assert::false(array_key_exists('password', $kept));
    try {
        $service->validateTarget([
            'label' => 'Doppelt', 'kind' => 's3', 's3_endpoint' => 'http://minio.firma.local:9000', 's3_bucket' => 'lanpa-cold',
            's3_prefix' => 'intranet/prod', 's3_access_key' => 'AKIA2', 's3_secret_key' => 'x',
        ], null);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::same(['s3_bucket'], array_keys($exception->errors()));
    }

    // Wechsel von S3 zu SMB verwirft das Secret.
    $smb = $service->validateTarget(['label' => 'NAS', 'kind' => 'smb', 'unc_path' => '\\\\nas01\\backup', 'smb_version' => 'auto'], $existing);
    Assert::null($smb['password']);
    Assert::same('', $smb['s3_bucket']);
    Assert::same(0, $smb['capacity_bytes']);

    try {
        $service->validateTarget([
            'label' => 'X', 'kind' => 's3', 's3_endpoint' => 'ftp://user:pw@host/pfad', 's3_region' => 'eu central',
            's3_bucket' => 'A_b', 's3_prefix' => '../etc', 's3_access_key' => 'a:b', 's3_secret_key' => '', 'capacity_gb' => '-1',
        ], null);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::same(['s3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_access_key', 's3_secret_key', 'capacity_gb'], array_keys($exception->errors()));
    }
    try {
        $service->validateTarget(['label' => 'X', 'kind' => 'nfs'], null);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['kind']));
    }

    Assert::same('https://[::1]:9000', StorageService::normalizeS3Endpoint('https://[::1]:9000'));
    Assert::null(StorageService::normalizeS3Endpoint('https://s3.example.com/bucket'));
    Assert::null(StorageService::normalizeS3Endpoint('https://s3.example.com?x=1'));
    Assert::same('', StorageService::normalizeS3Prefix(' / '));
});

Runner::test('Speicher-Tiering: S3-Ziele in Adminseite, Formular und SNMP', static function (): void {
    View::setViewPath(BASE_PATH . '/views');
    $service = storageService(storagePdo());
    $overview = storageOverviewFixture(false);
    $s3 = array_merge($overview['targets'][0], [
        'id' => 8, 'label' => 'MinIO', 'kind' => 's3', 'unc_path' => 's3://minio:9000/lanpa', 'username' => 'AKIAEXAMPLE',
        's3_endpoint' => 'https://minio:9000', 's3_region' => '', 's3_bucket' => 'lanpa', 's3_prefix' => '',
        'capacity_bytes' => 0, 'unbounded' => true, 'is_primary' => false, 'total_bytes' => 0, 'free_bytes' => 0,
        'fill' => StorageHealth::fill(0, 0, 85, 95), 'synced_bytes' => 2048,
    ]);
    $overview['targets'] = [$s3];
    $overview['health'] = StorageHealth::evaluate(true, $overview['targets'], 5, 5, ['pending_files' => 0], 900);

    $html = View::render('admin.storage', ['overview' => $overview, 'alert' => null, 'events' => [], 'errors' => [], 'values' => []]);
    Assert::contains('s3://minio:9000/lanpa', $html);
    Assert::contains('ohne Kapazitätsgrenze', $html);
    Assert::contains('Access Key AKIAEXAMPLE', $html);

    $cold = $service->snmp('storage_cold_fill', $overview);
    Assert::same(0, $cold['exit']);
    Assert::contains('MinIO ohne Kapazitaetsgrenze', $cold['lines'][0]);
    Assert::contains('kind=s3', $service->snmp('storage_targets', $overview)['lines'][0]);
    $live = $service->liveData($overview);
    foreach (['username', 'password', 's3_endpoint', 'unc_path'] as $key) {
        Assert::false(array_key_exists($key, $live['targets'][0]));
    }

    $form = View::render('admin.storage_target', [
        'target' => ['id' => 8, 'label' => 'MinIO', 'kind' => 's3', 'unc_path' => 's3://minio:9000/lanpa', 'username' => 'AKIAEXAMPLE',
            'domain' => '', 'password' => 'enc', 'smb_version' => 'auto', 's3_endpoint' => 'https://minio:9000', 's3_region' => '',
            's3_bucket' => 'lanpa', 's3_prefix' => '', 's3_path_style' => 1, 's3_verify_tls' => 1, 'capacity_bytes' => 2 * 1073741824,
            'is_primary' => 0, 'active' => 1],
        'versions' => StorageService::SMB_VERSIONS,
        'kinds' => StorageService::KINDS,
        'errors' => [],
        'values' => [],
    ]);
    Assert::contains('<option value="s3" selected>', $form);
    Assert::contains('value="AKIAEXAMPLE"', $form);
    Assert::contains('value="2"', $form);
    Assert::contains('Ein Secret Key ist gespeichert', $form);
    Assert::false(str_contains($form, 'Ein Kennwort ist gespeichert'));
    Assert::false(str_contains($form, 'value="s3://minio:9000/lanpa"'));
    Assert::false(str_contains($form, 'value="enc"'));
    Assert::false(str_contains($form, 'style="'));
});
