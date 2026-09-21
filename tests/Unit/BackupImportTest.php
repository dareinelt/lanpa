<?php

declare(strict_types=1);

use App\Repositories\ActivationNumberRepository;
use App\Repositories\AdminUserRepository;
use App\Repositories\AlarmGroupRepository;
use App\Repositories\AnnouncementRepository;
use App\Repositories\EmergencyNumberRepository;
use App\Repositories\ImportantLinkRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\PhonebookRepository;
use App\Repositories\SettingsRepository;
use App\Services\BackupService;
use App\Services\BackgroundImageService;
use App\Services\FaviconService;
use App\Services\ImportService;
use App\Services\LogoService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Minimales, gueltiges 1x1-PNG (fuer getimagesizefromstring).
 */
const TEST_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

function backupImportPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key VARCHAR(64) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE navigation_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(120) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            type VARCHAR(16) NOT NULL DEFAULT \'external\',
            parent_id INTEGER NULL,
            icon VARCHAR(32) NULL,
            background_color VARCHAR(7) NULL,
            background_opacity INTEGER NULL,
            override_background INTEGER NOT NULL DEFAULT 0,
            short_description VARCHAR(255) NOT NULL DEFAULT \'\',
            description TEXT NOT NULL DEFAULT \'\',
            content TEXT NULL,
            alarm_text VARCHAR(255) NULL,
            alarm_group_id INTEGER NULL,
            protected_access INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE important_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(120) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            icon_file VARCHAR(32) NULL,
            icon_mime VARCHAR(64) NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE emergency_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label VARCHAR(120) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT \'\',
            sort_order INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(120) NOT NULL,
            message TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE alarm_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            group_number VARCHAR(64) NOT NULL,
            description VARCHAR(255) NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE activation_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone VARCHAR(64) NOT NULL,
            phone_digits VARCHAR(64) NOT NULL UNIQUE,
            alarm_group_id INTEGER NULL,
            sort_order INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT \'admin\',
            active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE phonebook (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id VARCHAR(128) NULL UNIQUE,
            display_name VARCHAR(120) NOT NULL DEFAULT \'\',
            first_name VARCHAR(64) NULL,
            last_name VARCHAR(64) NULL,
            phone VARCHAR(40) NULL,
            phone_digits VARCHAR(40) NULL,
            mobile VARCHAR(40) NULL,
            email VARCHAR(120) NULL,
            department VARCHAR(120) NULL,
            ad_modified VARCHAR(32) NULL,
            synced_at TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            visible INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

/**
 * @return array{0:BackupService,1:ImportService,2:SettingsService,3:string}
 */
function backupImportServices(PDO $pdo, string $uploadDir): array
{
    $settings = new SettingsService(new SettingsRepository($pdo));
    $settingsRepo = new SettingsRepository($pdo);
    $navRepo = new NavigationRepository($pdo);
    $linkRepo = new ImportantLinkRepository($pdo);
    $emergencyRepo = new EmergencyNumberRepository($pdo);
    $announcementRepo = new AnnouncementRepository($pdo);
    $adminUserRepo = new AdminUserRepository($pdo);
    $phonebookRepo = new PhonebookRepository($pdo);
    $alarmGroupRepo = new AlarmGroupRepository($pdo);
    $activationRepo = new ActivationNumberRepository($pdo);

    $logo = new LogoService($settings, $uploadDir, 512 * 1024, true);
    $background = new BackgroundImageService($settings, $uploadDir, 2 * 1024 * 1024);
    $favicons = new FaviconService($uploadDir);

    $backup = new BackupService(
        $settingsRepo,
        $navRepo,
        $linkRepo,
        $emergencyRepo,
        $announcementRepo,
        $adminUserRepo,
        $phonebookRepo,
        $alarmGroupRepo,
        $activationRepo,
        $logo,
        $background,
        $favicons
    );

    $import = new ImportService(
        $settingsRepo,
        $settings,
        $navRepo,
        $linkRepo,
        $emergencyRepo,
        $announcementRepo,
        $adminUserRepo,
        $phonebookRepo,
        $alarmGroupRepo,
        $activationRepo,
        $logo,
        $background,
        $favicons
    );

    return [$backup, $import, $settings, $uploadDir];
}

function backupImportTempDir(): string
{
    $dir = sys_get_temp_dir() . '/lanpa-test-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o775, true);

    return $dir;
}

function cleanupBackupImportDir(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

Runner::test('Sicherung enthält alle Daten und kann vollständig eingespielt werden', static function (): void {
    $sourceDir = backupImportTempDir();
    $targetDir = backupImportTempDir();

    try {
        $sourcePdo = backupImportPdo();
        [$backup, , $sourceSettings] = backupImportServices($sourcePdo, $sourceDir);

        $sourceSettingsRepo = new SettingsRepository($sourcePdo);
        $sourceSettingsRepo->insert('site_title', 'Quellinstallation');
        $sourceSettingsRepo->insert('footer_text', 'Fußzeile');

        $navRepo = new NavigationRepository($sourcePdo);
        $parent = $navRepo->create(['title' => 'Bereich', 'url' => '', 'type' => 'subpage', 'icon' => null, 'active' => true, 'sort_order' => 1]);
        $navRepo->create(['title' => 'Seite', 'url' => '', 'type' => 'page', 'parent_id' => $parent, 'icon' => null, 'content' => '<p>Text</p>', 'active' => true, 'sort_order' => 1]);

        $linkRepo = new ImportantLinkRepository($sourcePdo);
        $linkRepo->create(['title' => 'GitHub', 'url' => 'https://github.com', 'icon_file' => 'favicon-0123456789abcdef.png', 'icon_mime' => 'image/png', 'active' => true]);

        $emergencyRepo = new EmergencyNumberRepository($sourcePdo);
        $emergencyRepo->create(['label' => 'Feuerwehr', 'phone' => '112', 'description' => '', 'sort_order' => 1, 'active' => true]);

        $announcementRepo = new AnnouncementRepository($sourcePdo);
        $announcementRepo->create(['title' => 'Wartung', 'message' => 'Wartung am Wochenende', 'active' => true]);

        $adminRepo = new AdminUserRepository($sourcePdo);
        $adminRepo->create('admin', '$2y$10$abcdefghijklmnopqrstuv', AdminUserRepository::ROLE_ADMIN);
        $adminRepo->create('redakteur', '$2y$10$uvwxyzabcdefghijklmnopq', AdminUserRepository::ROLE_REDAKTION);

        $phonebookRepo = new PhonebookRepository($sourcePdo);
        $phonebookRepo->insert(['external_id' => 'ext-1', 'display_name' => 'Max Mustermann', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'phone' => '0123', 'active' => 1]);

        $logoFile = 'logo-0123456789abcdef.png';
        file_put_contents($sourceDir . '/' . $logoFile, base64_decode(TEST_PNG));
        $sourceSettingsRepo->insert('logo_file', $logoFile);
        $sourceSettingsRepo->insert('logo_mime', 'image/png');

        $backgroundFile = 'background-0123456789abcdef.png';
        file_put_contents($sourceDir . '/' . $backgroundFile, base64_decode(TEST_PNG));
        $sourceSettingsRepo->insert('background_file', $backgroundFile);
        $sourceSettingsRepo->insert('background_mime', 'image/png');

        file_put_contents($sourceDir . '/favicon-0123456789abcdef.png', base64_decode(TEST_PNG));

        $zip = $backup->create();
        Assert::true(strlen($zip) > 0);
        Assert::true(str_contains($zip, 'manifest.json'));

        $targetPdo = backupImportPdo();
        [$targetBackup, $targetImport, $targetSettings] = backupImportServices($targetPdo, $targetDir);

        $targetImport->import($zip);

        Assert::same('Quellinstallation', $targetSettings->get('site_title'));
        Assert::same('Fußzeile', $targetSettings->get('footer_text'));

        $targetNav = new NavigationRepository($targetPdo);
        $nav = $targetNav->all();
        Assert::same(2, count($nav));
        $child = array_values(array_filter($nav, static fn (array $r): bool => $r['type'] === 'page'))[0];
        $root = array_values(array_filter($nav, static fn (array $r): bool => $r['type'] === 'subpage'))[0];
        Assert::same((int) $root['id'], (int) $child['parent_id']);

        $targetLink = new ImportantLinkRepository($targetPdo);
        $links = $targetLink->all();
        Assert::same(1, count($links));
        Assert::same('favicon-0123456789abcdef.png', $links[0]['icon_file']);

        $targetEmergency = new EmergencyNumberRepository($targetPdo);
        Assert::same(1, count($targetEmergency->all()));

        $targetAnnouncement = new AnnouncementRepository($targetPdo);
        Assert::same(1, count($targetAnnouncement->all()));

        $targetAdmin = new AdminUserRepository($targetPdo);
        $users = $targetAdmin->allWithPasswordHash();
        Assert::same(2, count($users));

        $targetPhonebook = new PhonebookRepository($targetPdo);
        $entries = $targetPhonebook->all();
        Assert::same(1, count($entries));
        Assert::same('Max Mustermann', $entries[0]['display_name']);

        Assert::true(is_file($targetDir . '/' . $logoFile));
        Assert::true(is_file($targetDir . '/' . $backgroundFile));
        Assert::true(is_file($targetDir . '/favicon-0123456789abcdef.png'));

        Assert::true($targetBackup instanceof BackupService);
    } finally {
        cleanupBackupImportDir($sourceDir);
        cleanupBackupImportDir($targetDir);
    }
});

Runner::test('Import verweigert Archive ohne aktives Administratorkonto', static function (): void {
    $sourceDir = backupImportTempDir();
    $targetDir = backupImportTempDir();

    try {
        $sourcePdo = backupImportPdo();
        [$backup] = backupImportServices($sourcePdo, $sourceDir);

        $adminRepo = new AdminUserRepository($sourcePdo);
        $id = $adminRepo->create('admin', 'hash', AdminUserRepository::ROLE_ADMIN);
        $adminRepo->setActive($id, false);

        $zip = $backup->create();

        $targetPdo = backupImportPdo();
        [, $targetImport] = backupImportServices($targetPdo, $targetDir);

        try {
            $targetImport->import($zip);
            Assert::true(false);
        } catch (App\Exceptions\ValidationException $exception) {
            Assert::true(isset($exception->errors()['backup']));
        }
    } finally {
        cleanupBackupImportDir($sourceDir);
        cleanupBackupImportDir($targetDir);
    }
});
