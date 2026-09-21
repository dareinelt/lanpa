<?php

declare(strict_types=1);

use App\Exceptions\ValidationException;
use App\Repositories\ActivationNumberRepository;
use App\Repositories\AlarmGroupRepository;
use App\Services\ActivationNumberService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function activationNumberPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
        'CREATE TABLE activation_numbers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            phone VARCHAR(64) NOT NULL,
            phone_digits VARCHAR(64) NOT NULL UNIQUE,
            alarm_group_id INTEGER NULL,
            sort_order INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $pdo->exec("INSERT INTO alarm_groups (group_number, description) VALUES ('10', 'Einsatzgruppe')");

    return $pdo;
}

function activationNumberService(PDO $pdo): ActivationNumberService
{
    return new ActivationNumberService(
        new ActivationNumberRepository($pdo),
        new AlarmGroupRepository($pdo)
    );
}

Runner::test('Rufnummer wird angelegt und eindeutig validiert', static function (): void {
    $pdo = activationNumberPdo();
    $service = activationNumberService($pdo);

    $id = $service->create(['phone' => '+49 170 1234567', 'alarm_group_id' => 1, 'active' => true]);

    $item = $service->find($id);
    Assert::same('491701234567', (string) $item['phone_digits']);

    try {
        $service->create(['phone' => '+49 170 1234567', 'alarm_group_id' => 1, 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['phone']));
    }
});

Runner::test('Status wird umgeschaltet', static function (): void {
    $pdo = activationNumberPdo();
    $service = activationNumberService($pdo);

    $id = $service->create(['phone' => '+49 170 1234567', 'alarm_group_id' => 1, 'active' => true]);

    $service->toggle($id);
    Assert::same(0, (int) $service->find($id)['active']);

    $service->toggle($id);
    Assert::same(1, (int) $service->find($id)['active']);
});

Runner::test('Ungültige Gruppe wird abgelehnt', static function (): void {
    $pdo = activationNumberPdo();
    $service = activationNumberService($pdo);

    try {
        $service->create(['phone' => '+49 170 1234567', 'alarm_group_id' => 0, 'active' => true]);
        Assert::true(false);
    } catch (ValidationException $exception) {
        Assert::true(isset($exception->errors()['alarm_group_id']));
    }
});
