<?php

declare(strict_types=1);

use App\Repositories\PhonebookRepository;
use App\Services\PhonebookService;
use Tests\Support\Assert;
use Tests\Support\Runner;

function phonebookDirectoryPdo(): PDO
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
            personnel_number VARCHAR(64) NULL,
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

Runner::test('Telefonbuch-Personenauswahl liefert Kennungen für die Übernahme in KLINQ', static function (): void {
    $pdo = phonebookDirectoryPdo();
    $pdo->exec("INSERT INTO phonebook (external_id, samaccount_name, display_name, first_name, last_name, title, personnel_number, department, phone, email, active, visible) VALUES
        ('4d1f2c3b', 'mmustermann', 'Mustermann, Max', 'Max', 'Mustermann', 'IT-Support', '4711', 'Technik', '0123', 'max@example.de', 1, 1),
        ('5e2a3d4c', 'keinmail', 'Ohne Mail, Erika', 'Erika', 'Ohne Mail', 'Reinigung', '0815', 'Service', '0456', NULL, 1, 1)");

    $service = new PhonebookService(new PhonebookRepository($pdo));
    $items = $service->directory('Muster');

    Assert::same(1, count($items), 'Die Suche nach "Muster" trifft nur den passenden Eintrag.');
    Assert::same('mmustermann', $items[0]['username'], 'Der SamAccountName wird für die Zuordnung mitgeliefert.');
    Assert::same('4d1f2c3b', $items[0]['external_id'], 'Die objectGUID wird für die Zuordnung mitgeliefert.');
    Assert::same('IT-Support', $items[0]['title'], 'Die Position wird mitgeliefert.');
    Assert::same('4711', $items[0]['personnel_number'], 'Die Personalnummer wird mitgeliefert.');
    Assert::same('max@example.de', $items[0]['email'], 'Die E-Mail-Adresse wird mitgeliefert.');
    Assert::same('', $items[0]['source'], 'Bei nur einer Identitätsquelle bleibt die Quellenangabe leer.');

    // KLINQ sucht die Felder "Melder" und "Betroffener Mitarbeiter" auch nach Personalnummer.
    $byNumber = $service->directory('4711');
    Assert::same(1, count($byNumber), 'Die Personalnummer ist durchsuchbar.');
    Assert::same('mmustermann', $byNumber[0]['username']);
    Assert::same([], $service->directory('9999'), 'Unbekannte Personalnummern liefern keine Treffer.');

    // Personen ohne Postfach sind ebenfalls wählbar (z. B. Reinigung, Externe).
    $withoutMail = $service->directory('Erika');
    Assert::same(1, count($withoutMail), 'Auch Einträge ohne E-Mail-Adresse werden gefunden.');
    Assert::same('', $withoutMail[0]['email'], 'Einträge ohne Postfach haben keine E-Mail-Adresse.');
});

Runner::test('Telefonbuch-Personenauswahl liefert leere Trefferliste ohne Suchbegriff', static function (): void {
    $pdo = phonebookDirectoryPdo();
    $pdo->exec("INSERT INTO phonebook (external_id, display_name, last_name, email, active, visible) VALUES
        ('a1', 'Mustermann, Max', 'Mustermann', 'max@example.de', 1, 1)");

    $service = new PhonebookService(new PhonebookRepository($pdo));

    Assert::same([], $service->directory(''), 'Ohne Suchbegriff wird nicht das ganze Telefonbuch ausgeliefert.');
    Assert::same([], $service->directory('   '), 'Reine Leerzeichen gelten als leerer Suchbegriff.');
});

Runner::test('Einzelabruf für die Übernahme liefert nur aktive und sichtbare Einträge', static function (): void {
    $pdo = phonebookDirectoryPdo();
    $pdo->exec("INSERT INTO phonebook (id, external_id, samaccount_name, display_name, last_name, email, active, visible) VALUES
        (1, 'b1', 'aktiv', 'Aktiv, Anna', 'Aktiv', 'anna@example.de', 1, 1),
        (2, 'b2', 'versteckt', 'Versteckt, Vera', 'Versteckt', 'vera@example.de', 1, 0),
        (3, 'b3', 'inaktiv', 'Inaktiv, Ingo', 'Inaktiv', 'ingo@example.de', 0, 1)");

    $service = new PhonebookService(new PhonebookRepository($pdo));

    $entry = $service->directoryEntry(1);
    Assert::same('aktiv', $entry['username'] ?? null, 'Der aktive Eintrag wird geliefert.');
    Assert::same(null, $service->directoryEntry(2), 'Ausgeblendete Einträge werden nicht geliefert.');
    Assert::same(null, $service->directoryEntry(3), 'Inaktive Einträge werden nicht geliefert.');
    Assert::same(null, $service->directoryEntry(0), 'Ungültige IDs werden abgewiesen.');
});


Runner::test('SamAccountName-Suche ohne Quellenbindung bevorzugt die Hauptquelle', static function (): void {
    $pdo = phonebookDirectoryPdo();
    $pdo->exec("INSERT INTO phonebook (id, identity_source_id, external_id, samaccount_name, display_name, first_name, last_name, email, active) VALUES
        (1, 3, 'n1', 'mmuster', 'Muster, Max (Niederlassung)', 'Max', 'Muster', 'nord@example.de', 1),
        (2, 0, 'n2', 'mmuster', 'Muster, Max', 'Max', 'Muster', 'max@example.de', 1),
        (3, 0, 'n3', 'inaktiv', 'Inaktiv, Ingo', 'Ingo', 'Inaktiv', 'ingo@example.de', 0)");
    $repository = new PhonebookRepository($pdo);

    $row = $repository->findActiveBySamAccountNameAnySource('MMuster');
    Assert::same(2, (int) ($row['id'] ?? 0), 'Die Hauptquelle hat Vorrang, die Schreibweise ist egal.');
    Assert::same('max@example.de', $row['email'] ?? null);

    Assert::same(null, $repository->findActiveBySamAccountNameAnySource('inaktiv'), 'Inaktive Einträge zählen nicht.');
    Assert::same(null, $repository->findActiveBySamAccountNameAnySource('   '), 'Ohne Namen wird nicht gesucht.');
    Assert::same(null, $repository->findActiveBySamAccountNameAnySource('unbekannt'));
});
