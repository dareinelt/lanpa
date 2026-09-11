<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\AdminUserRepository;
use App\Services\AdminUserService;
use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Baut eine In-Memory-SQLite-Datenbank mit dem admin_users-Schema auf,
 * damit Repository und Service ohne MySQL getestet werden koennen.
 */
function adminUsersTestPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(16) NOT NULL DEFAULT "admin",
            active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

function adminUsersTestService(PDO $pdo): AdminUserService
{
    return new AdminUserService(new AdminUserRepository($pdo));
}

Runner::test('Benutzer werden mit Rolle angelegt', static function (): void {
    $service = adminUsersTestService(adminUsersTestPdo());

    $id = $service->create(['username' => 'redakteur', 'role' => 'redaktion', 'password' => 'sicheres-passwort123', 'active' => true]);
    $item = $service->find($id);

    Assert::same('redakteur', (string) $item['username']);
    Assert::same('redaktion', (string) $item['role']);
    Assert::same(1, (int) $item['active']);
});

Runner::test('Ungueltige Eingaben werden fuer Benutzer abgewiesen', static function (): void {
    $service = adminUsersTestService(adminUsersTestPdo());

    try {
        $service->create(['username' => 'a', 'role' => 'unbekannt', 'password' => 'zukurz', 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['username']));
        Assert::true(isset($exception->errors()['role']));
        Assert::true(isset($exception->errors()['password']));
    }
});

Runner::test('Benutzernamen muessen eindeutig sein', static function (): void {
    $service = adminUsersTestService(adminUsersTestPdo());

    $service->create(['username' => 'admin', 'role' => 'admin', 'password' => 'sicheres-passwort123', 'active' => true]);

    try {
        $service->create(['username' => 'admin', 'role' => 'redaktion', 'password' => 'sicheres-passwort123', 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['username']));
    }
});

Runner::test('Der letzte aktive Administrator kann nicht gelöscht oder deaktiviert werden', static function (): void {
    $service = adminUsersTestService(adminUsersTestPdo());

    $adminId = $service->create(['username' => 'admin', 'role' => 'admin', 'password' => 'sicheres-passwort123', 'active' => true]);

    try {
        $service->delete($adminId);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true($exception->errors() !== []);
    }

    try {
        $service->toggle($adminId);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true($exception->errors() !== []);
    }
});

Runner::test('Ein weiterer Administrator kann geloescht werden', static function (): void {
    $service = adminUsersTestService(adminUsersTestPdo());

    $service->create(['username' => 'admin1', 'role' => 'admin', 'password' => 'sicheres-passwort123', 'active' => true]);
    $secondId = $service->create(['username' => 'admin2', 'role' => 'admin', 'password' => 'sicheres-passwort123', 'active' => true]);

    $service->delete($secondId);

    Assert::null($service->find($secondId));
});

Runner::test('Passwort bleibt beim Bearbeiten ohne Eingabe unveraendert', static function (): void {
    $pdo = adminUsersTestPdo();
    $repository = new AdminUserRepository($pdo);
    $service = new AdminUserService($repository);

    $id = $service->create(['username' => 'redakteur', 'role' => 'redaktion', 'password' => 'erstes-passwort123', 'active' => true]);
    $before = $repository->findActiveByUsername('redakteur');

    $service->update($id, ['username' => 'redakteur', 'role' => 'redaktion', 'password' => '', 'active' => true]);
    $after = $repository->findActiveByUsername('redakteur');

    Assert::same((string) $before['password_hash'], (string) $after['password_hash']);
});
