<?php

declare(strict_types=1);

use App\Repositories\NavigationRepository;
use App\Services\NavigationService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function permissionsPdo(): PDO
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
            type VARCHAR(16) NOT NULL DEFAULT \'group\',
            sort_order INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec(
        'CREATE TABLE navigation_item_permissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            navigation_id INTEGER NOT NULL,
            identity_type TEXT NOT NULL,
            user_id INTEGER NULL,
            group_name TEXT NULL
        )'
    );

    return $pdo;
}

function permissionsService(PDO $pdo): NavigationService
{
    return new NavigationService(new NavigationRepository($pdo));
}

/**
 * @return list<int>
 */
function tileIds(array $items): array
{
    $ids = [];
    foreach ($items as $item) {
        $ids[] = (int) $item['id'];
    }

    return $ids;
}

Runner::test('Berechtigungsloser Benutzer sieht dasselbe wie ein anonymer Nutzer', static function (): void {
    $service = permissionsService(permissionsPdo());

    $oeffentlich = $service->create(['title' => 'Oeffentlich', 'type' => 'external', 'url' => 'https://example.com', 'active' => true]);
    $intern = $service->create(['title' => 'Intern', 'type' => 'external', 'url' => 'https://intranet.example.com', 'active' => true]);
    // "Intern" wird eingeschraenkt; "Oeffentlich" bleibt global sichtbar.
    $service->assignPermissions($intern, [5], []);

    $anonym = $service->activeTopLevelFor(null);
    $ohneRechte = $service->activeTopLevelFor(['id' => 9, 'username' => 'u9', 'display_name' => 'U9', 'groups' => []]);

    Assert::same([$oeffentlich], tileIds($anonym));
    Assert::same(tileIds($anonym), tileIds($ohneRechte));
});

Runner::test('Zugewiesene Kachel ist nur fuer den berechtigten Benutzer sichtbar', static function (): void {
    $service = permissionsService(permissionsPdo());

    $service->create(['title' => 'Oeffentlich', 'type' => 'external', 'url' => 'https://example.com', 'active' => true]);
    $intern = $service->create(['title' => 'Intern', 'type' => 'external', 'url' => 'https://intranet.example.com', 'active' => true]);
    $service->assignPermissions($intern, [7], []);

    $erlaubt = $service->activeTopLevelFor(['id' => 7, 'username' => 'u7', 'display_name' => 'U7', 'groups' => []]);
    $verboten = $service->activeTopLevelFor(['id' => 8, 'username' => 'u8', 'display_name' => 'U8', 'groups' => []]);

    Assert::same(2, count($erlaubt));
    Assert::same(1, count($verboten));
    Assert::false(in_array($intern, tileIds($verboten), true));
});

Runner::test('Gruppenberechtigungen schalten Kacheln fuer Mitglieder frei', static function (): void {
    $service = permissionsService(permissionsPdo());

    $service->create(['title' => 'Oeffentlich', 'type' => 'external', 'url' => 'https://example.com', 'active' => true]);
    $intern = $service->create(['title' => 'Intern', 'type' => 'external', 'url' => 'https://intranet.example.com', 'active' => true]);
    $service->assignPermissions($intern, [], ['Verwaltung']);

    $mitglied = $service->activeTopLevelFor(['id' => 3, 'username' => 'u3', 'display_name' => 'U3', 'groups' => ['verwaltung']]);
    $fremd = $service->activeTopLevelFor(['id' => 4, 'username' => 'u4', 'display_name' => 'U4', 'groups' => ['it']]);

    Assert::true(in_array($intern, tileIds($mitglied), true));
    Assert::false(in_array($intern, tileIds($fremd), true));
});

Runner::test('isAccessible sperrt nicht berechtigte Elemente serverseitig', static function (): void {
    $service = permissionsService(permissionsPdo());

    $intern = $service->create(['title' => 'Intern', 'type' => 'page', 'content' => '<p>Geheim</p>', 'active' => true]);
    $service->assignPermissions($intern, [11], []);

    Assert::true($service->isAccessible($intern, ['id' => 11, 'username' => 'u11', 'display_name' => 'U11', 'groups' => []]));
    Assert::false($service->isAccessible($intern, ['id' => 12, 'username' => 'u12', 'display_name' => 'U12', 'groups' => []]));
    Assert::false($service->isAccessible($intern, null));
});

Runner::test('Erneutes Speichern ersetzt bestehende Berechtigungen', static function (): void {
    $service = permissionsService(permissionsPdo());

    $tile = $service->create(['title' => 'Kachel', 'type' => 'external', 'url' => 'https://example.com', 'active' => true]);
    $service->assignPermissions($tile, [1, 2], ['Alt']);

    $service->assignPermissions($tile, [2], ['Neu', 'Noch eine']);

    $permissions = $service->permissions($tile);
    Assert::same([2], $permissions['user_ids']);
    Assert::same(['Neu', 'Noch eine'], $permissions['group_names']);
});
