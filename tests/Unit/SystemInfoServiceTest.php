<?php

declare(strict_types=1);

use App\Services\SystemInfoService;
use Tests\Support\Assert;
use Tests\Support\Runner;

Runner::test('Migrationsstand: offene Dateien werden erkannt und sortiert', static function (): void {
    $state = SystemInfoService::migrationState(
        ['001_init.sql', '003_drei.sql'],
        ['001_init.sql', '002_zwei.sql', '003_drei.sql', '004_vier.sql']
    );

    Assert::same(2, $state['applied']);
    Assert::same(['002_zwei.sql', '004_vier.sql'], $state['pending']);
    Assert::false($state['complete']);
});

Runner::test('Migrationsstand: vollständig, wenn alle Dateien angewendet sind', static function (): void {
    $state = SystemInfoService::migrationState(
        ['001_init.sql', '002_zwei.sql', ''],
        ['001_init.sql', '002_zwei.sql']
    );

    Assert::same(2, $state['applied']);
    Assert::same([], $state['pending']);
    Assert::true($state['complete']);
});

Runner::test('Migrationsstand: Einträge ohne Datei zählen nicht als offen', static function (): void {
    $state = SystemInfoService::migrationState(['001_init.sql', '099_entfernt.sql'], ['001_init.sql']);

    Assert::same(2, $state['applied']);
    Assert::same([], $state['pending']);
    Assert::true($state['complete']);
});

Runner::test('Laufzeit aus /proc/uptime wird gelesen', static function (): void {
    Assert::same(12345.67, SystemInfoService::parseUptime("12345.67 98765.43\n"));
    Assert::same(42.0, SystemInfoService::parseUptime('42'));
    Assert::null(SystemInfoService::parseUptime(''));
    Assert::null(SystemInfoService::parseUptime('keine Zahl'));
});

Runner::test('Laufzeit wird lesbar formatiert', static function (): void {
    Assert::null(SystemInfoService::formatUptime(null));
    Assert::same('weniger als eine Minute', SystemInfoService::formatUptime(59));
    Assert::same('1 Minute', SystemInfoService::formatUptime(90));
    Assert::same('2 Minuten', SystemInfoService::formatUptime(150));
    Assert::same('1 Stunde, 1 Minute', SystemInfoService::formatUptime(3661));
    Assert::same('1 Tag, 1 Stunde, 1 Minute', SystemInfoService::formatUptime(90061));
    Assert::same('2 Tage, 0 Stunden, 0 Minuten', SystemInfoService::formatUptime(172800));
});
