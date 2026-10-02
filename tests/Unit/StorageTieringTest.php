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
    Assert::contains('Cold-Tier (SMB-Tier)', $html);
    Assert::contains('data-fill="target-7"', $html);
    Assert::contains('NAS &lt;A&gt;', $html);
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
