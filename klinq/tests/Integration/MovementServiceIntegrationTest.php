<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\MovementService;
use Tests\Support\DatabaseTestCase;

final class MovementServiceIntegrationTest extends DatabaseTestCase
{
    private int $employeeId;
    private int $employeeWithCcId;
    private int $stockLocationId;
    private int $officeLocationId;
    private int $costCenterId;
    private int $employeeCostCenterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('assetmanagement');
        $this->stockLocationId = $this->c->get(LocationRepository::class)->create(['name' => 'Testlager', 'type' => 'room', 'full_path' => 'Testlager', 'depth' => 0, 'is_active' => 1]);
        $this->officeLocationId = $this->c->get(LocationRepository::class)->create(['name' => 'Testbüro', 'type' => 'room', 'full_path' => 'Testbüro', 'depth' => 0, 'is_active' => 1]);
        $this->costCenterId = $this->c->get(CostCenterRepository::class)->create(['number' => 'T-100', 'description' => 'Test KST', 'is_active' => 1]);
        $this->employeeCostCenterId = $this->c->get(CostCenterRepository::class)->create(['number' => 'T-200', 'description' => 'Mitarbeiter KST', 'is_active' => 1]);
        $this->employeeId = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Bewegung Tester', 'username' => 'btester', 'is_active' => 1]);
        $this->employeeWithCcId = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'KST Tester', 'username' => 'ktester', 'is_active' => 1, 'cost_center_id' => $this->employeeCostCenterId]);
    }

    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => 1, 'username' => 'tester', 'display_name' => 'Test User', 'role' => $role]);
    }

    private function service(): MovementService
    {
        return $this->c->get(MovementService::class);
    }

    /** @return array<string,mixed> */
    private function createAsset(array $overrides = []): array
    {
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode('PC')['id'];
        $created = $this->c->get(AssetService::class)->create(array_merge(
            ['asset_type_id' => $typeId, 'name' => 'Bewegungstest', 'location_id' => (string) $this->stockLocationId],
            $overrides
        ));

        return $this->c->get(AssetRepository::class)->find((int) $created['id']);
    }

    /** @return array<string,mixed> */
    private function asset(int $id): array
    {
        return $this->c->get(AssetRepository::class)->find($id);
    }

    /** @return array<string,mixed> */
    private function checkoutComplete(array $asset, array $overrides = []): array
    {
        return $this->service()->checkout(array_merge([
            'asset_id' => (string) $asset['id'], 'asset_version' => (string) $asset['version'],
            'employee_id' => (string) $this->employeeId, 'location_id' => (string) $this->officeLocationId, 'cost_center_id' => (string) $this->costCenterId,
        ], $overrides), 'mobile');
    }

    public function testResolveAssetByNumberQrUrlAndSerial(): void
    {
        $asset = $this->createAsset(['serial_number' => 'SN-RESOLVE-1']);
        $svc = $this->service();
        $this->assertSame($asset['id'], $svc->resolveAsset($asset['inventory_number'])['id']);
        $this->assertSame($asset['id'], $svc->resolveAsset(' ' . strtolower($asset['inventory_number']) . ' ')['id']);
        $this->assertSame($asset['id'], $svc->resolveAsset('https://assets.example.test/a/' . $asset['inventory_number'] . '?action=checkout')['id']);
        $this->assertSame($asset['id'], $svc->resolveAsset('sn-resolve-1')['id']);
        $this->assertNull($svc->resolveAsset('GIBTSNICHT'));
        $this->assertNull($svc->resolveAsset(''));
    }

    public function testCompleteCheckoutUpdatesAssetAndWritesHistory(): void
    {
        $asset = $this->createAsset();
        $movement = $this->checkoutComplete($asset, ['expected_return_at' => '2027-01-31']);

        $this->assertSame('checkout', $movement['type']);
        $this->assertSame('completed', $movement['status']);
        $this->assertSame('[]', $movement['missing_fields']);
        $this->assertSame($this->stockLocationId, $movement['from_location_id']);
        $this->assertSame($this->officeLocationId, $movement['to_location_id']);
        $this->assertNotNull($movement['completed_at']);

        $after = $this->asset($asset['id']);
        $this->assertSame('issued', $after['status_code']);
        $this->assertSame($this->employeeId, $after['employee_id']);
        $this->assertSame($this->officeLocationId, $after['location_id']);
        $this->assertSame($this->costCenterId, $after['cost_center_id']);
        $this->assertSame('2027-01-31', $after['expected_return_at']);
        $this->assertSame($asset['version'] + 1, $after['version']);

        $events = array_column($this->c->get(AssetHistoryRepository::class)->forMovement($movement['id']), 'event_type');
        $this->assertContains('checkout', $events);
        $this->assertContains('location_changed', $events, 'Standortwechsel bei Entnahme muss in der Historie stehen');
        $this->assertContains('assignment_changed', $events);
        $this->assertContains('status_changed', $events);
    }

    public function testCheckoutWithoutLocationStaysOpenAndCostCenterFallsBackToEmployee(): void
    {
        $asset = $this->createAsset();
        $movement = $this->service()->checkout([
            'asset_id' => (string) $asset['id'], 'asset_version' => (string) $asset['version'], 'employee_id' => (string) $this->employeeWithCcId,
        ], 'mobile');

        $this->assertSame('open', $movement['status']);
        $this->assertSame(['location'], json_decode($movement['missing_fields'], true));
        $this->assertSame($this->employeeCostCenterId, $movement['cost_center_id'], 'Kostenstelle wird vom Mitarbeiter übernommen');
        $this->assertSame(['Standort'], MovementService::missingLabels($movement));

        $after = $this->asset($asset['id']);
        $this->assertSame('issued', $after['status_code'], 'Asset ist trotz offenem Vorgang ausgegeben');
        $this->assertSame($this->stockLocationId, $after['location_id'], 'Standort bleibt unverändert, wenn keiner angegeben wurde');
        $this->assertNotNull($this->c->get(MovementRepository::class)->openForAsset($asset['id'], 'checkout'));
    }

    public function testCheckoutWithoutAnyCostCenterIsMarkedMissing(): void
    {
        $asset = $this->createAsset();
        $movement = $this->service()->checkout([
            'asset_id' => (string) $asset['id'], 'asset_version' => (string) $asset['version'], 'employee_id' => (string) $this->employeeId,
        ], 'web');
        $this->assertSame(['location', 'cost_center'], json_decode($movement['missing_fields'], true));
        $this->assertSame('open', $movement['status']);
    }

    public function testCheckoutValidationAndPermissions(): void
    {
        $asset = $this->createAsset();
        $svc = $this->service();

        $e = $this->assertThrows(ValidationException::class, fn () => $svc->checkout(['asset_id' => (string) $asset['id']], 'web'));
        $this->assertTrue(isset($e->errors()['employee_id']));

        $this->assertThrows(ValidationException::class, fn () => $svc->checkout(['inventory_number' => 'NOPE1', 'employee_id' => (string) $this->employeeId], 'web'), 'nicht gefunden');

        $inactive = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Ex Mitarbeiter', 'is_active' => 0]);
        $this->assertThrows(ValidationException::class, fn () => $svc->checkout(['asset_id' => (string) $asset['id'], 'employee_id' => (string) $inactive], 'web'), 'deaktiviert');

        $this->loginAs('readonly');
        $this->assertThrows(ForbiddenException::class, fn () => $this->checkoutComplete($asset));
    }

    public function testDoubleCheckoutConflictsAndClientTransactionIsIdempotent(): void
    {
        $asset = $this->createAsset();
        $tx = 'tx-' . bin2hex(random_bytes(8));
        $first = $this->checkoutComplete($asset, ['client_transaction_id' => $tx]);
        $again = $this->checkoutComplete($asset, ['client_transaction_id' => $tx]);
        $this->assertSame($first['id'], $again['id'], 'Gleiche Client-Transaktion liefert denselben Vorgang');
        $this->assertCount(1, $this->c->get(MovementRepository::class)->forAsset($asset['id']));

        $e = $this->assertThrows(ConflictException::class, fn () => $this->checkoutComplete($this->asset($asset['id'])), 'bereits');
        $this->assertSame($this->employeeId, $e->details()['employee_id']);
    }

    public function testStaleAssetVersionIsRejected(): void
    {
        $asset = $this->createAsset();
        $this->c->get(AssetRepository::class)->update($asset['id'], ['note' => 'zwischenzeitlich geändert']);
        $this->assertThrows(ConflictException::class, fn () => $this->checkoutComplete($asset), 'zwischenzeitlich');
    }

    public function testCheckoutOfFinalAssetIsRejected(): void
    {
        $asset = $this->createAsset();
        $this->c->get(AssetService::class)->changeStatus($asset['id'], $this->asset($asset['id']), 'retired', 'Test');
        $this->assertThrows(ConflictException::class, fn () => $this->checkoutComplete($this->asset($asset['id'])));
    }

    public function testReturnDefaultsTargetStatusFromCondition(): void
    {
        $this->assertSame('in_stock', MovementService::defaultTargetStatus('ok', false));
        $this->assertSame('in_stock', MovementService::defaultTargetStatus('worn', false));
        $this->assertSame('repair', MovementService::defaultTargetStatus('worn', true));
        $this->assertSame('repair', MovementService::defaultTargetStatus('damaged', false));
        $this->assertSame('defective', MovementService::defaultTargetStatus('defective', true));

        $asset = $this->createAsset();
        $this->checkoutComplete($asset);
        $issued = $this->asset($asset['id']);
        $movement = $this->service()->returnAsset([
            'asset_id' => (string) $issued['id'], 'asset_version' => (string) $issued['version'],
            'condition_code' => 'ok', 'to_location_id' => (string) $this->stockLocationId, 'accessories_checked' => '1',
        ], 'mobile');

        $this->assertSame('return', $movement['type']);
        $this->assertSame('completed', $movement['status']);
        $this->assertSame('in_stock', $movement['target_status_code']);
        $this->assertSame($this->employeeId, $movement['employee_id'], 'Rückgabe merkt sich den abgebenden Mitarbeiter');
        $this->assertSame($this->officeLocationId, $movement['from_location_id']);

        $after = $this->asset($asset['id']);
        $this->assertSame('in_stock', $after['status_code']);
        $this->assertNull($after['employee_id']);
        $this->assertNull($after['expected_return_at']);
        $this->assertSame($this->stockLocationId, $after['location_id']);
    }

    public function testReturnWithDamageRequiresDescriptionAndSetsRepair(): void
    {
        $asset = $this->createAsset();
        $this->checkoutComplete($asset);
        $issued = $this->asset($asset['id']);
        $svc = $this->service();

        $e = $this->assertThrows(ValidationException::class, fn () => $svc->returnAsset([
            'asset_id' => (string) $issued['id'], 'condition_code' => 'damaged', 'has_damage' => '1', 'to_location_id' => (string) $this->stockLocationId,
        ], 'mobile'));
        $this->assertTrue(isset($e->errors()['damage_description']));

        $e = $this->assertThrows(ValidationException::class, fn () => $svc->returnAsset(['asset_id' => (string) $issued['id'], 'to_location_id' => (string) $this->stockLocationId], 'mobile'));
        $this->assertTrue(isset($e->errors()['condition_code']));

        $movement = $svc->returnAsset([
            'asset_id' => (string) $issued['id'], 'condition_code' => 'damaged', 'has_damage' => '1', 'damage_description' => 'Display gesprungen',
            'to_location_id' => (string) $this->stockLocationId,
        ], 'mobile');
        $this->assertSame('repair', $movement['target_status_code']);
        $this->assertSame(1, (int) $movement['has_damage']);
        $this->assertSame('repair', $this->asset($asset['id'])['status_code']);
    }

    public function testReturnWithoutLocationIsOpenAndReturnOfStockAssetIsRejected(): void
    {
        $asset = $this->createAsset();
        $this->assertThrows(ConflictException::class, fn () => $this->service()->returnAsset(['asset_id' => (string) $asset['id'], 'condition_code' => 'ok'], 'web'), 'nicht ausgegeben');

        $this->checkoutComplete($asset);
        $movement = $this->service()->returnAsset(['asset_id' => (string) $asset['id'], 'condition_code' => 'ok'], 'mobile');
        $this->assertSame('open', $movement['status']);
        $this->assertSame(['location'], json_decode($movement['missing_fields'], true));
        $after = $this->asset($asset['id']);
        $this->assertSame('in_stock', $after['status_code']);
        $this->assertNull($after['employee_id']);
        $this->assertSame($this->officeLocationId, $after['location_id'], 'Ohne Zielstandort bleibt der bisherige Standort');
    }

    public function testRetireOnReturnRequiresPermission(): void
    {
        $asset = $this->createAsset();
        $this->checkoutComplete($asset);
        $input = ['asset_id' => (string) $asset['id'], 'condition_code' => 'defective', 'target_status_code' => 'retired', 'to_location_id' => (string) $this->stockLocationId];
        $this->loginAs('lager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->returnAsset($input, 'web'));

        $this->loginAs('admin');
        $movement = $this->service()->returnAsset($input, 'web');
        $this->assertSame('retired', $movement['target_status_code']);
        $this->assertSame('retired', $this->asset($asset['id'])['status_code']);
    }

    public function testUpdateCompletesOpenCheckoutAndRecordsLocationChange(): void
    {
        $asset = $this->createAsset();
        $open = $this->service()->checkout(['asset_id' => (string) $asset['id'], 'employee_id' => (string) $this->employeeId], 'mobile');
        $this->assertSame('open', $open['status']);
        $svc = $this->service();

        // Abschließen ohne fehlende Angaben → Fehler je Feld
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->update($open['id'], $open, ['movement_date' => $open['movement_date'], 'employee_id' => (string) $this->employeeId], true));
        $this->assertTrue(isset($e->errors()['to_location_id']));
        $this->assertTrue(isset($e->errors()['cost_center_id']));

        // Nur speichern lässt Lücken zu
        $saved = $svc->update($open['id'], $open, ['movement_date' => $open['movement_date'], 'employee_id' => (string) $this->employeeId, 'cost_center_id' => (string) $this->costCenterId], false);
        $this->assertSame('open', $saved['status']);
        $this->assertSame(['location'], json_decode($saved['missing_fields'], true));
        $this->assertSame($this->costCenterId, $this->asset($asset['id'])['cost_center_id'], 'Nachgetragene Kostenstelle landet am Asset');

        $done = $svc->update($saved['id'], $saved, [
            'movement_date' => $open['movement_date'], 'employee_id' => (string) $this->employeeId,
            'cost_center_id' => (string) $this->costCenterId, 'to_location_id' => (string) $this->officeLocationId,
        ], true);
        $this->assertSame('completed', $done['status']);
        $this->assertNotNull($done['completed_at']);
        $after = $this->asset($asset['id']);
        $this->assertSame($this->officeLocationId, $after['location_id']);

        $events = array_column($this->c->get(AssetHistoryRepository::class)->forMovement($open['id']), 'event_type');
        $this->assertContains('location_changed', $events);
        $this->assertContains('movement_completed', $events);
        $this->assertNull($this->c->get(MovementRepository::class)->openForAsset($asset['id']));
    }

    public function testCancelRevertsAssetAndOnlyForLatestMovement(): void
    {
        $asset = $this->createAsset();
        $checkout = $this->checkoutComplete($asset, ['expected_return_at' => '2027-03-01']);
        $svc = $this->service();

        $this->assertThrows(ValidationException::class, fn () => $svc->cancel($checkout['id'], $checkout, ''), 'Grund');

        $cancelled = $svc->cancel($checkout['id'], $checkout, 'Falsches Gerät gescannt');
        $this->assertSame('cancelled', $cancelled['status']);
        $after = $this->asset($asset['id']);
        $this->assertSame('in_stock', $after['status_code']);
        $this->assertNull($after['employee_id']);
        $this->assertNull($after['cost_center_id']);
        $this->assertNull($after['expected_return_at']);
        $this->assertSame($this->stockLocationId, $after['location_id']);
        $events = array_column($this->c->get(AssetHistoryRepository::class)->forMovement($checkout['id']), 'event_type');
        $this->assertContains('movement_cancelled', $events);

        $this->assertThrows(ConflictException::class, fn () => $svc->cancel($cancelled['id'], $cancelled, 'nochmal'), 'bereits storniert');

        // Entnahme + Rückgabe: die Entnahme ist nicht mehr der letzte Vorgang
        $asset2 = $this->createAsset();
        $co = $this->checkoutComplete($asset2);
        $this->service()->returnAsset(['asset_id' => (string) $asset2['id'], 'condition_code' => 'ok', 'to_location_id' => (string) $this->stockLocationId], 'web');
        $this->assertThrows(ConflictException::class, fn () => $svc->cancel($co['id'], $co, 'zu spät'), 'jüngste');
    }

    public function testSearchFiltersAndSummary(): void
    {
        $repo = $this->c->get(MovementRepository::class);
        $today = date('Y-m-d');
        $before = $repo->summary($today, $today);

        $a = $this->createAsset();
        $b = $this->createAsset();
        $this->checkoutComplete($a);
        $openCheckout = $this->service()->checkout(['asset_id' => (string) $b['id'], 'employee_id' => (string) $this->employeeId], 'mobile');
        $this->service()->returnAsset(['asset_id' => (string) $a['id'], 'condition_code' => 'ok', 'to_location_id' => (string) $this->stockLocationId], 'web');

        $summary = $repo->summary($today, $today);
        $this->assertSame((int) $before['checkouts'] + 2, (int) $summary['checkouts']);
        $this->assertSame((int) $before['returns'] + 1, (int) $summary['returns']);
        $this->assertSame((int) $before['open_checkouts'] + 1, (int) $summary['open_checkouts']);

        $open = $repo->search(['status' => 'open', 'asset_id' => $b['id']]);
        $this->assertCount(1, $open);
        $this->assertSame($openCheckout['id'], $open[0]['id']);

        $this->assertCount(1, $repo->search(['missing' => 'location', 'asset_id' => $b['id']]));
        $this->assertCount(0, $repo->search(['missing' => 'condition', 'asset_id' => $b['id']]));
        $this->assertCount(2, $repo->search(['asset_id' => $a['id']]));
        $this->assertCount(1, $repo->search(['asset_id' => $a['id'], 'type' => 'return']));
        $this->assertCount(1, $repo->search(['asset_id' => $a['id'], 'location_id' => $this->officeLocationId, 'type' => 'checkout']));
        $this->assertCount(1, $repo->search(['asset_id' => $a['id'], 'source' => 'web']));
        $this->assertCount(2, $repo->search(['q' => $a['inventory_number']]));
        $this->assertSame(2, $repo->countSearch(['employee_id' => $this->employeeId, 'asset_id' => $a['id']]));
        $this->assertCount(0, $repo->search(['asset_id' => $a['id'], 'date_from' => '2999-01-01']));
    }
}
