<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\NavigationRepository;
use App\Services\NavigationService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function navigationTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    return $pdo;
}

function navigationTestService(PDO $pdo): NavigationService
{
    return new NavigationService(new NavigationRepository($pdo));
}

Runner::test('Unterseiten und Textseiten werden angelegt und hierarchisch sortiert', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $root = $service->create(['title' => 'Bereich', 'type' => 'subpage', 'active' => true]);
    $child = $service->create(['title' => 'Unterpunkt', 'type' => 'page', 'parent_id' => $root, 'content' => '<p>Inhalt</p>', 'active' => true]);

    $topLevel = $service->activeTopLevel();
    Assert::same(1, count($topLevel));
    Assert::same('subpage', $topLevel[0]['type']);

    $children = $service->activeChildren($root);
    Assert::same(1, count($children));
    Assert::same($child, (int) $children[0]['id']);
    Assert::same('page', $children[0]['type']);

    $breadcrumb = $service->breadcrumb($child);
    Assert::same(2, count($breadcrumb));
    Assert::same($root, (int) $breadcrumb[0]['id']);
    Assert::same($child, (int) $breadcrumb[1]['id']);
});

Runner::test('Textseiten-Inhalte werden bereinigt', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $id = $service->create(['title' => 'Seite', 'type' => 'page', 'content' => '<h2>Titel</h2><script>alert(1)</script><p>Text</p>', 'active' => true]);

    $item = $service->find($id);
    Assert::false(str_contains((string) $item['content'], '<script'));
    Assert::true(str_contains((string) $item['content'], '<h2>Titel</h2>'));
    Assert::true(str_contains((string) $item['content'], '<p>Text</p>'));
});

Runner::test('Zirkuläre Verschachtelung wird verhindert', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $parent = $service->create(['title' => 'Eltern', 'type' => 'subpage', 'active' => true]);
    $child = $service->create(['title' => 'Kind', 'type' => 'subpage', 'parent_id' => $parent, 'active' => true]);

    try {
        $service->update($parent, ['title' => 'Eltern', 'type' => 'subpage', 'parent_id' => $child, 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['parent_id']));
    }
});

Runner::test('Nur Unterseiten koennen eine uebergeordnete Ebene sein', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $page = $service->create(['title' => 'Textseite', 'type' => 'page', 'active' => true]);

    try {
        $service->create(['title' => 'Kind', 'type' => 'page', 'parent_id' => $page, 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['parent_id']));
    }
});

Runner::test('Alarmierungen koennen einer Unterseite zugeordnet werden', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $root = $service->create(['title' => 'Bereich', 'type' => 'subpage', 'active' => true]);
    $alarm = $service->create([
        'title' => 'Alarm',
        'type' => 'alarm',
        'parent_id' => $root,
        'alarm_text' => 'Einsatzalarm',
        'alarm_group_id' => 1,
        'active' => true,
    ]);

    $children = $service->activeChildren($root);
    Assert::same(1, count($children));
    Assert::same($alarm, (int) $children[0]['id']);
    Assert::same('alarm', $children[0]['type']);

    $breadcrumb = $service->breadcrumb($alarm);
    Assert::same(2, count($breadcrumb));
    Assert::same($root, (int) $breadcrumb[0]['id']);
    Assert::same($alarm, (int) $breadcrumb[1]['id']);
});

Runner::test('Deckkraft wird auch als Integer akzeptiert', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    // Der Controller reicht background_opacity bereits als Integer durch (vgl. NavigationController::payload).
    $id = $service->create([
        'title' => 'Kachel',
        'type' => 'external',
        'url' => 'https://example.com',
        'override_background' => true,
        'background_opacity' => 97,
        'active' => true,
    ]);

    $item = $service->find($id);
    Assert::same(97, (int) $item['background_opacity']);
});

Runner::test('Ohne Opt-Out werden abweichende Kachelfarben verworfen', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $id = $service->create([
        'title' => 'Kachel',
        'type' => 'external',
        'url' => 'https://example.com',
        'background_color' => '#123456',
        'background_opacity' => 50,
        'active' => true,
    ]);

    $item = $service->find($id);
    Assert::same(0, (int) $item['override_background']);
    Assert::same(null, $item['background_color']);
    Assert::same(null, $item['background_opacity']);
});

Runner::test('Mit Opt-Out werden abweichende Kachelfarben übernommen', static function (): void {
    $service = navigationTestService(navigationTestPdo());

    $id = $service->create([
        'title' => 'Kachel',
        'type' => 'external',
        'url' => 'https://example.com',
        'override_background' => true,
        'background_color' => '#123456',
        'background_opacity' => 50,
        'active' => true,
    ]);

    $item = $service->find($id);
    Assert::same(1, (int) $item['override_background']);
    Assert::same('#123456', (string) $item['background_color']);
    Assert::same(50, (int) $item['background_opacity']);
});
