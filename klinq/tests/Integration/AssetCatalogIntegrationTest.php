<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ValidationException;
use App\Repositories\AssetTypeRepository;
use App\Security\CurrentUser;
use App\Services\AssetCatalogService;
use App\Services\AssetService;
use Tests\Support\DatabaseTestCase;

/** Kataloge „Assettypen“ und „Kategorien“ (Auswertung & System → Kataloge). */
final class AssetCatalogIntegrationTest extends DatabaseTestCase
{
    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function typeInput(array $overrides = []): array
    {
        return array_merge([
            'code' => 'drk', 'name' => 'Drucker', 'inventory_prefix' => 'dr', 'icon' => 'print',
            'has_serial_number' => '1', 'has_mac_address' => '1', 'sort_order' => '50', 'is_active' => '1',
        ], $overrides);
    }

    public function testCreateTypeNormalizesAndValidatesUniqueness(): void
    {
        $svc = $this->c->get(AssetCatalogService::class);
        $repo = $this->c->get(AssetTypeRepository::class);

        $id = $svc->saveType(null, $this->typeInput());
        $row = $repo->find($id);
        $this->assertSame('DRK', $row['code']);
        $this->assertSame('DR', $row['inventory_prefix']);
        $this->assertSame(1, (int) $row['has_mac_address']);
        $this->assertSame(0, (int) $row['has_imei']);
        $this->assertContains('DRK', array_column($repo->all(true), 'code'));

        $this->assertThrows(ValidationException::class, fn () => $svc->saveType(null, $this->typeInput(['inventory_prefix' => 'XY'])), 'Code');
        $this->assertThrows(ValidationException::class, fn () => $svc->saveType(null, $this->typeInput(['code' => 'NEU', 'inventory_prefix' => 'PC'])), 'Präfix');
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->saveType(null, $this->typeInput(['code' => 'NEU', 'inventory_prefix' => 'AB1'])));
        $this->assertStringContains('Buchstaben', $e->errors()['inventory_prefix'] ?? '');
        $e = $this->assertThrows(ValidationException::class, fn () => $svc->saveType(null, $this->typeInput(['code' => 'NEU', 'inventory_prefix' => 'NEU', 'icon' => 'unbekannt'])));
        $this->assertStringContains('Symbol', $e->errors()['icon'] ?? '');
    }

    public function testPrefixIsLockedOnceAssetsExist(): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => 1, 'username' => 'tester', 'display_name' => 'Test User', 'role' => 'admin']);
        $svc = $this->c->get(AssetCatalogService::class);
        $repo = $this->c->get(AssetTypeRepository::class);

        $id = $svc->saveType(null, $this->typeInput());
        // Ohne Assets darf das Präfix noch geändert werden
        $svc->saveType($id, $this->typeInput(['inventory_prefix' => 'DRU']));
        $this->assertSame('DRU', $repo->find($id)['inventory_prefix']);

        $this->pdo->exec("INSERT INTO manufacturers (name) VALUES ('Katalogtest')");
        $this->pdo->prepare('INSERT INTO articles (manufacturer_id, asset_type_id, name, is_active) VALUES (?, ?, ?, 1)')
            ->execute([(int) $this->pdo->lastInsertId(), $id, 'Drucker 1']);
        $articleId = (int) $this->pdo->lastInsertId();
        $created = $this->c->get(AssetService::class)->create(['asset_type_id' => (string) $id, 'article_id' => (string) $articleId, 'serial_number' => 'SN-DRU-1']);
        $this->assertMatches('/^DRU\d{5}$/', (string) $created['inventory_number']);

        $this->assertThrows(ValidationException::class, fn () => $svc->saveType($id, $this->typeInput(['inventory_prefix' => 'DRX'])), 'Präfix');
        // Übrige Felder bleiben änderbar
        $svc->saveType($id, $this->typeInput(['inventory_prefix' => 'DRU', 'name' => 'Etikettendrucker']));
        $this->assertSame('Etikettendrucker', $repo->find($id)['name']);
    }

    public function testDeactivatedTypeIsHiddenFromSelectionButKeptForCurrentRecord(): void
    {
        $svc = $this->c->get(AssetCatalogService::class);
        $repo = $this->c->get(AssetTypeRepository::class);
        $id = $svc->saveType(null, $this->typeInput());

        $svc->setTypeActive($id, $repo->find($id), false);
        $this->assertFalse(in_array($id, array_map('intval', array_column($repo->all(true), 'id')), true));
        $this->assertContains($id, array_map('intval', array_column($repo->selectable($id), 'id')));
    }

    public function testCategoriesAreUniquePerTypeAndTypeIsLockedWhenUsed(): void
    {
        $svc = $this->c->get(AssetCatalogService::class);
        $repo = $this->c->get(AssetTypeRepository::class);
        $pc = (int) $repo->findByCode('PC')['id'];
        $md = (int) $repo->findByCode('MD')['id'];

        $id = $svc->saveCategory(null, ['asset_type_id' => (string) $pc, 'name' => 'Mini-PC', 'sort_order' => '15', 'is_active' => '1']);
        $this->assertSame('Mini-PC', $repo->findCategory($id)['name']);
        $this->assertThrows(ValidationException::class, fn () => $svc->saveCategory(null, ['asset_type_id' => (string) $pc, 'name' => 'Mini-PC', 'is_active' => '1']), 'bereits');
        // Gleicher Name bei anderem Assettyp ist erlaubt
        $svc->saveCategory(null, ['asset_type_id' => (string) $md, 'name' => 'Mini-PC', 'is_active' => '1']);

        // Unbenutzt: Assettyp darf wechseln
        $svc->saveCategory($id, ['asset_type_id' => (string) $md, 'name' => 'Mini-Gerät', 'is_active' => '1']);
        $this->assertSame($md, (int) $repo->findCategory($id)['asset_type_id']);

        // Von einem Artikel verwendet: Assettyp gesperrt
        $this->pdo->exec("INSERT INTO manufacturers (name) VALUES ('Katalogtest')");
        $this->pdo->prepare('INSERT INTO articles (manufacturer_id, asset_type_id, asset_category_id, name, is_active) VALUES (?, ?, ?, ?, 1)')
            ->execute([(int) $this->pdo->lastInsertId(), $md, $id, 'Mini 1']);
        $this->assertSame(1, $repo->categoryUsage($id));
        $this->assertThrows(ValidationException::class, fn () => $svc->saveCategory($id, ['asset_type_id' => (string) $pc, 'name' => 'Mini-Gerät', 'is_active' => '1']), 'Assettyp');
    }

    public function testDeactivatedCategoryStaysSelectableForCurrentRecord(): void
    {
        $svc = $this->c->get(AssetCatalogService::class);
        $repo = $this->c->get(AssetTypeRepository::class);
        $pc = (int) $repo->findByCode('PC')['id'];
        $id = $svc->saveCategory(null, ['asset_type_id' => (string) $pc, 'name' => 'Altgerät', 'is_active' => '1']);

        $svc->setCategoryActive($id, $repo->findCategory($id), false);
        $this->assertFalse(in_array($id, array_map('intval', array_column($repo->selectableCategories(), 'id')), true));
        $this->assertContains($id, array_map('intval', array_column($repo->selectableCategories($id), 'id')));

        $usage = array_column($repo->categoriesWithUsage($pc), null, 'id');
        $this->assertSame(0, (int) $usage[$id]['asset_count']);
        $types = array_column($repo->allWithUsage(), null, 'id');
        $this->assertTrue((int) $types[$pc]['category_count'] >= 5);
    }
}
