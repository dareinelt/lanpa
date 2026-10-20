<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\LicenseService;
use Tests\Support\DatabaseTestCase;

final class LicenseServiceIntegrationTest extends DatabaseTestCase
{
    private int $userId;
    private int $stockLocationId;
    private int $manufacturerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO users (username, display_name, role_id, auth_source, is_active) SELECT 'lic-tester', 'Test User', id, 'local', 1 FROM roles ORDER BY id LIMIT 1");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->loginAs('assetmanagement');
        $this->stockLocationId = $this->c->get(LocationRepository::class)->create(['name' => 'Lizenz-Lager', 'type' => 'warehouse', 'full_path' => 'Lizenz-Lager', 'depth' => 0, 'is_active' => 1]);
        $this->manufacturerId = $this->c->get(ManufacturerRepository::class)->create(['name' => 'Softwerk AG', 'phonetic_key' => 'SFTWRK', 'normalized_name' => 'softwerk', 'is_active' => 1]);
    }

    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $this->userId, 'username' => 'tester', 'display_name' => 'Test User', 'role' => $role]);
    }

    private function service(): LicenseService
    {
        return $this->c->get(LicenseService::class);
    }

    private function repo(): LicenseRepository
    {
        return $this->c->get(LicenseRepository::class);
    }

    /** @return array<string,mixed> */
    private function license(int $id): array
    {
        return $this->repo()->find($id) ?? throw new \RuntimeException('Lizenz fehlt');
    }

    /** @param array<string,mixed> $overrides */
    private function createLicense(array $overrides = []): int
    {
        return $this->service()->create(array_merge([
            'manufacturer_id' => (string) $this->manufacturerId,
            'product' => 'Office Suite',
            'license_type' => 'Abonnement',
            'license_number' => 'LN-1',
            'license_key' => 'AAAAA-BBBBB',
            'quantity' => '2',
            'purchase_date' => '2026-01-15',
            'expires_at' => date('Y-m-d', strtotime('+200 days')),
            'cost' => '199,90',
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function createAsset(array $overrides = []): array
    {
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode('PC')['id'];
        $created = $this->c->get(AssetService::class)->create(array_merge(
            ['asset_type_id' => $typeId, 'name' => 'Lizenz-Testgerät', 'location_id' => (string) $this->stockLocationId],
            $overrides
        ));

        return $this->c->get(AssetRepository::class)->find((int) $created['id']);
    }

    /** @return list<string> */
    private function historyTypes(int $assetId): array
    {
        return array_column($this->c->get(AssetHistoryRepository::class)->forAsset($assetId), 'event_type');
    }

    // ------------------------------------------------------------------ Anlage & Validierung

    public function testCreateStoresFieldsAndComputesStatus(): void
    {
        $id = $this->createLicense();
        $l = $this->license($id);
        $this->assertSame('Office Suite', $l['product']);
        $this->assertSame('Softwerk AG', $l['manufacturer_name']);
        $this->assertSame(2, (int) $l['quantity']);
        $this->assertSame(0, (int) $l['used_count']);
        $this->assertSame(2, (int) $l['available_count']);
        $this->assertSame('199.90', $l['cost']);
        $this->assertSame('valid', $l['expiry_status']);
        $this->assertSame(1, (int) $l['is_active']);
    }

    public function testExpiryStatusPerpetualExpiringExpired(): void
    {
        $perpetual = $this->license($this->createLicense(['expires_at' => '']));
        $this->assertSame('perpetual', $perpetual['expiry_status']);

        $expiring = $this->license($this->createLicense(['expires_at' => date('Y-m-d', strtotime('+10 days'))]));
        $this->assertSame('expiring', $expiring['expiry_status']);
        $this->assertSame(10, (int) $expiring['days_left']);

        $expired = $this->license($this->createLicense(['purchase_date' => '2024-01-01', 'expires_at' => date('Y-m-d', strtotime('-1 day'))]));
        $this->assertSame('expired', $expired['expiry_status']);
    }

    public function testCreateRequiresProduct(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->createLicense(['product' => '']));
        $this->assertTrue(isset($e->errors()['product']));
    }

    public function testExpiryBeforePurchaseRejected(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->createLicense(['purchase_date' => '2026-05-01', 'expires_at' => '2026-04-01']));
        $this->assertTrue(isset($e->errors()['expires_at']));
    }

    public function testQuantityBelowUsedRejectedOnUpdate(): void
    {
        $id = $this->createLicense(['quantity' => '2']);
        $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]);
        $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]);
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->update($id, $this->license($id), ['product' => 'Office Suite', 'quantity' => '1']));
        $this->assertTrue(isset($e->errors()['quantity']));
        $this->service()->update($id, $this->license($id), ['product' => 'Office Suite Plus', 'quantity' => '5']);
        $this->assertSame('Office Suite Plus', $this->license($id)['product']);
        $this->assertSame(3, (int) $this->license($id)['available_count']);
    }

    public function testUnknownForeignKeysRejected(): void
    {
        $e = $this->assertThrows(ValidationException::class, fn () => $this->createLicense(['manufacturer_id' => '999999']));
        $this->assertTrue(isset($e->errors()['manufacturer_id']));
    }

    // ------------------------------------------------------------------ Zuordnung

    public function testAssignByIdCreatesAssignmentAndHistory(): void
    {
        $id = $this->createLicense();
        $asset = $this->createAsset();
        $assignmentId = $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id'], 'note' => 'Installiert']);

        $l = $this->license($id);
        $this->assertSame(1, (int) $l['used_count']);
        $this->assertSame(1, (int) $l['available_count']);
        $assignments = $this->repo()->assignments($id, true);
        $this->assertCount(1, $assignments);
        $this->assertSame($assignmentId, (int) $assignments[0]['id']);
        $this->assertSame('Installiert', $assignments[0]['note']);
        $this->assertSame('Test User', $assignments[0]['assigned_by']);
        $this->assertContains('license_assigned', $this->historyTypes((int) $asset['id']));
        $this->assertCount(1, $this->repo()->forAsset((int) $asset['id']));
    }

    public function testAssignByInventoryAndSerialNumber(): void
    {
        $id = $this->createLicense(['quantity' => '3']);
        $a = $this->createAsset();
        $b = $this->createAsset(['serial_number' => 'LIC-SN-0002']);
        $this->service()->assign($this->license($id), ['asset_code' => $a['inventory_number']]);
        $this->service()->assign($this->license($id), ['asset_code' => 'lic-sn-0002']);
        $this->assertSame(2, (int) $this->license($id)['used_count']);
    }

    public function testAssignUnknownCodeRejected(): void
    {
        $id = $this->createLicense();
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->assign($this->license($id), ['asset_code' => 'NOPE-123']));
        $this->assertStringContains('NOPE-123', $e->errors()['asset_id']);
    }

    public function testAssignWithoutAssetRejected(): void
    {
        $id = $this->createLicense();
        $this->assertThrows(ValidationException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => '', 'asset_code' => '']));
    }

    public function testDuplicateAssignmentRejected(): void
    {
        $id = $this->createLicense();
        $asset = $this->createAsset();
        $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]);
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]));
        $this->assertStringContains('bereits zugeordnet', $e->errors()['asset_id']);
    }

    public function testCapacityExhaustedRejected(): void
    {
        $id = $this->createLicense(['quantity' => '1']);
        $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]));
    }

    public function testExpiredLicenseCannotBeAssigned(): void
    {
        $id = $this->createLicense(['purchase_date' => '2024-01-01', 'expires_at' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]));
    }

    public function testInactiveLicenseCannotBeAssigned(): void
    {
        $id = $this->createLicense();
        $this->service()->setActive($id, $this->license($id), false);
        $this->assertSame(0, (int) $this->license($id)['is_active']);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]));
    }

    public function testRetiredAssetCannotBeAssigned(): void
    {
        $id = $this->createLicense();
        $asset = $this->createAsset();
        $this->loginAs('admin');
        $this->c->get(AssetService::class)->changeStatus((int) $asset['id'], $asset, 'retired');
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]));
        $this->assertStringContains('ausgeschieden', $e->errors()['asset_id']);
    }

    // ------------------------------------------------------------------ Freigabe

    public function testReleaseFreesUnitAndWritesHistory(): void
    {
        $id = $this->createLicense(['quantity' => '1']);
        $asset = $this->createAsset();
        $assignmentId = $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]);
        $this->service()->release($this->license($id), $assignmentId);

        $l = $this->license($id);
        $this->assertSame(0, (int) $l['used_count']);
        $this->assertSame(1, (int) $l['available_count']);
        $released = $this->repo()->findAssignment($assignmentId);
        $this->assertNotNull($released['released_at']);
        $this->assertSame('Test User', $released['released_by']);
        $this->assertSame([], $this->repo()->assignments($id, true));
        $this->assertCount(1, $this->repo()->assignments($id, false));
        $this->assertSame([], $this->repo()->forAsset((int) $asset['id']));
        $this->assertContains('license_removed', $this->historyTypes((int) $asset['id']));

        // Einheit ist wieder frei: erneute Zuordnung möglich
        $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]);
        $this->assertSame(1, (int) $this->license($id)['used_count']);
    }

    public function testReleaseTwiceRejected(): void
    {
        $id = $this->createLicense();
        $assignmentId = $this->service()->assign($this->license($id), ['asset_id' => (string) $this->createAsset()['id']]);
        $this->service()->release($this->license($id), $assignmentId);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->release($this->license($id), $assignmentId));
    }

    public function testReleaseOfForeignAssignmentRejected(): void
    {
        $a = $this->createLicense();
        $b = $this->createLicense(['license_number' => 'LN-2']);
        $assignmentId = $this->service()->assign($this->license($a), ['asset_id' => (string) $this->createAsset()['id']]);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->release($this->license($b), $assignmentId));
    }

    // ------------------------------------------------------------------ Filter & Zähler

    public function testStatusFiltersAndCounts(): void
    {
        $used = $this->createLicense(['quantity' => '1']);
        $this->service()->assign($this->license($used), ['asset_id' => (string) $this->createAsset()['id']]);
        $expiring = $this->createLicense(['expires_at' => date('Y-m-d', strtotime('+5 days'))]);
        $expired = $this->createLicense(['purchase_date' => '2024-01-01', 'expires_at' => date('Y-m-d', strtotime('-5 days'))]);
        $inactive = $this->createLicense();
        $this->service()->setActive($inactive, $this->license($inactive), false);

        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $this->assertContains($used, $ids($this->repo()->search(['status' => 'used'], 50, 0)));
        $this->assertFalse(in_array($used, $ids($this->repo()->search(['status' => 'available'], 50, 0)), true));
        $this->assertContains($expiring, $ids($this->repo()->search(['status' => 'expiring'], 50, 0)));
        $this->assertContains($expiring, $ids($this->repo()->search(['expiring' => 30], 50, 0)));
        $this->assertFalse(in_array($expiring, $ids($this->repo()->search(['expiring' => 2], 50, 0)), true));
        $this->assertContains($expired, $ids($this->repo()->search(['status' => 'expired'], 50, 0)));
        $this->assertFalse(in_array($expired, $ids($this->repo()->search(['status' => 'available'], 50, 0)), true));
        $this->assertContains($inactive, $ids($this->repo()->search(['status' => 'inactive'], 50, 0)));
        $this->assertFalse(in_array($inactive, $ids($this->repo()->search(['status' => 'active'], 50, 0)), true));
        $this->assertContains($used, $ids($this->repo()->search(['q' => 'LN-1'], 50, 0)));

        $counts = $this->repo()->statusCounts();
        $this->assertTrue($counts['used'] >= 1);
        $this->assertTrue($counts['expiring'] >= 1);
        $this->assertTrue($counts['expired'] >= 1);
        $this->assertTrue($counts['inactive'] >= 1);
    }

    // ------------------------------------------------------------------ Berechtigungen

    public function testReadonlyAndLagerCannotManage(): void
    {
        $id = $this->createLicense();
        foreach (['readonly', 'lager'] as $role) {
            $this->loginAs($role);
            $asset = $this->createAssetAsAdmin();
            $this->assertThrows(ForbiddenException::class, fn () => $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]));
            $this->assertThrows(ForbiddenException::class, fn () => $this->createLicense(['license_number' => 'LN-' . $role]));
        }
    }

    public function testEinkaufMayManageLicenses(): void
    {
        $this->loginAs('einkauf');
        $id = $this->createLicense();
        $asset = $this->createAssetAsAdmin();
        $this->loginAs('einkauf');
        $assignmentId = $this->service()->assign($this->license($id), ['asset_id' => (string) $asset['id']]);
        $this->service()->release($this->license($id), $assignmentId);
        $this->assertSame(0, (int) $this->license($id)['used_count']);
    }

    /** Assets anlegen erfordert assets.manage – dafür kurz als Admin arbeiten. @return array<string,mixed> */
    private function createAssetAsAdmin(): array
    {
        $previous = $this->c->get(CurrentUser::class)->role();
        $this->loginAs('admin');
        $asset = $this->createAsset();
        $this->loginAs($previous);

        return $asset;
    }
}
