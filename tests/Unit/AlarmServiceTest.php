<?php

declare(strict_types=1);

use App\Repositories\AlarmLogRepository;
use App\Repositories\NavigationRepository;
use App\Repositories\SettingsRepository;
use App\Services\AlarmService;
use App\Services\SettingsService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function alarmServicePdo(): PDO
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
            sort_order INTEGER NOT NULL DEFAULT 1,
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
        'CREATE TABLE alarm_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            navigation_id INTEGER NULL,
            title VARCHAR(120) NOT NULL,
            alarm_text VARCHAR(255) NOT NULL,
            group_number VARCHAR(64) NOT NULL,
            group_description VARCHAR(255) NOT NULL DEFAULT \'\',
            status VARCHAR(16) NOT NULL DEFAULT \'error\',
            message VARCHAR(1000) NULL,
            triggered_at TEXT NOT NULL
        )'
    );

    return $pdo;
}

function alarmServiceInstance(PDO $pdo): AlarmService
{
    return new AlarmService(
        new NavigationRepository($pdo),
        new AlarmLogRepository($pdo),
        new SettingsService(new SettingsRepository($pdo))
    );
}

function alarmServiceSeed(PDO $pdo, string $alarmText = 'Einsatzalarm'): int
{
    $pdo->prepare('INSERT INTO alarm_groups (group_number, description) VALUES (?, ?)')
        ->execute(['10', 'Einsatzgruppe']);

    $pdo->prepare(
        'INSERT INTO navigation_items (title, url, type, alarm_text, alarm_group_id, active)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute(['Feuerwehr', '', 'alarm', $alarmText, 1, 1]);

    return (int) $pdo->lastInsertId();
}

Runner::test('Freitext wird beim Auslösen an die Vorlage angehängt', static function (): void {
    $pdo = alarmServicePdo();
    $service = alarmServiceInstance($pdo);
    $id = alarmServiceSeed($pdo);

    // Gateway ist nicht konfiguriert – der kombinierte Text wird dennoch protokolliert.
    $result = $service->trigger($id, 'Keller 3');

    Assert::same('error', $result['status']);

    $rows = $pdo->query('SELECT alarm_text, status FROM alarm_log')->fetchAll();
    Assert::same(1, count($rows));
    Assert::same('Einsatzalarm Keller 3', (string) $rows[0]['alarm_text']);
    Assert::same('error', (string) $rows[0]['status']);
});

Runner::test('Ohne Freitext bleibt die Vorlage unverändert', static function (): void {
    $pdo = alarmServicePdo();
    $service = alarmServiceInstance($pdo);
    $id = alarmServiceSeed($pdo);

    $service->trigger($id);

    $rows = $pdo->query('SELECT alarm_text FROM alarm_log')->fetchAll();
    Assert::same(1, count($rows));
    Assert::same('Einsatzalarm', (string) $rows[0]['alarm_text']);
});

Runner::test('Meldung über 255 Zeichen wird abgelehnt', static function (): void {
    $pdo = alarmServicePdo();
    $service = alarmServiceInstance($pdo);
    $id = alarmServiceSeed($pdo, str_repeat('a', 250));

    $result = $service->trigger($id, str_repeat('b', 100));

    Assert::same('error', $result['status']);
    Assert::contains('255', $result['message']);

    $rows = $pdo->query('SELECT alarm_text, message FROM alarm_log')->fetchAll();
    Assert::same(1, count($rows));
    Assert::same(255, mb_strlen((string) $rows[0]['alarm_text']));
    Assert::contains('255', (string) $rows[0]['message']);
});
