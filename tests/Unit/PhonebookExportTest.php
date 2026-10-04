<?php

declare(strict_types=1);

use App\Repositories\PhonebookRepository;
use App\Services\PhonebookService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function phonebookExportPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE phonebook (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id VARCHAR(128) NULL UNIQUE,
            identity_source_id INTEGER NOT NULL DEFAULT 0,
            samaccount_name VARCHAR(64) NULL,
            display_name VARCHAR(120) NOT NULL DEFAULT \'\',
            first_name VARCHAR(64) NULL,
            last_name VARCHAR(64) NULL,
            title VARCHAR(120) NULL,
            phone VARCHAR(40) NULL,
            phone_digits VARCHAR(40) NULL,
            mobile VARCHAR(40) NULL,
            email VARCHAR(120) NULL,
            department VARCHAR(120) NULL,
            ad_modified VARCHAR(32) NULL,
            synced_at TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            visible INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $pdo;
}

Runner::test('CSV-Export enthält nur für Besucher sichtbare Einträge', static function (): void {
    $pdo = phonebookExportPdo();
    $pdo->exec("INSERT INTO phonebook (external_id, display_name, last_name, department, phone, email, active, visible) VALUES
        ('e1', 'Max Mustermann', 'Mustermann', 'Technik', '0123', 'max@example.de', 1, 1),
        ('e2', 'Ohne E-Mail', 'Keinmail', 'Service', '0456', NULL, 1, 1),
        ('e3', 'Ausgeblendet', 'Versteckt', 'Verwaltung', '0789', 'hidden@example.de', 1, 0),
        ('e4', 'Inaktiv', 'Ruht', 'Technik', '0321', 'inaktiv@example.de', 0, 1)");

    $service = new PhonebookService(new PhonebookRepository($pdo));
    $csv = $service->csvExport();

    Assert::true(str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV sollte mit einem UTF-8-BOM beginnen.');
    Assert::contains('Name;Bereich;Telefonnummer;Mail-Adresse', $csv);
    Assert::contains('"Max Mustermann";"Technik";"0123";"max@example.de"', $csv);
    Assert::false(str_contains($csv, 'Ohne E-Mail'), 'Einträge ohne E-Mail sind für Besucher nicht sichtbar.');
    Assert::false(str_contains($csv, 'Ausgeblendet'), 'Ausgeblendete Einträge werden nicht exportiert.');
    Assert::false(str_contains($csv, 'Inaktiv'), 'Inaktive Einträge werden nicht exportiert.');
});

Runner::test('CSV-Export maskiert Anführungszeichen und Semikolon', static function (): void {
    $pdo = phonebookExportPdo();
    $pdo->exec("INSERT INTO phonebook (external_id, display_name, last_name, department, phone, email, active, visible) VALUES
        ('q1', 'Müller, \"Max\"', 'Müller', 'Technik;Labor', '0123', 'max@example.de', 1, 1)");

    $service = new PhonebookService(new PhonebookRepository($pdo));
    $csv = $service->csvExport();

    Assert::contains('"Müller, ""Max"""', $csv);
    Assert::contains('"Technik;Labor"', $csv);
});
