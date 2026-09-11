<?php

declare(strict_types=1);

use App\Repositories\AnnouncementRepository;
use App\Services\AnnouncementService;
use App\Exceptions\ValidationException;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Baut eine In-Memory-SQLite-Datenbank mit dem announcements-Schema auf,
 * damit Repository und Service ohne MySQL getestet werden koennen.
 */
function announcementsTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

function announcementsTestService(PDO $pdo): AnnouncementService
{
    return new AnnouncementService(new AnnouncementRepository($pdo));
}

Runner::test('Mitteilungen werden angelegt und als aktiv ausgeliefert', static function (): void {
    $service = announcementsTestService(announcementsTestPdo());

    $id = $service->create(['title' => 'Wartung', 'message' => 'Am Freitag findet eine Wartung statt.', 'active' => true]);

    $active = $service->active();
    Assert::same($id, (int) $active['id']);
});

Runner::test('Ungueltige Eingaben werden fuer Mitteilungen abgewiesen', static function (): void {
    $service = announcementsTestService(announcementsTestPdo());

    try {
        $service->create(['title' => '', 'message' => '', 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['title']));
        Assert::true(isset($exception->errors()['message']));
    }
});

Runner::test('Nur eine Mitteilung kann gleichzeitig aktiv sein', static function (): void {
    $service = announcementsTestService(announcementsTestPdo());

    $firstId = $service->create(['title' => 'Erste', 'message' => 'Erster Text', 'active' => true]);
    $secondId = $service->create(['title' => 'Zweite', 'message' => 'Zweiter Text', 'active' => true]);

    $active = $service->active();
    Assert::same($secondId, (int) $active['id']);

    $items = $service->allItems();
    $activeCount = 0;
    foreach ($items as $item) {
        if ((int) $item['active'] === 1) {
            $activeCount++;
        }
    }
    Assert::same(1, $activeCount);
    Assert::true($firstId !== $secondId);
});

Runner::test('Mitteilungen koennen archiviert und reaktiviert werden', static function (): void {
    $service = announcementsTestService(announcementsTestPdo());

    $id = $service->create(['title' => 'Info', 'message' => 'Text', 'active' => true]);

    $service->setActive($id, false);
    Assert::null($service->active());

    $service->setActive($id, true);
    $active = $service->active();
    Assert::same($id, (int) $active['id']);
});

Runner::test('Reaktivieren einer Mitteilung archiviert die zuvor aktive', static function (): void {
    $service = announcementsTestService(announcementsTestPdo());

    $firstId = $service->create(['title' => 'Erste', 'message' => 'Erster Text', 'active' => true]);
    $secondId = $service->create(['title' => 'Zweite', 'message' => 'Zweiter Text', 'active' => false]);

    $service->setActive($secondId, true);

    $active = $service->active();
    Assert::same($secondId, (int) $active['id']);

    $first = $service->find($firstId);
    Assert::same(0, (int) $first['active']);
});

Runner::test('Unbekannte Mitteilungen werden beim Statuswechsel abgewiesen', static function (): void {
    $service = announcementsTestService(announcementsTestPdo());

    try {
        $service->setActive(999, true);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['id']));
    }
});
