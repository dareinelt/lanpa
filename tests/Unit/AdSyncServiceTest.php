<?php

declare(strict_types=1);

use App\Core\Logger;
use App\Services\AdSyncService;
use Tests\Support\Assert;
use Tests\Support\FakeLdapClient;
use Tests\Support\FakePhonebookStore;
use Tests\Support\FakeSyncLog;
use Tests\Support\Runner;

function testLogger(): Logger
{
    return new Logger(sys_get_temp_dir() . '/intranet-tests.log', 'error');
}

/**
 * @return list<array<string,string|null>>
 */
function testUsers(): array
{
    return [
        ['external_id' => 'guid-1', 'display_name' => 'Erika Muster'],
        ['external_id' => 'guid-2', 'display_name' => 'Max Beispiel'],
    ];
}

Runner::test('Erfolgreiche Synchronisation schreibt und deaktiviert veraltete Einträge', static function (): void {
    $store = new FakePhonebookStore();
    $log = new FakeSyncLog();
    $service = new AdSyncService(new FakeLdapClient(testUsers()), $store, $log, testLogger());

    $result = $service->run();

    Assert::same('success', $result['status']);
    Assert::same(2, $result['processed']);
    Assert::same(2, $result['deactivated']);
    Assert::same(2, count($store->upserted));
    Assert::same(1, $store->deactivateCalls);
    Assert::same(1, $store->commits);
    Assert::same('success', $log->entries[0]['status']);
});

Runner::test('Nicht erreichbares AD verändert den Datenbestand nicht', static function (): void {
    $store = new FakePhonebookStore();
    $log = new FakeSyncLog();
    $service = new AdSyncService(new FakeLdapClient([], true), $store, $log, testLogger());

    $result = $service->run();

    Assert::same('error', $result['status']);
    Assert::same(0, count($store->upserted));
    Assert::same(0, $store->deactivateCalls);
    Assert::same('error', $log->entries[0]['status']);
});

Runner::test('Leeres AD-Ergebnis deaktiviert keine Einträge', static function (): void {
    $store = new FakePhonebookStore();
    $service = new AdSyncService(new FakeLdapClient([]), $store, new FakeSyncLog(), testLogger());

    $result = $service->run();

    Assert::same('error', $result['status']);
    Assert::same(0, $store->deactivateCalls);
    Assert::same(0, $store->commits);
});

Runner::test('Schreibfehler führt zum Rollback ohne Deaktivierung', static function (): void {
    $store = new FakePhonebookStore();
    $store->failOnUpsert = true;
    $service = new AdSyncService(new FakeLdapClient(testUsers()), $store, new FakeSyncLog(), testLogger());

    $result = $service->run();

    Assert::same('error', $result['status']);
    Assert::same(1, $store->rollbacks);
    Assert::same(0, $store->deactivateCalls);
    Assert::same(0, $store->commits);
});

Runner::test('Datensätze ohne eindeutige Kennung werden übersprungen', static function (): void {
    $store = new FakePhonebookStore();
    $users = [
        ['external_id' => '', 'display_name' => 'Ohne Kennung'],
        ['external_id' => 'guid-3', 'display_name' => 'Mit Kennung'],
    ];
    $service = new AdSyncService(new FakeLdapClient($users), $store, new FakeSyncLog(), testLogger());

    $result = $service->run();

    Assert::same('success', $result['status']);
    Assert::same(1, $result['processed']);
    Assert::same('guid-3', $store->upserted[0]['external_id']);
});
