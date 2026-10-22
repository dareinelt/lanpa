<?php

declare(strict_types=1);

use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\NavigationIconRepository;
use App\Repositories\NavigationRepository;
use App\Services\NavigationIconService;
use App\Services\NavigationService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function navigationIconTestPdo(): PDO
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
            open_in_new_tab INTEGER NOT NULL DEFAULT 0,
            proxy_enabled INTEGER NOT NULL DEFAULT 0,
            proxy_bypass_networks TEXT NOT NULL DEFAULT \'\',
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
        'CREATE TABLE navigation_icons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alias VARCHAR(32) NOT NULL UNIQUE,
            file_name VARCHAR(120) NOT NULL,
            mime_type VARCHAR(64) NOT NULL DEFAULT \'image/svg+xml\',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

function navigationIconTestDir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lanpa-navicon-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o775, true);

    return $dir;
}

function navigationIconTestService(PDO $pdo, string $dir): NavigationIconService
{
    return new NavigationIconService(new NavigationIconRepository($pdo), $dir, 64 * 1024);
}

/**
 * @return array{name:string,tmp_name:string,size:int,error:int}
 */
function navigationIconTestUpload(string $contents, string $name = 'icon.svg'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lanpa-navicon-upload-');
    if ($tmp === false) {
        throw new RuntimeException('Temporäre Upload-Datei konnte nicht angelegt werden.');
    }
    file_put_contents($tmp, $contents);

    return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($contents), 'error' => UPLOAD_ERR_OK];
}

function navigationIconTestSvg(string $marker = '#123456'): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
        . '<path d="M3 3h18v18H3z" fill="' . $marker . '"/></svg>';
}

function navigationIconTestCleanup(string $dir): void
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

/**
 * @return array<string,string>
 */
function navigationIconTestErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    Assert::true(false);

    return [];
}

Runner::test('Icon-Katalog legt SVG unter einem Alias ab', static function (): void {
    $dir = navigationIconTestDir();
    $service = navigationIconTestService(navigationIconTestPdo(), $dir);
    $file = navigationIconTestUpload(navigationIconTestSvg());

    try {
        $id = $service->create('haus', $file);
    } finally {
        @unlink($file['tmp_name']);
    }

    try {
        $icons = $service->all();
        Assert::same(1, count($icons));
        Assert::same('haus', (string) $icons[0]['alias']);
        Assert::same($id, (int) $icons[0]['id']);
        Assert::same(['haus'], $service->aliases());
        Assert::true($service->exists('haus'));

        $stored = $service->fileByAlias('haus');
        Assert::true($stored !== null);
        Assert::same('image/svg+xml', $stored['mime']);
        Assert::true(is_file($stored['path']));
        Assert::true(str_contains((string) file_get_contents($stored['path']), 'fill="#123456"'));
    } finally {
        navigationIconTestCleanup($dir);
    }
});

Runner::test('Icon-Katalog lehnt aktive SVG-Inhalte ab', static function (): void {
    $dir = navigationIconTestDir();
    $service = navigationIconTestService(navigationIconTestPdo(), $dir);
    $file = navigationIconTestUpload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    try {
        $errors = navigationIconTestErrors(static fn () => $service->create('boese', $file));
    } finally {
        @unlink($file['tmp_name']);
    }

    try {
        Assert::true(isset($errors['svg']));
        Assert::same([], $service->all());
        Assert::same([], glob($dir . DIRECTORY_SEPARATOR . '*') ?: []);
    } finally {
        navigationIconTestCleanup($dir);
    }
});

Runner::test('Icon-Katalog prueft Alias und reservierte Standard-Icons', static function (): void {
    $dir = navigationIconTestDir();
    $service = navigationIconTestService(navigationIconTestPdo(), $dir);

    $file = navigationIconTestUpload(navigationIconTestSvg());
    try {
        Assert::true(isset(navigationIconTestErrors(static fn () => $service->create('', $file))['alias']));
        Assert::true(isset(navigationIconTestErrors(static fn () => $service->create('haus icon', $file))['alias']));
        Assert::true(isset(navigationIconTestErrors(static fn () => $service->create('mail', $file))['alias']));

        $service->create('Haus', $file);
        Assert::same(['haus'], $service->aliases());
        Assert::true(isset(navigationIconTestErrors(static fn () => $service->create('haus', $file))['alias']));
    } finally {
        @unlink($file['tmp_name']);
        navigationIconTestCleanup($dir);
    }
});

Runner::test('Umbenennen aktualisiert vorhandene Kacheln', static function (): void {
    $pdo = navigationIconTestPdo();
    $dir = navigationIconTestDir();
    $service = navigationIconTestService($pdo, $dir);
    $file = navigationIconTestUpload(navigationIconTestSvg());

    try {
        $id = $service->create('alt', $file);
    } finally {
        @unlink($file['tmp_name']);
    }

    try {
        $pdo->exec("INSERT INTO navigation_items (title, url, type, icon) VALUES ('Kachel', 'https://example.com', 'external', 'alt')");

        $service->rename($id, 'neu');

        Assert::same(['neu'], $service->aliases());
        $icon = $pdo->query('SELECT icon FROM navigation_items')->fetchColumn();
        Assert::same('neu', (string) $icon);

        $errors = navigationIconTestErrors(static fn () => $service->rename($id, 'mail'));
        Assert::true(isset($errors['alias']));
    } finally {
        navigationIconTestCleanup($dir);
    }
});

Runner::test('Loeschen ist gesperrt solange Kacheln den Alias verwenden', static function (): void {
    $pdo = navigationIconTestPdo();
    $dir = navigationIconTestDir();
    $service = navigationIconTestService($pdo, $dir);
    $file = navigationIconTestUpload(navigationIconTestSvg());

    try {
        $id = $service->create('haus', $file);
    } finally {
        @unlink($file['tmp_name']);
    }

    try {
        $pdo->exec("INSERT INTO navigation_items (title, url, type, icon) VALUES ('Kachel', 'https://example.com', 'external', 'haus')");

        $errors = navigationIconTestErrors(static fn () => $service->delete($id));
        Assert::true(isset($errors['icon']));
        Assert::same(1, count($service->all()));

        $pdo->exec('UPDATE navigation_items SET icon = NULL');
        $service->delete($id);

        Assert::same([], $service->all());
        Assert::same(null, $service->fileByAlias('haus'));
        Assert::same([], glob($dir . DIRECTORY_SEPARATOR . '*') ?: []);
    } finally {
        navigationIconTestCleanup($dir);
    }
});

Runner::test('Ersetzen tauscht die SVG-Datei aus', static function (): void {
    $dir = navigationIconTestDir();
    $service = navigationIconTestService(navigationIconTestPdo(), $dir);

    $first = navigationIconTestUpload(navigationIconTestSvg('#111111'));
    try {
        $id = $service->create('haus', $first);
    } finally {
        @unlink($first['tmp_name']);
    }

    try {
        $oldFile = (string) $service->find($id)['file_name'];
        $second = navigationIconTestUpload(navigationIconTestSvg('#222222'));
        try {
            $service->replaceFile($id, $second);
        } finally {
            @unlink($second['tmp_name']);
        }

        $newFile = (string) $service->find($id)['file_name'];
        Assert::true($newFile !== $oldFile);
        Assert::false(is_file($dir . DIRECTORY_SEPARATOR . $oldFile));
        Assert::true(str_contains((string) file_get_contents($dir . DIRECTORY_SEPARATOR . $newFile), '#222222'));
    } finally {
        navigationIconTestCleanup($dir);
    }
});

Runner::test('Icon-Dropdown der Kachel-Einstellung zeigt Standard- und Katalog-Icons', static function (): void {
    $item = [
        'id' => null,
        'title' => '',
        'url' => '',
        'type' => 'external',
        'parent_id' => null,
        'icon' => 'haus',
        'background_color' => '',
        'background_opacity' => null,
        'short_description' => '',
        'description' => '',
        'content' => '',
        'alarm_text' => '',
        'alarm_group_id' => null,
        'protected_access' => 0,
        'open_in_new_tab' => 0,
        'proxy_enabled' => 0,
        'proxy_bypass_networks' => '',
        'sort_order' => 1,
        'active' => 1,
    ];

    $html = View::render('admin.navigation.form', [
        'item' => $item,
        'errors' => [],
        'subpages' => [],
        'alarmGroups' => [],
        'knownTargets' => [],
        'customIcons' => ['haus', 'werkstatt'],
    ]);

    Assert::contains('Standard-Icons', $html, 'Die eingebauten Icons stehen im Dropdown.');
    Assert::contains('Eigene Icons (Icon-Katalog)', $html);
    Assert::contains('<option value="haus" selected>', $html, 'Das gewählte Katalog-Icon ist ausgewählt.');
    Assert::contains('<option value="werkstatt"', $html);
    Assert::contains('href="/admin/navigation/icons"', $html, 'Der Hinweis führt in den Icon-Katalog.');
});

Runner::test('Icon-Katalog-Ansicht bietet Hochladen, Ersetzen, Umbenennen und Loeschen', static function (): void {
    $html = View::render('admin.navigation.icons', [
        'icons' => [[
            'id' => 7,
            'alias' => 'haus',
            'file_name' => 'nav-icon-0123456789abcdef.svg',
            'mime_type' => 'image/svg+xml',
            'created_at' => '2026-01-01 10:00:00',
            'updated_at' => '2026-01-01 10:00:00',
        ]],
        'errors' => ['alias' => 'Dieser Alias ist bereits vergeben.'],
        'alias' => 'haus',
        'maxIconKb' => 256,
    ]);

    Assert::contains('action="/admin/navigation/icons"', $html);
    Assert::contains('enctype="multipart/form-data"', $html);
    Assert::contains('src="/kachel-icon?alias=haus"', $html, 'Die Vorschau kommt aus dem Auslieferungs-Endpunkt.');
    Assert::contains('action="/admin/navigation/icons/ersetzen"', $html);
    Assert::contains('action="/admin/navigation/icons/umbenennen"', $html);
    Assert::contains('action="/admin/navigation/icons/loeschen"', $html);
    Assert::contains('Dieser Alias ist bereits vergeben.', $html);
    Assert::contains('Maximal 256 KB', $html);
});

Runner::test('Navigation akzeptiert nur vorhandene Katalog-Icons', static function (): void {
    $pdo = navigationIconTestPdo();
    $dir = navigationIconTestDir();
    $iconService = navigationIconTestService($pdo, $dir);
    $navigation = new NavigationService(
        new NavigationRepository($pdo),
        null,
        new NavigationIconRepository($pdo)
    );

    try {
        $errors = navigationIconTestErrors(static fn () => $navigation->create([
            'title' => 'Kachel',
            'type' => 'external',
            'url' => 'https://example.com',
            'icon' => 'haus',
            'active' => true,
        ]));
        Assert::true(isset($errors['icon']));

        $file = navigationIconTestUpload(navigationIconTestSvg());
        try {
            $iconService->create('haus', $file);
        } finally {
            @unlink($file['tmp_name']);
        }

        $id = $navigation->create([
            'title' => 'Kachel',
            'type' => 'external',
            'url' => 'https://example.com',
            'icon' => 'haus',
            'active' => true,
        ]);
        Assert::same('haus', (string) $navigation->find($id)['icon']);

        $builtin = $navigation->create([
            'title' => 'Standard',
            'type' => 'external',
            'url' => 'https://example.com',
            'icon' => 'mail',
            'active' => true,
        ]);
        Assert::same('mail', (string) $navigation->find($builtin)['icon']);
    } finally {
        navigationIconTestCleanup($dir);
    }
});
