<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\DashboardService;
use App\Services\MovementService;
use App\Services\ReportService;
use Tests\Support\DatabaseTestCase;

final class ReportServiceIntegrationTest extends DatabaseTestCase
{
    private int $userId;
    private int $employeeId;
    private int $parentLocationId;
    private int $childLocationId;
    private int $costCenterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO users (username, display_name, role_id, auth_source, is_active) SELECT 'report-tester', 'Report Tester', id, 'local', 1 FROM roles ORDER BY id LIMIT 1");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->loginAs('assetmanagement');

        $locations = $this->c->get(LocationRepository::class);
        $this->parentLocationId = $locations->create(['name' => 'Report-Gebäude', 'type' => 'building', 'full_path' => 'Report-Gebäude', 'depth' => 0, 'is_active' => 1]);
        $this->childLocationId = $locations->create(['name' => 'Report-Raum', 'type' => 'room', 'parent_id' => $this->parentLocationId, 'full_path' => 'Report-Gebäude / Report-Raum', 'depth' => 1, 'is_active' => 1]);
        $this->costCenterId = $this->c->get(CostCenterRepository::class)->create(['number' => '97001', 'description' => 'Report-KST', 'is_active' => 1]);
        $this->employeeId = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Report Nutzer', 'username' => 'rnutzer', 'personnel_number' => '97001', 'department' => 'Berichte', 'is_active' => 1]);
    }

    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $this->userId, 'username' => 'report-tester', 'display_name' => 'Report Tester', 'role' => $role]);
    }

    private function service(): ReportService
    {
        return $this->c->get(ReportService::class);
    }

    /** @return array<string,mixed> */
    private function createAsset(array $overrides = []): array
    {
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode('PC')['id'];
        $created = $this->c->get(AssetService::class)->create(array_merge([
            'asset_type_id' => $typeId, 'name' => 'Report-Gerät', 'location_id' => (string) $this->childLocationId,
            'cost_center_id' => (string) $this->costCenterId, 'purchase_price' => '100,00',
        ], $overrides));

        return $this->c->get(AssetRepository::class)->find((int) $created['id']);
    }

    // ------------------------------------------------------------------ Inventarliste

    public function testInventoryFiltersByLocationSubtree(): void
    {
        $inRoom = $this->createAsset(['name' => 'Im Raum']);
        $this->createAsset(['name' => 'Woanders', 'location_id' => '']);

        $rows = $this->service()->inventory(['location_id' => $this->parentLocationId]);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $this->assertContains((int) $inRoom['id'], $ids);
        $this->assertCount(1, $ids);
        $this->assertSame(1, $this->service()->inventoryCount(['location_id' => $this->parentLocationId]));
    }

    public function testInventoryDefaultsToActiveAssets(): void
    {
        $asset = $this->createAsset(['name' => 'Bald ausgemustert']);
        $this->pdo->prepare('UPDATE assets SET status_id = (SELECT id FROM asset_statuses WHERE code = :code) WHERE id = :id')
            ->execute(['code' => 'retired', 'id' => $asset['id']]);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->service()->inventory(['cost_center_id' => $this->costCenterId]));
        $this->assertFalse(in_array((int) $asset['id'], $ids, true), 'Standard: nur aktive Assets');

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->service()->inventory(['cost_center_id' => $this->costCenterId, 'status' => 'all']));
        $this->assertContains((int) $asset['id'], $ids);
    }

    public function testInventoryMissingFilter(): void
    {
        $withoutLocation = $this->createAsset(['name' => 'Ohne Standort', 'location_id' => '']);
        $this->createAsset(['name' => 'Mit Standort']);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->service()->inventory(['cost_center_id' => $this->costCenterId, 'missing' => 'location']));
        $this->assertSame([(int) $withoutLocation['id']], $ids);
    }

    public function testInventoryCsvContainsHeaderAndRows(): void
    {
        $asset = $this->createAsset(['name' => 'CSV;Gerät', 'serial_number' => '=CMD()']);
        $csv = $this->service()->inventoryCsv(['cost_center_id' => $this->costCenterId]);

        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBFInventarnummer;Bezeichnung;Assettyp;"));
        $this->assertStringContains($asset['inventory_number'] . ';"CSV;Gerät";PC / Endgerät;', $csv);
        $this->assertStringContains('"\'=CMD()"', $csv, 'Formel-Injektion wird entschärft');
        $this->assertStringContains(';97001;Report-KST;', $csv);
        $this->assertStringContains(';100,00;', $csv);
        $this->assertStringContains('Report-Gebäude / Report-Raum', $csv);
    }

    public function testInventoryCsvRequiresExportPermission(): void
    {
        $this->loginAs('lager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->inventoryCsv([]));

        $this->loginAs('readonly');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->inventoryCsv([]));
        $this->assertTrue(is_array($this->service()->inventory([])), 'Lesen bleibt erlaubt');
    }

    // ------------------------------------------------------------------ Bestandsbericht

    public function testStockAggregatesByTypeLocationAndCostCenter(): void
    {
        $before = $this->service()->stock();
        $typeBefore = $this->rowByKey($before['by_type'], 'code', 'PC');
        $inStockBefore = (int) $this->rowByKey($before['by_status'], 'code', 'in_stock')['total'];

        $this->createAsset(['purchase_price' => '250,50']);
        $this->createAsset(['purchase_price' => '49,50']);

        $after = $this->service()->stock();
        $typeAfter = $this->rowByKey($after['by_type'], 'code', 'PC');
        $this->assertSame((int) $typeBefore['total'] + 2, (int) $typeAfter['total']);
        $this->assertSame((int) $typeBefore['in_stock'] + 2, (int) $typeAfter['in_stock']);
        $this->assertSame(round((float) $typeBefore['purchase_value'] + 300.0, 2), round((float) $typeAfter['purchase_value'], 2));
        $this->assertSame($inStockBefore + 2, (int) $this->rowByKey($after['by_status'], 'code', 'in_stock')['total']);

        $location = $this->rowByKey($after['by_location'], 'id', $this->childLocationId);
        $this->assertNotNull($location);
        $this->assertSame(2, (int) $location['total']);

        $cc = $this->rowByKey($after['by_cost_center'], 'id', $this->costCenterId);
        $this->assertNotNull($cc);
        $this->assertSame(2, (int) $cc['total']);
        $this->assertSame('300.00', number_format((float) $cc['purchase_value'], 2, '.', ''));
    }

    public function testStockUnassignedCounts(): void
    {
        $before = $this->service()->stock()['unassigned'];
        $this->createAsset(['location_id' => '', 'cost_center_id' => '']);
        $after = $this->service()->stock()['unassigned'];

        $this->assertSame((int) $before['without_location'] + 1, (int) $after['without_location']);
        $this->assertSame((int) $before['without_cost_center'] + 1, (int) $after['without_cost_center']);
        $this->assertSame((int) $before['active_total'] + 1, (int) $after['active_total']);
    }

    public function testStockCsvSectionsAndInvalidSection(): void
    {
        $this->createAsset();
        $csv = $this->service()->stockCsv('type');
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBFAssettyp;Code;Gesamt;"));
        $this->assertStringContains('PC / Endgerät;PC;', $csv);

        $this->assertTrue(str_starts_with($this->service()->stockCsv('status'), "\xEF\xBB\xBFStatus;Code;Anzahl;Endgültig"));
        $this->assertStringContains('Report-Gebäude / Report-Raum;1;1;0;0', $this->service()->stockCsv('location'));
        $this->assertStringContains('97001;Report-KST;1;0;100,00', $this->service()->stockCsv('cost_center'));

        $this->assertThrows(ValidationException::class, fn () => $this->service()->stockCsv('foo'));
        $this->loginAs('lager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->stockCsv('type'));
    }

    // ------------------------------------------------------------------ Bewegungen

    public function testMovementFiltersDefaultsAndNormalisation(): void
    {
        $f = $this->service()->movementFilters('checkout', []);
        $this->assertSame('checkout', $f['type']);
        $this->assertSame(date('Y-m-d'), $f['date_to']);
        $this->assertSame(date('Y-m-d', strtotime('-' . ReportService::DEFAULT_PERIOD_DAYS . ' days')), $f['date_from']);
        $this->assertSame('', $f['status']);

        $f = $this->service()->movementFilters('return', ['date_from' => '2026-03-31', 'date_to' => '2026-01-01', 'status' => 'bogus', 'q' => '  abc ']);
        $this->assertSame('2026-01-01', $f['date_from'], 'Zeitraum wird bei vertauschten Grenzen gedreht');
        $this->assertSame('2026-03-31', $f['date_to']);
        $this->assertSame('', $f['status']);
        $this->assertSame('abc', $f['q']);

        $f = $this->service()->movementFilters('return', ['date_from' => 'kein-datum', 'status' => 'cancelled']);
        $this->assertSame(date('Y-m-d', strtotime('-' . ReportService::DEFAULT_PERIOD_DAYS . ' days')), $f['date_from']);
        $this->assertSame('cancelled', $f['status']);
    }

    public function testCheckoutAndReturnReportsAndCsv(): void
    {
        $asset = $this->createAsset(['name' => 'Bewegtes Gerät']);
        $movements = $this->c->get(MovementService::class);
        $checkout = $movements->checkout(['asset_id' => $asset['id'], 'employee_id' => $this->employeeId, 'location_id' => $this->childLocationId, 'cost_center_id' => $this->costCenterId]);
        $asset = $this->c->get(AssetRepository::class)->find((int) $asset['id']);
        $movements->returnAsset(['asset_id' => $asset['id'], 'condition_code' => 'damaged', 'has_damage' => '1', 'damage_description' => 'Display gebrochen', 'to_location_id' => $this->childLocationId, 'target_status_code' => 'repair', 'accessories_checked' => '1']);

        $co = $this->service()->movementFilters('checkout', ['employee_id' => $this->employeeId]);
        $rows = $this->service()->movements($co);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $checkout['id'], (int) $rows[0]['id']);
        $this->assertSame(1, $this->service()->movementsCount($co));

        $summary = $this->service()->movementSummary($co);
        $this->assertTrue(isset($summary['by_month'], $summary['conditions'], $summary['top_employees']));
        $top = $this->rowByKey($summary['top_employees'], 'id', $this->employeeId);
        $this->assertNotNull($top);
        $this->assertTrue((int) $top['total'] >= 1);
        $this->assertTrue((int) ($summary['conditions']['damaged'] ?? 0) >= 1);

        $csv = $this->service()->movementsCsv($co);
        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBFDatum;Uhrzeit;Typ;Status;Inventarnummer;"));
        $this->assertStringContains('Von Standort;Fehlende Angaben', $csv);
        $this->assertStringContains(';Entnahme;Abgeschlossen;' . $asset['inventory_number'] . ';Bewegtes Gerät;', $csv);
        $this->assertStringContains(';Report Nutzer;rnutzer;Berichte;97001;', $csv);

        $ret = $this->service()->movementFilters('return', ['q' => $asset['inventory_number']]);
        $rows = $this->service()->movements($ret);
        $this->assertCount(1, $rows);
        $this->assertSame('damaged', $rows[0]['condition_code']);
        $csv = $this->service()->movementsCsv($ret);
        $this->assertStringContains(';Zustand;Schaden;Schadensbeschreibung;Zubehör geprüft;Zubehör-Hinweis;Zielstatus;Dokumente', $csv);
        $this->assertStringContains(';Retoure;Abgeschlossen;' . $asset['inventory_number'], $csv);
        $this->assertStringContains(';Beschädigt;Ja;Display gebrochen;Ja;;Reparatur;0', $csv);

        $this->loginAs('lager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->movementsCsv($co));
    }

    // ------------------------------------------------------------------ Dashboard

    public function testDashboardStatsAndOpenTasks(): void
    {
        $dashboard = $this->c->get(DashboardService::class);
        $stats = $dashboard->stats();
        foreach (['assets_total', 'assets_in_stock', 'assets_issued', 'assets_defective', 'assets_repair', 'open_checkouts', 'open_returns', 'returns_overdue', 'orders_open', 'deliveries_expected', 'deliveries_overdue', 'licenses_expiring'] as $key) {
            $this->assertTrue(array_key_exists($key, $stats), "Kennzahl $key fehlt");
        }

        $tasks = $dashboard->openTasks(['open_checkouts' => 3, 'assets_defective' => 1, 'deliveries_overdue' => 2, 'licenses_expiring' => 0], static fn (string $p): bool => true);
        $this->assertCount(3, $tasks);
        $this->assertSame('Offene Entnahmen', $tasks[0]['label']);
        $this->assertSame(3, $tasks[0]['count']);
        $this->assertSame('warning', $tasks[0]['level']);
        $this->assertSame('/orders?status=overdue', $tasks[2]['href']);

        $restricted = $dashboard->openTasks(['open_checkouts' => 3, 'assets_defective' => 1], static fn (string $p): bool => $p === 'assets.view');
        $this->assertCount(1, $restricted);
        $this->assertSame('Defekte Assets', $restricted[0]['label']);

        $this->assertSame([], $dashboard->openTasks([], static fn (string $p): bool => true));
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed>|null */
    private function rowByKey(array $rows, string $key, mixed $value): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row[$key] === (string) $value) {
                return $row;
            }
        }

        return null;
    }
}
