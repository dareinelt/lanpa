<?php

declare(strict_types=1);

use App\Repositories\ActivationNumberRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use App\Services\SmsCodeService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function smsCodePdo(): PDO
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
        "INSERT INTO settings (setting_key, setting_value) VALUES ('sms_code_secret', '" . str_repeat('a', 64) . "')"
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

    return $pdo;
}

function smsCodeService(PDO $pdo): SmsCodeService
{
    return new SmsCodeService(
        new NavigationRepository($pdo),
        new ActivationNumberRepository($pdo),
        new SettingsService(new SettingsRepository($pdo))
    );
}

function smsCodeSeedNavigation(PDO $pdo, int $protectedAccess = 1, int $active = 1): int
{
    $pdo->prepare(
        'INSERT INTO navigation_items (title, url, type, protected_access, active) VALUES (?, ?, ?, ?, ?)'
    )->execute(['Geschützt', 'https://example.com', 'external', $protectedAccess, $active]);

    return (int) $pdo->lastInsertId();
}

Runner::test('Tagescode ist sechsstellig und deterministisch', static function (): void {
    $service = smsCodeService(smsCodePdo());

    $first = $service->currentCode();
    $second = $service->currentCode();

    Assert::same(6, strlen($first));
    Assert::true(ctype_digit($first));
    Assert::same($first, $second);
});

Runner::test('SMS-Vorlage ersetzt Platzhalter', static function (): void {
    $pdo = smsCodePdo();
    (new SettingsRepository($pdo))->insert('sms_code_template', 'Code: {code} für {title}');
    $service = smsCodeService($pdo);

    Assert::same('Code: 123456 für Alarm', $service->buildMessage('123456', 'Alarm'));
});

Runner::test('Zeitfenster wird begrenzt', static function (): void {
    $pdo = smsCodePdo();
    (new SettingsRepository($pdo))->insert('sms_code_timeout', '999');
    $service = smsCodeService($pdo);
    Assert::same(120, $service->timeout());
});

Runner::test('Unbekannte Rufnummer erzeugt keine Fehlermeldung und laesst sich mit Tagescode verifizieren', static function (): void {
    $_SESSION = [];

    $pdo = smsCodePdo();
    $service = smsCodeService($pdo);
    $navId = smsCodeSeedNavigation($pdo);

    $result = $service->requestCode($navId, '+49 170 1234567');
    Assert::same('success', $result['status']);

    $ok = $service->verify($navId, '+49 170 1234567', $service->currentCode());
    Assert::same('success', $ok['status']);

    // Nach erfolgreicher Verifizierung ist der Zugriff freigeschaltet.
    Assert::true($service->isVerified($navId));

    // Ein zweiter Versuch scheitert, weil die Sitzung bereits verbraucht ist.
    $again = $service->verify($navId, '+49 170 1234567', $service->currentCode());
    Assert::same('error', $again['status']);
});

Runner::test('Zugriff ist erst nach erfolgreicher Verifizierung freigeschaltet', static function (): void {
    $_SESSION = [];

    $pdo = smsCodePdo();
    $service = smsCodeService($pdo);
    $navId = smsCodeSeedNavigation($pdo);

    Assert::false($service->isVerified($navId));

    $service->markVerified($navId);
    Assert::true($service->isVerified($navId));
    Assert::false($service->isVerified($navId + 1));
});

Runner::test('Nicht geschuetzte Elemente werden abgelehnt', static function (): void {
    $_SESSION = [];

    $pdo = smsCodePdo();
    $service = smsCodeService($pdo);
    $navId = smsCodeSeedNavigation($pdo, 0);

    $result = $service->requestCode($navId, '+49 170 1234567');
    Assert::same('error', $result['status']);
});

Runner::test('findProtectedInternal liefert geschuetzte interne Elemente', static function (): void {
    $pdo = smsCodePdo();
    $pdo->prepare(
        'INSERT INTO navigation_items (title, url, type, protected_access, active) VALUES (?, ?, ?, ?, ?)'
    )->execute(['Telefonliste', '/telefonliste', 'internal', 1, 1]);

    $service = smsCodeService($pdo);

    $item = $service->findProtectedInternal('/telefonliste');
    Assert::true($item !== null);
    Assert::same('internal', (string) $item['type']);
    Assert::true($service->findProtectedInternal('/unbekannt') === null);
});

Runner::test('findProtectedInternal ignoriert ungeschuetzte interne Elemente', static function (): void {
    $pdo = smsCodePdo();
    $pdo->prepare(
        'INSERT INTO navigation_items (title, url, type, protected_access, active) VALUES (?, ?, ?, ?, ?)'
    )->execute(['Telefonliste', '/telefonliste', 'internal', 0, 1]);

    $service = smsCodeService($pdo);

    Assert::true($service->findProtectedInternal('/telefonliste') === null);
});

Runner::test('targetUrl bildet alle Elementtypen ab', static function (): void {
    $service = smsCodeService(smsCodePdo());

    Assert::same('/seite?id=5', $service->targetUrl(['id' => 5, 'type' => 'page', 'url' => '']));
    Assert::same('/unterseite?id=7', $service->targetUrl(['id' => 7, 'type' => 'subpage', 'url' => '']));
    Assert::same('/telefonliste', $service->targetUrl(['id' => 9, 'type' => 'internal', 'url' => '/telefonliste']));
    Assert::same('https://example.com', $service->targetUrl(['id' => 11, 'type' => 'external', 'url' => 'https://example.com']));
});
