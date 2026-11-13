<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Repositories\AuditLogRepository;
use App\Repositories\EmployeeRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\AuditLogService;
use App\Services\EmployeeService;
use App\Services\Helpdesk\Ai\AiTransportInterface;
use App\Services\Lanpa\LanpaBridge;
use App\Services\PersonDirectoryService;
use PDO;
use Tests\Support\TestCase;

/**
 * Personenauswahl fuer Melder und Betroffene: Treffer aus dem Intranet-
 * Telefonbuch, Abbildung auf lokale Mitarbeiter und Uebernahme unbekannter
 * Personen beim Auswaehlen.
 */
final class PersonDirectoryServiceTest extends TestCase
{
    private PDO $pdo;

    private PersonDirectoryTransport $transport;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE employees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ad_object_guid TEXT NULL,
                username TEXT NULL,
                first_name TEXT NOT NULL DEFAULT \'\',
                last_name TEXT NOT NULL DEFAULT \'\',
                display_name TEXT NOT NULL,
                email TEXT NULL,
                personnel_number TEXT NULL,
                department TEXT NULL,
                position TEXT NULL,
                phone TEXT NULL,
                ad_location TEXT NULL,
                ad_cost_center TEXT NULL,
                location_id INTEGER NULL,
                cost_center_id INTEGER NULL,
                source TEXT NOT NULL DEFAULT \'manual\',
                is_active INTEGER NOT NULL DEFAULT 1,
                deactivated_at TEXT NULL,
                last_synced_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                username TEXT NULL,
                action TEXT NOT NULL,
                object_type TEXT NOT NULL,
                object_id INTEGER NULL,
                object_label TEXT NULL,
                old_data TEXT NULL,
                new_data TEXT NULL,
                ip_address TEXT NULL
            )'
        );

        $this->pdo->exec(
            "INSERT INTO employees (display_name, username, department, personnel_number, is_active)
             VALUES ('Max Mustermann', 'mmustermann', 'IT', '4711', 1)"
        );
        $this->pdo->exec(
            "INSERT INTO employees (display_name, username, department, is_active)
             VALUES ('Erika Muster', 'emuster', 'Verwaltung', 1)"
        );

        $this->transport = new PersonDirectoryTransport();
    }

    public function testSearchMapsPhonebookHitsToLocalEmployees(): void
    {
        $this->transport->items = [
            [
                'id' => 11,
                'external_id' => '4d1f2c3b5e2a3d4c8f9a0b1c2d3e4f5a',
                'username' => 'mmustermann',
                'display_name' => 'Mustermann, Max',
                'first_name' => 'Max',
                'last_name' => 'Mustermann',
                'title' => '',
                'personnel_number' => '4711',
                'phone' => '',
                'mobile' => '',
                'email' => '',
                'department' => 'Technik',
                'source' => '',
            ],
            [
                'id' => 12,
                'external_id' => '',
                'username' => 'nneu',
                'display_name' => 'Neu, Nina',
                'first_name' => 'Nina',
                'last_name' => 'Neu',
                'title' => '',
                'personnel_number' => '',
                'phone' => '',
                'mobile' => '',
                'email' => '',
                'department' => 'Service',
                'source' => 'Niederlassung Nord',
            ],
        ];

        $items = $this->service()->search('Mus');

        $this->assertCount(3, $items, 'Telefonbuchtreffer zuerst, lokale Ergaenzungen danach.');
        $this->assertSame(1, $items[0]['id'], 'Bekannte Person wird auf den lokalen Datensatz abgebildet.');
        $this->assertSame(0, $items[0]['ref'], 'Bekannte Personen brauchen keine Uebernahme.');
        $this->assertSame('Mustermann, Max', $items[0]['name']);
        $this->assertSame('Technik · mmustermann', $items[0]['meta']);
        $this->assertSame(0, $items[1]['id'], 'Unbekannte Person hat noch keinen lokalen Datensatz.');
        $this->assertSame(12, $items[1]['ref']);
        $this->assertSame('Service · Niederlassung Nord · nneu', $items[1]['meta']);
        $this->assertSame(2, $items[2]['id'], 'Nur lokal bekannte Personen bleiben waehlbar.');
        $this->assertSame(0, $items[2]['ref']);
    }

    public function testSearchWithoutTermOrIntranetUsesLocalEmployeesOnly(): void
    {
        $this->assertSame([], $this->service()->search('   '));
        $this->assertSame(0, $this->transport->calls, 'Ohne Suchbegriff darf keine Anfrage rausgehen');

        $items = $this->service(intranet: false)->search('Muster');

        $this->assertCount(2, $items);
        $this->assertSame(0, $this->transport->calls);
        $this->assertSame(0, $items[0]['ref']);
    }

    public function testAdoptCreatesALocalEmployeeFromThePhonebookEntry(): void
    {
        $this->transport->entry = [
            'id' => 12,
            'external_id' => '4d1f2c3b5e2a3d4c8f9a0b1c2d3e4f5a',
            'username' => 'nneu',
            'display_name' => 'Neu, Nina',
            'first_name' => 'Nina',
            'last_name' => 'Neu',
            'title' => 'Reinigungskraft',
            'personnel_number' => '4712',
            'phone' => '0456 99',
            'mobile' => '0170 1234567',
            'email' => 'nina.neu@example.local',
            'department' => 'Service',
            'source' => 'Niederlassung Nord',
        ];

        $id = $this->service()->adopt(12);

        $this->assertNotNull($id);
        $this->assertSame('http://intranet-app/internal/klinq/telefonbuch?id=12', $this->transport->url);
        $row = $this->employee($id);
        $this->assertSame('manual', $row['source']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertSame('4d1f2c3b-5e2a-3d4c-8f9a-0b1c2d3e4f5a', $row['ad_object_guid']);
        $this->assertSame('Neu, Nina', $row['display_name']);
        $this->assertSame('Reinigungskraft', $row['position']);
        $this->assertSame('4712', $row['personnel_number'], 'Die Personalnummer kommt aus dem Intranet.');
        $this->assertSame('0456 99', $row['phone'], 'Die Telefonnummer hat Vorrang vor der Mobilnummer.');
        $this->assertSame('nina.neu@example.local', $row['email']);

        $this->assertSame($id, $this->service()->adopt(12), 'Zweite Auswahl nutzt den vorhandenen Datensatz.');
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
        $audit = $this->pdo->query('SELECT action, object_type, object_id FROM audit_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['action' => 'create', 'object_type' => 'employee', 'object_id' => $id], $audit);
    }

    public function testAdoptRejectsUnknownPhonebookEntries(): void
    {
        $this->transport->entry = null;

        $this->assertNull($this->service()->adopt(12));
        $this->assertNull($this->service()->adopt(0));
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
    }

    public function testAdoptByEmailTakesOverTheMatchingPhonebookEntry(): void
    {
        $this->transport->entry = [
            'id' => 13, 'external_id' => '', 'username' => 'cfinke', 'display_name' => 'Finke, Claudine',
            'first_name' => 'Claudine', 'last_name' => 'Finke', 'title' => '', 'personnel_number' => '',
            'phone' => '0531 123', 'mobile' => '', 'email' => 'Claudine.Finke@example.local',
            'department' => 'Station 3', 'source' => '',
        ];

        $id = $this->service()->adoptByEmail(' claudine.finke@EXAMPLE.local ');

        $this->assertNotNull($id);
        $this->assertSame('http://intranet-app/internal/klinq/telefonbuch?email=claudine.finke%40example.local', $this->transport->url);
        $row = $this->employee($id);
        $this->assertSame('Finke, Claudine', $row['display_name']);
        $this->assertSame('Station 3', $row['department']);
        $this->assertSame('0531 123', $row['phone']);

        // Antwortet das Intranet mit einer anderen Adresse, gilt das nicht als Treffer.
        $this->transport->entry['email'] = 'andere@example.local';
        $this->assertNull($this->service()->adoptByEmail('neu@example.local'));
        $calls = $this->transport->calls;
        $this->assertNull($this->service()->adoptByEmail('keine-mail'));
        $this->assertSame($calls, $this->transport->calls, 'Ungültige Adressen lösen keine Anfrage aus.');
    }

    public function testCreateContactKeepsThePersonInTheSelection(): void
    {
        $contact = $this->service()->createContact('Anrufer, Anna', 'Anna.Anrufer@Example.Local', '0170 555', true);

        $row = $this->employee($contact['id']);
        $this->assertSame('Anrufer, Anna', $row['display_name']);
        $this->assertSame('anna.anrufer@example.local', $row['email']);
        $this->assertSame('0170 555', $row['phone'], 'Die Rueckrufnummer landet in der Telefonnummer.');
        $this->assertSame('manual', $row['source']);
        $this->assertSame(1, (int) $row['is_active']);
        $this->assertNull($row['deactivated_at']);
        $this->assertSame('anna.anrufer@example.local · 0170 555', $contact['meta']);

        $items = $this->service()->search('anna.anrufer@example.local');
        $this->assertCount(1, $items);
        $this->assertSame($contact['id'], $items[0]['id'], 'Der Kontakt ist ohne Verzeichnistreffer waehlbar.');
        $this->assertSame(0, $items[0]['ref']);
        $this->assertSame('anna.anrufer@example.local · 0170 555', $items[0]['meta'], 'Ohne Verzeichnisdaten zeigt die Zeile E-Mail und Rueckrufnummer.');

        $byPhone = $this->service()->search('0170 555');
        $this->assertCount(1, $byPhone);
        $this->assertSame($contact['id'], $byPhone[0]['id'], 'Kontakte sind auch ueber die Rueckrufnummer auffindbar.');

        $audit = $this->pdo->query('SELECT action, object_type, object_id FROM audit_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['action' => 'create', 'object_type' => 'employee', 'object_id' => $contact['id']], $audit);
    }

    public function testCreateContactWithoutKeepIsOnlyForTheCurrentTicket(): void
    {
        $contact = $this->service()->createContact('Externer Anrufer', '', '0451 1234', false);

        $row = $this->employee($contact['id']);
        $this->assertSame(0, (int) $row['is_active'], 'Ohne Kontakt-Option bleibt der Datensatz inaktiv.');
        $this->assertNotNull($row['deactivated_at']);
        $this->assertNull($row['email']);
        $this->assertSame('0451 1234', $row['phone']);
        $this->assertSame('0451 1234', $contact['meta']);
        $this->assertSame([], $this->service()->search('Externer'), 'Inaktive Kontakte erscheinen nicht in der Auswahl.');
    }

    public function testCreateContactReusesAnExistingContactByEmail(): void
    {
        $service = $this->service();
        $first = $service->createContact('Anrufer, Anna', 'anna@example.local', '0170 555', false);
        $second = $service->createContact('Anrufer, Anna', 'anna@example.local', '0170 555', true);

        $this->assertSame($first['id'], $second['id'], 'Gleiche E-Mail bedeutet gleiche Person.');
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
        $row = $this->employee($second['id']);
        $this->assertSame(1, (int) $row['is_active'], 'Mit Kontakt-Option wird der Datensatz wieder aktiv.');
        $this->assertNull($row['deactivated_at']);
        $actions = $this->pdo->query('SELECT action FROM audit_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['create', 'activate'], $actions);
    }

    public function testCreateContactDoesNotReactivateADeactivatedEmployee(): void
    {
        $this->pdo->exec(
            "INSERT INTO employees (display_name, first_name, last_name, email, source, is_active, deactivated_at)
             VALUES ('Ausgeschieden, Paul', 'Paul', 'Ausgeschieden', 'paul@example.local', 'manual', 0, '2024-01-01 00:00:00')"
        );
        $id = (int) $this->pdo->lastInsertId();

        $contact = $this->service()->createContact('Paul', 'paul@example.local', '', true);

        $this->assertSame($id, $contact['id'], 'Die E-Mail verweist auf den vorhandenen Mitarbeiter.');
        $row = $this->employee($id);
        $this->assertSame(0, (int) $row['is_active'], 'Ein bewusst deaktivierter Mitarbeiter bleibt inaktiv.');
        $this->assertNotNull($row['deactivated_at']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn());
    }

    public function testCreateContactReusesAPhoneOnlyContactByNameAndNumber(): void
    {
        $service = $this->service();
        $first = $service->createContact('Externer Anrufer', '', '0451 1234', false);
        $second = $service->createContact('Externer Anrufer', '', '0451/1234', true);
        $other = $service->createContact('Anderer Anrufer', '', '0451 1234', false);

        $this->assertSame($first['id'], $second['id'], 'Gleicher Name und gleiche Rufnummer bedeuten gleiche Person.');
        $this->assertTrue($first['id'] !== $other['id'], 'Anderer Name ergibt einen eigenen Kontakt.');
        $this->assertSame(1, (int) $this->employee($first['id'])['is_active']);
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
    }

    public function testCreateContactValidatesTheInput(): void
    {
        $service = $this->service();

        $this->assertThrows(ValidationException::class, static fn () => $service->createContact('  ', 'a@example.local', '', true), 'Namen');
        $this->assertThrows(ValidationException::class, static fn () => $service->createContact('Anrufer', 'keine-mail', '', true), 'E-Mail');
        $this->assertThrows(ValidationException::class, static fn () => $service->createContact('Anrufer', '', '  ', true), 'Rückrufnummer');
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn());
    }

    private function service(bool $intranet = true): PersonDirectoryService
    {
        $config = new Config(dirname(__DIR__, 2) . '/config');
        $audit = new AuditLogService(new AuditLogRepository($this->pdo), new CurrentUser(new Permissions($config)));
        $employees = new EmployeeRepository($this->pdo);
        $bridge = new LanpaBridge(Config::fromArray(['lanpa' => [
            'enabled' => $intranet,
            'internal_url' => 'http://intranet-app',
            'service_token' => 'dienst-token',
            'design_css' => '',
        ]]), $this->transport);

        return new PersonDirectoryService($bridge, $employees, new EmployeeService($employees, $audit), $audit);
    }

    /** @return array<string,mixed> */
    private function employee(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM employees WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(is_array($row), 'Der uebernommene Mitarbeiter wurde nicht angelegt.');

        return $row;
    }
}

/** Liefert je nach Anfrage die Suchtrefferliste oder einen Einzeleintrag. */
final class PersonDirectoryTransport implements AiTransportInterface
{
    public int $calls = 0;

    public string $url = '';

    /** @var list<array<string,mixed>> */
    public array $items = [];

    /** @var array<string,mixed>|null */
    public ?array $entry = null;

    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->calls++;
        $this->url = $url;
        if (str_contains($url, 'id=') || str_contains($url, 'email=')) {
            return [
                'status' => $this->entry === null ? 404 : 200,
                'body' => $this->entry === null ? '' : (string) json_encode($this->entry),
                'error' => null,
            ];
        }

        return [
            'status' => 200,
            'body' => (string) json_encode(['items' => $this->items]),
            'error' => null,
        ];
    }
}
