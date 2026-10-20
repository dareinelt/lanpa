<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Controllers\ProcurementController;
use App\Core\Request;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\PurchaseOrderService;
use Tests\Support\DatabaseTestCase;

final class PurchaseOrderServiceIntegrationTest extends DatabaseTestCase
{
    private int $supplierId;
    private int $costCenterId;
    private int $stockLocationId;
    private int $articleId;
    private int $mobileTypeId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // Bestellungen/Wareneingänge referenzieren users per Fremdschlüssel – Testnutzer innerhalb der Transaktion anlegen
        $this->pdo->exec("INSERT INTO users (username, display_name, role_id, auth_source, is_active) SELECT 'po-tester', 'Test User', id, 'local', 1 FROM roles ORDER BY id LIMIT 1");
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->loginAs('einkauf');
        $this->supplierId = $this->c->get(SupplierRepository::class)->create(['name' => 'Test-Lieferant GmbH', 'customer_number' => 'K-1', 'is_active' => 1]);
        $this->costCenterId = $this->c->get(CostCenterRepository::class)->create(['number' => 'E-100', 'description' => 'Einkauf Test', 'is_active' => 1]);
        $this->stockLocationId = $this->c->get(LocationRepository::class)->create(['name' => 'Wareneingang-Lager', 'type' => 'warehouse', 'full_path' => 'Wareneingang-Lager', 'depth' => 0, 'is_active' => 1]);
        $this->mobileTypeId = (int) $this->c->get(AssetTypeRepository::class)->findByCode('MD')['id'];
        $manufacturerId = $this->c->get(ManufacturerRepository::class)->create(['name' => 'Testphone Inc.', 'phonetic_key' => 'TSTFN', 'normalized_name' => 'testphone', 'is_active' => 1]);
        $this->articleId = $this->c->get(ArticleRepository::class)->create([
            'manufacturer_id' => $manufacturerId, 'asset_type_id' => $this->mobileTypeId, 'name' => 'Phone X 128GB', 'article_number' => 'PX-128', 'is_active' => 1,
        ]);
    }

    private function loginAs(string $role): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => $this->userId, 'username' => 'tester', 'display_name' => 'Test User', 'role' => $role]);
    }

    private function service(): PurchaseOrderService
    {
        return $this->c->get(PurchaseOrderService::class);
    }

    private function repo(): PurchaseOrderRepository
    {
        return $this->c->get(PurchaseOrderRepository::class);
    }

    /** @return array<string,mixed> */
    private function order(int $id): array
    {
        return $this->repo()->find($id) ?? throw new \RuntimeException('Bestellung fehlt');
    }

    /** Entwurf mit Artikelposition (3 Stück, erzeugt Assets) und Nebenkostenposition. @return array{0:int,1:int,2:int} */
    private function createOrderWithItems(): array
    {
        $id = $this->service()->create(['supplier_id' => (string) $this->supplierId, 'cost_center_id' => (string) $this->costCenterId, 'expected_delivery_date' => date('Y-m-d', strtotime('+7 days'))]);
        $phoneItem = $this->service()->addItem($id, $this->order($id), ['article_id' => (string) $this->articleId, 'quantity' => '3', 'unit_price' => '899,00', 'creates_assets' => '1']);
        $feeItem = $this->service()->addItem($id, $this->order($id), ['description' => 'Versandkosten', 'quantity' => '1', 'unit_price' => '9.90', 'creates_assets' => '0']);

        return [$id, $phoneItem, $feeItem];
    }

    // ------------------------------------------------------------------ Anlage & Status

    public function testCreateAssignsSequentialOrderNumberAndDraftStatus(): void
    {
        $first = $this->service()->create(['supplier_id' => (string) $this->supplierId]);
        $second = $this->service()->create(['supplier_id' => (string) $this->supplierId]);
        $a = $this->order($first);
        $b = $this->order($second);

        $this->assertSame('draft', $a['status']);
        $this->assertMatches('/^B-\d{4}-\d{4}$/', $a['order_number']);
        $this->assertSame((int) substr($a['order_number'], -4) + 1, (int) substr($b['order_number'], -4));
        $this->assertSame('Test User', $a['ordered_by_display']);
        $this->assertSame($this->userId, (int) $a['ordered_by_user_id']);
    }

    public function testCreateRejectsDuplicateNumberAndDeliveryBeforeOrderDate(): void
    {
        $this->service()->create(['supplier_id' => (string) $this->supplierId, 'order_number' => 'PO-77']);
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->create(['supplier_id' => (string) $this->supplierId, 'order_number' => 'po-77']));
        $this->assertTrue(isset($e->errors()['order_number']));

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->create([
            'supplier_id' => (string) $this->supplierId, 'order_date' => '2026-05-10', 'expected_delivery_date' => '2026-05-01',
        ]));
        $this->assertTrue(isset($e->errors()['expected_delivery_date']));
    }

    public function testMarkOrderedRequiresItemsAndSetsOrderDate(): void
    {
        $id = $this->service()->create(['supplier_id' => (string) $this->supplierId]);
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->markOrdered($id, $this->order($id)));
        $this->assertTrue(isset($e->errors()['items']));

        $this->service()->addItem($id, $this->order($id), ['description' => 'Kabel', 'quantity' => '5', 'creates_assets' => '0']);
        $this->service()->markOrdered($id, $this->order($id));
        $order = $this->order($id);
        $this->assertSame('ordered', $order['status']);
        $this->assertSame(date('Y-m-d'), $order['order_date']);

        $this->assertThrows(ConflictException::class, fn () => $this->service()->markOrdered($id, $this->order($id)));
    }

    public function testCancelNeedsReasonAndIsBlockedAfterDelivery(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->cancel($id, $this->order($id), '   '));
        $this->assertTrue(isset($e->errors()['reason']));

        $this->service()->markOrdered($id, $this->order($id));
        $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '1']]]);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->cancel($id, $this->order($id), 'zu spät'), 'geliefert');

        $other = $this->service()->create(['supplier_id' => (string) $this->supplierId, 'note' => 'Erstnotiz']);
        $this->service()->cancel($other, $this->order($other), 'Doppelt angelegt');
        $cancelled = $this->order($other);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertStringContains('Storniert: Doppelt angelegt', $cancelled['note']);
    }

    public function testCloseAndReopenOnlyFromMatchingStatus(): void
    {
        [$id, $phoneItem, $feeItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));
        $this->assertThrows(ConflictException::class, fn () => $this->service()->close($id, $this->order($id)));

        $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'location_id' => (string) $this->stockLocationId, 'items' => [
            $phoneItem => ['quantity' => '3', 'serials' => ['CL-1', 'CL-2', 'CL-3']],
            $feeItem => ['quantity' => '1'],
        ]]);
        $this->assertSame('delivered', $this->order($id)['status']);

        $this->service()->close($id, $this->order($id));
        $this->assertSame('closed', $this->order($id)['status']);
        $this->assertThrows(ConflictException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => []]));

        $this->service()->reopen($id, $this->order($id));
        $this->assertSame('delivered', $this->order($id)['status']);
    }

    // ------------------------------------------------------------------ Positionen

    public function testAddItemInfersTypeAndDescriptionFromArticle(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $item = $this->repo()->findItem($phoneItem);

        $this->assertSame('Testphone Inc. Phone X 128GB', $item['description']);
        $this->assertSame($this->mobileTypeId, (int) $item['effective_asset_type_id']);
        $this->assertSame(1, (int) $item['position']);
        $this->assertSame('899.00', $item['unit_price']);
        $this->assertSame(3, (int) $item['quantity_open']);

        $order = $this->order($id);
        $this->assertSame(2, (int) $order['item_count']);
        $this->assertSame(4, (int) $order['quantity_total']);
        $this->assertSame('2706.90', number_format((float) $order['total_net'], 2, '.', ''));
    }

    public function testAssetCreatingItemNeedsAssetType(): void
    {
        $id = $this->service()->create(['supplier_id' => (string) $this->supplierId]);
        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->addItem($id, $this->order($id), ['description' => 'Irgendwas', 'quantity' => '1', 'creates_assets' => '1']));
        $this->assertTrue(isset($e->errors()['asset_type_id']));

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->addItem($id, $this->order($id), ['quantity' => '1']));
        $this->assertTrue(isset($e->errors()['description']));
    }

    public function testItemsCannotBeEditedAfterDeliveryStarted(): void
    {
        [$id, $phoneItem, $feeItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));
        $this->service()->updateItem($this->order($id), $this->repo()->findItem($feeItem), ['description' => 'Versand & Verpackung', 'quantity' => '1', 'creates_assets' => '0']);
        $this->assertSame('Versand & Verpackung', $this->repo()->findItem($feeItem)['description']);

        $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '2', 'serials' => ['E-1', 'E-2']]]]);

        $this->assertThrows(ConflictException::class, fn () => $this->service()->deleteItem($this->order($id), $this->repo()->findItem($feeItem)));
        $this->assertThrows(ConflictException::class, fn () => $this->service()->addItem($id, $this->order($id), ['description' => 'Nachtrag', 'quantity' => '1', 'creates_assets' => '0']));
    }

    public function testDeleteItemBlockedWhenPartiallyReceivedAndQuantityCannotDropBelowReceived(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $this->service()->deleteItem($this->order($id), $this->repo()->findItem($phoneItem));
        $this->assertNull($this->repo()->findItem($phoneItem));

        // Positionsnummern werden nicht wiederverwendet (Versandkosten behält Pos. 2)
        $again = $this->service()->addItem($id, $this->order($id), ['article_id' => (string) $this->articleId, 'quantity' => '2', 'creates_assets' => '1']);
        $this->assertSame(3, (int) $this->repo()->findItem($again)['position']);
    }

    // ------------------------------------------------------------------ Wareneingang

    public function testPartialReceiptCreatesAssetsAndUpdatesStatus(): void
    {
        [$id, $phoneItem, $feeItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));

        $result = $this->service()->receive($id, $this->order($id), [
            'received_at' => '2026-09-10',
            'delivery_note_number' => 'LS-1',
            'location_id' => (string) $this->stockLocationId,
            'items' => [$phoneItem => ['quantity' => '2', 'serials' => ['sn-a1', 'SN-A2'], 'note' => 'Karton beschädigt']],
        ]);

        $this->assertCount(2, $result['asset_ids']);
        $this->assertSame(2, $result['quantity']);
        $order = $this->order($id);
        $this->assertSame('partially_delivered', $order['status']);
        $this->assertSame(2, (int) $order['quantity_received']);
        $this->assertSame(1, (int) $order['receipt_count']);
        $this->assertSame(1, (int) $this->repo()->findItem($phoneItem)['quantity_open']);

        $assets = $this->c->get(AssetRepository::class);
        $asset = $assets->find($result['asset_ids'][0]);
        $this->assertSame('sn-a1', $asset['serial_number']);
        $this->assertNull($asset['name']); // Bezeichnung kommt aus dem Artikel
        $this->assertSame($this->mobileTypeId, (int) $asset['asset_type_id']);
        $this->assertSame($this->articleId, (int) $asset['article_id']);
        $this->assertSame($id, (int) $asset['purchase_order_id']);
        $this->assertSame($phoneItem, (int) $asset['purchase_order_item_id']);
        $this->assertSame($result['receipt_id'], (int) $asset['goods_receipt_id']);
        $this->assertSame($this->supplierId, (int) $asset['supplier_id']);
        $this->assertSame($this->costCenterId, (int) $asset['cost_center_id']);
        $this->assertSame($this->stockLocationId, (int) $asset['location_id']);
        $this->assertSame('2026-09-10', $asset['purchase_date']);
        $this->assertSame('899.00', $asset['purchase_price']);
        $this->assertMatches('/^MD\d{2}\d{3,}$/', $asset['inventory_number']);

        $events = array_column($this->c->get(AssetHistoryRepository::class)->forAsset($result['asset_ids'][0]), 'event_type');
        $this->assertContains('goods_receipt', $events);
        $this->assertContains('created', $events);

        $receipt = $this->repo()->findReceipt($result['receipt_id']);
        $this->assertSame('LS-1', $receipt['delivery_note_number']);
        $lines = $this->repo()->receiptItems($result['receipt_id']);
        $this->assertCount(1, $lines);
        $this->assertSame('Karton beschädigt', $lines[0]['note']);
        $this->assertCount(2, $this->repo()->assetsFor($id, $result['receipt_id']));

        // Rest liefern → vollständig geliefert
        $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [
            $phoneItem => ['quantity' => '1', 'serials' => ['SN-A3']],
            $feeItem => ['quantity' => '1'],
        ]]);
        $order = $this->order($id);
        $this->assertSame('delivered', $order['status']);
        $this->assertSame(4, (int) $order['quantity_received']);
        $this->assertCount(3, $this->repo()->assetsFor($id));
    }

    public function testReceiveRejectsOverQuantityDuplicateSerialsAndEmptyDelivery(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '4']]]));
        $this->assertTrue(isset($e->errors()["items.{$phoneItem}.quantity"]));

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '2', 'serials' => ['DUP-1', 'dup-1']]]]));
        $this->assertTrue(isset($e->errors()["items.{$phoneItem}.serials.1"]));

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '0']]]));
        $this->assertTrue(isset($e->errors()['items']));

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d', strtotime('+1 day')), 'items' => [$phoneItem => ['quantity' => '1']]]));
        $this->assertTrue(isset($e->errors()['received_at']));

        // Nichts gebucht
        $this->assertSame('ordered', $this->order($id)['status']);
        $this->assertSame(0, (int) $this->order($id)['receipt_count']);
    }

    public function testReceiveRollsBackWhenSerialAlreadyExists(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));
        $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '1', 'serials' => ['UNIQUE-1']]]]);

        $e = $this->assertThrows(ValidationException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '2', 'serials' => ['NEW-2', 'unique-1']]]]));
        $this->assertTrue(isset($e->errors()["items.{$phoneItem}.serials.1"]));

        $order = $this->order($id);
        $this->assertSame(1, (int) $order['quantity_received']);
        $this->assertSame(1, (int) $order['receipt_count']);
        $this->assertCount(1, $this->repo()->assetsFor($id));
    }

    public function testReceiveNotAllowedForDraft(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $this->assertThrows(ConflictException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '1']]]));
    }

    public function testConsumableReceiptOnlyIncreasesStockWithoutCreatingAsset(): void
    {
        $this->pdo->prepare('UPDATE articles SET is_consumable = 1, minimum_stock = 5, stock_quantity = 2 WHERE id = ?')->execute([$this->articleId]);
        $id = $this->service()->create(['supplier_id' => (string) $this->supplierId]);
        $itemId = $this->service()->addItem($id, $this->order($id), ['article_id' => (string) $this->articleId, 'quantity' => '4', 'creates_assets' => '1']);
        $this->service()->markOrdered($id, $this->order($id));

        $result = $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$itemId => ['quantity' => '4']]]);

        $this->assertSame([], $result['asset_ids']);
        $this->assertSame(6, (int) $this->pdo->query('SELECT stock_quantity FROM articles WHERE id = ' . $this->articleId)->fetchColumn());
    }

    public function testReceiptOfItemChangedToConsumableDoesNotCreateAssets(): void
    {
        $id = $this->service()->create(['supplier_id' => (string) $this->supplierId]);
        $itemId = $this->service()->addItem($id, $this->order($id), ['article_id' => (string) $this->articleId, 'quantity' => '1', 'creates_assets' => '1']);
        $this->service()->markOrdered($id, $this->order($id));
        $this->pdo->prepare('UPDATE articles SET is_consumable = 1, stock_quantity = 0 WHERE id = ?')->execute([$this->articleId]);

        $result = $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$itemId => ['quantity' => '1']]]);

        $this->assertSame([], $result['asset_ids']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT stock_quantity FROM articles WHERE id = ' . $this->articleId)->fetchColumn());
    }

    public function testDemandRequestAcceptsSelectedArticleWithoutFreeText(): void
    {
        $response = $this->c->get(ProcurementController::class)->storeRequest(new Request(
            ['REQUEST_METHOD' => 'POST'], [], ['article_id' => (string) $this->articleId, 'quantity' => '2'], []
        ));

        $this->assertSame(302, $response->status());
        $requests = $this->c->get(ProcurementRepository::class)->requests($this->userId);
        $this->assertCount(1, $requests);
        $items = $this->c->get(ProcurementRepository::class)->requestItems((int) $requests[0]['id']);
        $this->assertSame($this->articleId, (int) $items[0]['article_id']);
        $this->assertSame('Testphone Inc. Phone X 128GB', $items[0]['description']);
    }

    public function testDemandRequestCanOnlyBeClaimedOnce(): void
    {
        $repository = $this->c->get(ProcurementRepository::class);
        $id = $repository->createRequest(['requested_by' => $this->userId, 'requested_by_name' => 'Test User', 'status' => 'open']);

        $this->assertTrue($repository->claimRequest($id));
        $this->assertFalse($repository->claimRequest($id));
    }

    // ------------------------------------------------------------------ Berechtigungen

    public function testLagerMayReceiveButNotManageOrders(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));

        $this->loginAs('lager');
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->create(['supplier_id' => (string) $this->supplierId]));
        $this->assertThrows(ForbiddenException::class, fn () => $this->service()->addItem($id, $this->order($id), ['description' => 'x', 'quantity' => '1', 'creates_assets' => '0']));
        $result = $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '1', 'serials' => ['LAGER-1']]]]);
        $this->assertCount(1, $result['asset_ids']);
    }

    public function testReadonlyAndAssetmanagementCannotTouchOrders(): void
    {
        [$id, $phoneItem] = $this->createOrderWithItems();
        $this->service()->markOrdered($id, $this->order($id));

        foreach (['readonly', 'assetmanagement'] as $role) {
            $this->loginAs($role);
            $this->assertThrows(ForbiddenException::class, fn () => $this->service()->create(['supplier_id' => (string) $this->supplierId]));
            $this->assertThrows(ForbiddenException::class, fn () => $this->service()->receive($id, $this->order($id), ['received_at' => date('Y-m-d'), 'items' => [$phoneItem => ['quantity' => '1']]]));
            $this->assertThrows(ForbiddenException::class, fn () => $this->service()->cancel($id, $this->order($id), 'nein'));
        }
    }
}
