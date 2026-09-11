<?php

declare(strict_types=1);

use App\Repositories\ImportantLinkRepository;
use App\Services\FaviconService;
use App\Services\ImportantLinkService;
use App\Exceptions\ValidationException;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Baut eine In-Memory-SQLite-Datenbank mit dem important_links-Schema auf,
 * damit Repository und Service ohne MySQL getestet werden koennen.
 */
function importantLinksTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE important_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(120) NOT NULL,
            url VARCHAR(2048) NOT NULL,
            icon_file VARCHAR(64) NULL,
            icon_mime VARCHAR(64) NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

function importantLinksTestService(PDO $pdo): ImportantLinkService
{
    return new ImportantLinkService(
        new ImportantLinkRepository($pdo),
        new FaviconService(sys_get_temp_dir() . '/intranet-tests-icons')
    );
}

Runner::test('Wichtige Links werden angelegt und automatisch alphabetisch sortiert', static function (): void {
    $service = importantLinksTestService(importantLinksTestPdo());

    $service->create(['title' => 'Zebra-Portal', 'url' => '/zebra', 'active' => true]);
    $service->create(['title' => 'Anleitung', 'url' => '/anleitung', 'active' => true]);
    $service->create(['title' => 'Mitarbeiterportal', 'url' => '/mitarbeiter', 'active' => true]);

    $titles = array_map(static fn (array $item): string => (string) $item['title'], $service->allItems());

    Assert::same(['Anleitung', 'Mitarbeiterportal', 'Zebra-Portal'], $titles);
});

Runner::test('Ungueltige Eingaben werden fuer wichtige Links abgewiesen', static function (): void {
    $service = importantLinksTestService(importantLinksTestPdo());

    try {
        $service->create(['title' => '', 'url' => 'javascript:alert(1)', 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['title']));
        Assert::true(isset($exception->errors()['url']));
    }
});

Runner::test('Nur aktive wichtige Links werden auf der Startseite angezeigt', static function (): void {
    $service = importantLinksTestService(importantLinksTestPdo());

    $activeId = $service->create(['title' => 'Aktiv', 'url' => '/aktiv', 'active' => true]);
    $service->create(['title' => 'Inaktiv', 'url' => '/inaktiv', 'active' => false]);

    $activeItems = $service->activeItems();

    Assert::same(1, count($activeItems));
    Assert::same($activeId, (int) $activeItems[0]['id']);
});

Runner::test('Aendert sich die URL, wird ein zuvor gespeichertes Icon verworfen', static function (): void {
    $pdo = importantLinksTestPdo();
    $repository = new ImportantLinkRepository($pdo);
    $service = importantLinksTestService($pdo);

    $id = $service->create(['title' => 'Beispiel', 'url' => '/alt', 'active' => true]);
    $repository->updateIcon($id, 'favicon-0000000000000000.png', 'image/png');

    $service->update($id, ['title' => 'Beispiel', 'url' => '/neu', 'active' => true]);

    $item = $repository->find($id);
    Assert::null($item['icon_file']);
    Assert::null($item['icon_mime']);
});
