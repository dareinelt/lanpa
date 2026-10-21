<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
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
        if (str_contains($url, 'id=')) {
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
