<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\DocumentService;
use App\Services\OfflineSyncService;
use Tests\Support\DatabaseTestCase;

final class OfflineSyncServiceIntegrationTest extends DatabaseTestCase
{
    private int $userId;
    private int $employeeId;
    private int $locationId;
    /** @var list<int> */
    private array $documentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO users (username, display_name, role_id, auth_source, is_active) SELECT 'offline-tester', 'Offline Tester', id, 'local', 1 FROM roles ORDER BY id LIMIT 1");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->loginAs('assetmanagement');
        $this->locationId = $this->c->get(LocationRepository::class)->create(['name' => 'Offline-Lager', 'type' => 'room', 'full_path' => 'Offline-Lager', 'depth' => 0, 'is_active' => 1]);
        $this->employeeId = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Offline Nutzer', 'username' => 'onutzer', 'personnel_number' => '99001', 'department' => 'Technik', 'is_active' => 1]);
    }

    protected function tearDown(): void
    {
        $documents = $this->c->get(DocumentService::class);
        $repo = $this->c->get(DocumentRepository::class);
        foreach ($this->documentIds as $id) {
            $doc = $repo->find($id);
            if ($doc !== null) {
                $documents->delete($doc);
            }
        }
        parent::tearDown();
    }

    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $this->userId, 'username' => 'offline-tester', 'display_name' => 'Offline Tester', 'role' => $role]);
    }

    private function service(): OfflineSyncService
    {
        return $this->c->get(OfflineSyncService::class);
    }

    /** @return array<string,mixed> */
    private function createAsset(string $name = 'Offline-Asset'): array
    {
        $typeId = (string) $this->c->get(AssetTypeRepository::class)->findByCode('PC')['id'];
        $created = $this->c->get(AssetService::class)->create(['asset_type_id' => $typeId, 'name' => $name, 'location_id' => (string) $this->locationId]);

        return $this->c->get(AssetRepository::class)->find((int) $created['id']);
    }

    /** @return array<string,mixed> */
    private function checkoutTx(array $asset, string $txId, array $payload = []): array
    {
        return ['client_transaction_id' => $txId, 'type' => 'checkout', 'payload' => array_merge([
            'asset_id' => $asset['id'], 'asset_version' => $asset['version'], 'employee_id' => $this->employeeId, 'location_id' => $this->locationId,
        ], $payload)];
    }

    /** @return array<string,mixed> */
    private function returnTx(array $asset, string $txId, array $payload = [], ?array $photos = null): array
    {
        $tx = ['client_transaction_id' => $txId, 'type' => 'return', 'payload' => array_merge([
            'asset_id' => $asset['id'], 'asset_version' => $asset['version'], 'condition_code' => 'ok', 'to_location_id' => $this->locationId,
        ], $payload)];
        if ($photos !== null) {
            $tx['photos'] = $photos;
        }

        return $tx;
    }

    // ------------------------------------------------------------------ Bootstrap

    public function testBootstrapContainsAssetsMasterDataAndPermissions(): void
    {
        $asset = $this->createAsset('Bootstrap-Gerät');
        $data = $this->service()->bootstrap();

        $this->assertTrue(isset($data['generated_at'], $data['assets'], $data['employees'], $data['locations'], $data['cost_centers'], $data['conditions'], $data['return_targets']));
        $this->assertSame($this->userId, $data['user']['id']);
        $this->assertTrue($data['permissions']['checkout']);
        $this->assertTrue($data['permissions']['return']);
        $this->assertTrue($data['permissions']['retire']);

        $this->loginAs('lager');
        $this->assertFalse($this->service()->bootstrap()['permissions']['retire'], 'Lager darf nicht ausmustern');

        $found = array_values(array_filter($data['assets'], fn (array $a): bool => $a['id'] === (int) $asset['id']));
        $this->assertCount(1, $found);
        $this->assertSame($asset['inventory_number'], $found[0]['inventory_number']);
        $this->assertSame('in_stock', $found[0]['status_code']);
        $this->assertSame((int) $asset['version'], $found[0]['version']);
        $this->assertSame($this->locationId, $found[0]['location_id']);

        $employee = array_values(array_filter($data['employees'], fn (array $e): bool => $e['id'] === $this->employeeId));
        $this->assertCount(1, $employee);
        $this->assertSame('Offline Nutzer', $employee[0]['name']);
        $this->assertSame('99001 · Technik', $employee[0]['meta']);
    }

    public function testBootstrapReflectsReadonlyPermissions(): void
    {
        $this->loginAs('readonly');
        $data = $this->service()->bootstrap();
        $this->assertFalse($data['permissions']['checkout']);
        $this->assertFalse($data['permissions']['return']);
    }

    public function testBootstrapOmitsRetiredAssets(): void
    {
        $asset = $this->createAsset('Ausgemustert');
        $this->pdo->prepare('UPDATE assets SET status_id = (SELECT id FROM asset_statuses WHERE code = :code) WHERE id = :id')
            ->execute(['code' => 'retired', 'id' => $asset['id']]);
        $ids = array_column($this->service()->bootstrap()['assets'], 'id');
        $this->assertFalse(in_array((int) $asset['id'], $ids, true));
    }

    // ------------------------------------------------------------------ Synchronisation

    public function testCheckoutIsStoredWithOfflineSource(): void
    {
        $asset = $this->createAsset();
        $results = $this->service()->process([$this->checkoutTx($asset, 'offline-tx-000001')]);

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('offline-tx-000001', $results[0]['client_transaction_id']);
        $movement = $this->c->get(MovementRepository::class)->find($results[0]['movement_id']);
        $this->assertSame('offline_sync', $movement['source']);
        $this->assertSame('checkout', $movement['type']);
        $this->assertSame('issued', $this->c->get(AssetRepository::class)->find((int) $asset['id'])['status_code']);
    }

    public function testSameTransactionIdIsNeverCreatedTwice(): void
    {
        $asset = $this->createAsset();
        $tx = $this->checkoutTx($asset, 'offline-tx-dup-01');
        $first = $this->service()->process([$tx])[0];
        $second = $this->service()->process([$tx])[0];

        $this->assertSame('ok', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame($first['movement_id'], $second['movement_id']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM movements WHERE client_transaction_id = :tx');
        $count->execute(['tx' => 'offline-tx-dup-01']);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    public function testDuplicateWithinSameBatchIsReportedOnce(): void
    {
        $asset = $this->createAsset();
        $tx = $this->checkoutTx($asset, 'offline-tx-batch-1');
        $results = $this->service()->process([$tx, $tx]);
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('duplicate', $results[1]['status']);
    }

    public function testStaleVersionYieldsConflictWithServerState(): void
    {
        $asset = $this->createAsset();
        // Zwischenzeitliche Änderung auf dem Server
        $this->pdo->prepare('UPDATE assets SET version = version + 1 WHERE id = :id')->execute(['id' => $asset['id']]);
        $result = $this->service()->process([$this->checkoutTx($asset, 'offline-tx-stale-1')])[0];

        $this->assertSame('conflict', $result['status']);
        $this->assertStringContains('zwischenzeitlich geändert', $result['message']);
        $this->assertSame((int) $asset['version'] + 1, $result['conflict']['version']);
        $this->assertSame('in_stock', $result['conflict']['status']);
        $this->assertNull($this->c->get(MovementRepository::class)->findByClientTransaction('offline-tx-stale-1'));
    }

    public function testForceOverridesStaleVersionAfterUserConfirmation(): void
    {
        $asset = $this->createAsset();
        $this->pdo->prepare('UPDATE assets SET version = version + 1 WHERE id = :id')->execute(['id' => $asset['id']]);
        $tx = $this->checkoutTx($asset, 'offline-tx-force-1') + ['force' => true];
        $result = $this->service()->process([$tx])[0];
        $this->assertSame('ok', $result['status']);
        $this->assertNotNull($this->c->get(MovementRepository::class)->findByClientTransaction('offline-tx-force-1'));
    }

    public function testBusinessConflictIsNotOverriddenByForce(): void
    {
        $asset = $this->createAsset();
        $this->service()->process([$this->checkoutTx($asset, 'offline-tx-first-01')]);
        $other = $this->c->get(EmployeeRepository::class)->create(['display_name' => 'Zweiter Nutzer', 'username' => 'zweiter', 'is_active' => 1]);
        $fresh = $this->c->get(AssetRepository::class)->find((int) $asset['id']);
        $tx = $this->checkoutTx($fresh, 'offline-tx-second-1', ['employee_id' => $other]) + ['force' => true];
        $result = $this->service()->process([$tx])[0];

        $this->assertSame('conflict', $result['status']);
        $this->assertStringContains('bereits an', $result['message']);
    }

    public function testValidationErrorsAreReportedPerTransaction(): void
    {
        $asset = $this->createAsset();
        $results = $this->service()->process([
            $this->checkoutTx($asset, 'offline-tx-noemp-1', ['employee_id' => '']),
            ['client_transaction_id' => 'bad id!', 'type' => 'checkout', 'payload' => []],
            ['client_transaction_id' => 'offline-tx-type-01', 'type' => 'delete', 'payload' => []],
            ['client_transaction_id' => 'offline-tx-nopay-1', 'type' => 'return', 'payload' => ['asset_id' => $asset['id']]],
        ]);

        $this->assertSame('error', $results[0]['status']);
        $this->assertTrue(isset($results[0]['errors']['employee_id']));
        $this->assertSame('error', $results[1]['status']);
        $this->assertTrue(isset($results[1]['errors']['client_transaction_id']));
        $this->assertSame('error', $results[2]['status']);
        $this->assertTrue(isset($results[2]['errors']['type']));
        $this->assertSame('error', $results[3]['status']);
        $this->assertTrue(isset($results[3]['errors']['condition_code']));
    }

    public function testOneFailureDoesNotBlockOtherTransactions(): void
    {
        $a = $this->createAsset('A');
        $b = $this->createAsset('B');
        $results = $this->service()->process([
            $this->checkoutTx($a, 'offline-tx-mix-a-1', ['employee_id' => '']),
            $this->checkoutTx($b, 'offline-tx-mix-b-1'),
        ]);
        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
    }

    public function testReadonlyUserIsForbidden(): void
    {
        $asset = $this->createAsset();
        $this->loginAs('readonly');
        $result = $this->service()->process([$this->checkoutTx($asset, 'offline-tx-ro-0001')])[0];
        $this->assertSame('forbidden', $result['status']);
        $this->assertNull($this->c->get(MovementRepository::class)->findByClientTransaction('offline-tx-ro-0001'));

        // Lesen bleibt erlaubt, ohne Anmeldung ist der Bootstrap gesperrt
        $this->assertTrue(count($this->service()->bootstrap()['assets']) > 0);
        $this->c->get(CurrentUser::class)->logout();
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->bootstrap());
    }

    public function testBatchLimitIsEnforced(): void
    {
        $asset = $this->createAsset();
        $batch = [];
        for ($i = 0; $i <= OfflineSyncService::MAX_BATCH; $i++) {
            $batch[] = $this->checkoutTx($asset, sprintf('offline-tx-big-%04d', $i));
        }
        $this->assertThrows(ValidationException::class, fn () => $this->service()->process($batch), 'Höchstens');
    }

    public function testReturnWithBase64PhotoCreatesDocument(): void
    {
        $asset = $this->createAsset();
        $this->service()->process([$this->checkoutTx($asset, 'offline-tx-photo-c1')]);
        $issued = $this->c->get(AssetRepository::class)->find((int) $asset['id']);

        // 1×1 PNG
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $result = $this->service()->process([$this->returnTx($issued, 'offline-tx-photo-r1', ['condition_code' => 'damaged', 'has_damage' => '1', 'damage_description' => 'Kratzer'], [
            ['name' => 'schaden.png', 'type' => 'image/png', 'data' => 'data:image/png;base64,' . base64_encode($png)],
            ['name' => 'kaputt.png', 'type' => 'image/png', 'data' => '%%%nicht-base64%%%'],
        ])])[0];

        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContains('kaputt.png', $result['warnings'][0]);

        $docs = $this->c->get(DocumentRepository::class)->forEntity('movement', $result['movement_id']);
        $this->documentIds = array_map(static fn (array $d): int => (int) $d['id'], $docs);
        $this->assertCount(1, $docs);
        $this->assertSame('schaden.png', $docs[0]['original_name']);
        $this->assertSame('photo', $docs[0]['document_type']);
    }
}
