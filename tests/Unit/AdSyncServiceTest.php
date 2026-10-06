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
    // Zwei Festschreibungen: ein Schreibblock (2 Konten < BLOCK_SIZE) und die
    // abschliessende Transaktion fuer Abgleich und Gruppen.
    Assert::same(2, $store->commits);
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

/**
 * @return list<array<string,string|null>>
 */
function manyUsers(int $count): array
{
    $users = [];
    for ($i = 1; $i <= $count; $i++) {
        $users[] = ['external_id' => 'guid-' . $i, 'display_name' => 'Konto ' . $i];
    }

    return $users;
}

Runner::test('Grosse Kontenmengen werden blockweise mit einheitlichem Zeitstempel geschrieben', static function (): void {
    $store = new FakePhonebookStore();
    $service = new AdSyncService(new FakeLdapClient(manyUsers(450)), $store, new FakeSyncLog(), testLogger());

    $result = $service->run();

    Assert::same('success', $result['status']);
    Assert::same(450, $result['processed']);
    Assert::same(450, count($store->upserted));
    // 450 Konten ergeben drei Bloecke (200/200/50) plus die Abschluss-Transaktion.
    Assert::same(3, count($store->syncStamps));
    Assert::same(4, $store->commits);
    // Nur ein Zeitstempel fuer den ganzen Lauf, sonst wuerde deactivateStale()
    // die zuvor geschriebenen Bloecke wieder deaktivieren.
    Assert::same(1, count(array_unique($store->syncStamps)));
    Assert::same(1, $store->deactivateCalls);
});

Runner::test('Abbruch im zweiten Block lässt den ersten Block festgeschrieben', static function (): void {
    $store = new FakePhonebookStore();
    $store->failAfterUsers = 200;
    $service = new AdSyncService(new FakeLdapClient(manyUsers(450)), $store, new FakeSyncLog(), testLogger());

    $result = $service->run();

    Assert::same('error', $result['status']);
    // Der erste Block ist bereits festgeschrieben, der zweite wird zurueckgerollt.
    Assert::same(200, count($store->upserted));
    Assert::same(1, $store->commits);
    Assert::same(1, $store->rollbacks);
    // Ohne vollstaendigen Lauf wird nichts deaktiviert - kein Konto verliert
    // seine Sichtbarkeit.
    Assert::same(0, $store->deactivateCalls);
});
