<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Security\CurrentUser;
use App\Services\ArticleService;
use App\Services\AssetService;
use App\Services\CostCenterService;
use App\Services\DuplicateWarningException;
use App\Services\LocationService;
use App\Services\ManufacturerService;
use Tests\Support\DatabaseTestCase;

final class MasterDataIntegrationTest extends DatabaseTestCase
{
    public function testLocationPathsAreRecomputedWhenParentIsRenamed(): void
    {
        $svc = $this->c->get(LocationService::class);
        $repo = $this->c->get(LocationRepository::class);

        $site = $svc->create(['name' => 'Peine', 'type' => 'site', 'is_active' => '1']);
        $building = $svc->create(['name' => 'Gebäude A', 'type' => 'building', 'parent_id' => (string) $site, 'is_active' => '1']);
        $room = $svc->create(['name' => 'Raum 2.14', 'type' => 'room', 'parent_id' => (string) $building, 'is_active' => '1']);

        $this->assertSame('Peine / Gebäude A / Raum 2.14', $repo->find($room)['full_path']);
        $this->assertSame(2, (int) $repo->find($room)['depth']);

        $svc->update($building, $repo->find($building), ['name' => 'Haus 1', 'type' => 'building', 'parent_id' => (string) $site, 'is_active' => '1']);
        $this->assertSame('Peine / Haus 1 / Raum 2.14', $repo->find($room)['full_path']);

        $this->assertThrows(ValidationException::class, function () use ($svc, $repo, $site, $room): void {
            $svc->update($site, $repo->find($site), ['name' => 'Peine', 'type' => 'site', 'parent_id' => (string) $room, 'is_active' => '1']);
        }, 'Unterstandort');
    }

    public function testDeactivatingLocationCascadesToChildren(): void
    {
        $svc = $this->c->get(LocationService::class);
        $repo = $this->c->get(LocationRepository::class);
        $site = $svc->create(['name' => 'Hannover', 'type' => 'site', 'is_active' => '1']);
        $room = $svc->create(['name' => 'Lager', 'type' => 'warehouse', 'parent_id' => (string) $site, 'is_active' => '1']);

        $svc->setActive($site, $repo->find($site), false);
        $this->assertSame(0, (int) $repo->find($room)['is_active']);
    }

    public function testMoveChangesParentAndSiblingOrder(): void
    {
        $svc = $this->c->get(LocationService::class);
        $repo = $this->c->get(LocationRepository::class);
        $site = $svc->create(['name' => 'Wolfsburg', 'type' => 'site', 'is_active' => '1']);
        $a = $svc->create(['name' => 'Haus A', 'type' => 'building', 'parent_id' => (string) $site, 'is_active' => '1']);
        $b = $svc->create(['name' => 'Haus B', 'type' => 'building', 'parent_id' => (string) $site, 'is_active' => '1']);
        $floor = $svc->create(['name' => 'EG', 'type' => 'floor', 'parent_id' => (string) $a, 'is_active' => '1']);
        $room = $svc->create(['name' => 'Raum 1', 'type' => 'room', 'parent_id' => (string) $floor, 'is_active' => '1']);

        // Reihenfolge: Haus B vor Haus A
        $svc->move($b, $repo->find($b), $site, [$b, $a]);
        $this->assertSame(['Haus B', 'Haus A'], array_column($repo->children($site), 'name'));

        // EG samt Raum unter Haus B verschieben
        $moved = $svc->move($floor, $repo->find($floor), $b, [$floor]);
        $this->assertSame($b, (int) $moved['parent_id']);
        $this->assertSame('Wolfsburg / Haus B / EG / Raum 1', $repo->find($room)['full_path']);
        $this->assertSame(3, (int) $repo->find($room)['depth']);

        // Zur Wurzel verschieben
        $svc->move($floor, $repo->find($floor), null, [$floor]);
        $this->assertSame('EG / Raum 1', $repo->find($room)['full_path']);
        $this->assertSame(0, (int) $repo->find($floor)['depth']);

        $this->assertThrows(ValidationException::class, function () use ($svc, $repo, $floor, $room): void {
            $svc->move($floor, $repo->find($floor), $room, [$floor]);
        }, 'Unterstandort');

        $svc->create(['name' => 'EG', 'type' => 'floor', 'parent_id' => (string) $a, 'is_active' => '1']);
        $this->assertThrows(ValidationException::class, function () use ($svc, $repo, $floor, $a): void {
            $svc->move($floor, $repo->find($floor), $a, [$floor]);
        }, 'bereits');
    }

    public function testManufacturerDuplicateWarningAndOverride(): void
    {
        $svc = $this->c->get(ManufacturerService::class);
        $repo = $this->c->get(ManufacturerRepository::class);
        $id = $svc->create(['name' => 'Hewlett Packard', 'is_active' => '1']);
        $this->assertNotNull($repo->find($id));

        $e = $this->assertThrows(DuplicateWarningException::class, static function () use ($svc): void {
            $svc->create(['name' => 'Hewlet Packard', 'is_active' => '1']);
        });
        $this->assertSame('Hewlett Packard', $e->duplicates()[0]['name']);

        $second = $svc->create(['name' => 'Hewlet Packard', 'is_active' => '1'], true);
        $this->assertTrue($second !== $id);
        $this->assertCount(2, $repo->search(['q' => 'packard', 'active' => ''], 50, 0));
    }

    public function testCostCenterNumberMustBeUnique(): void
    {
        $svc = $this->c->get(CostCenterService::class);
        $repo = $this->c->get(CostCenterRepository::class);
        $svc->create(['number' => '77777', 'description' => 'Test', 'is_active' => '1']);
        $this->assertNotNull($repo->findByNumber('77777'));
        $this->assertThrows(ValidationException::class, static function () use ($svc): void {
            $svc->create(['number' => '77777', 'description' => 'Doppelt', 'is_active' => '1']);
        });
        $this->assertThrows(ValidationException::class, static function () use ($svc): void {
            $svc->create(['number' => '7777', 'description' => 'Zu kurz', 'is_active' => '1']);
        });
    }

    public function testConsumableArticleNeedsNoAssetType(): void
    {
        $svc = $this->c->get(ArticleService::class);
        $repo = $this->c->get(ArticleRepository::class);
        $manufacturerId = $this->c->get(ManufacturerService::class)->create(['name' => 'Ohne-Typ GmbH', 'is_active' => '1'], true);

        $id = $svc->create([
            'manufacturer_id' => (string) $manufacturerId,
            'name' => 'Toner ohne Typ 0815',
            'is_active' => '1',
            'is_consumable' => '1',
            'minimum_stock' => '3',
            'stock_quantity' => '7',
        ], true);
        $created = $repo->find($id);
        $this->assertNull($created['asset_type_id']);
        $this->assertNull($created['asset_category_id']);
        $this->assertSame(3, (int) $created['minimum_stock']);

        // Ein trotzdem übermittelter Assettyp wird bei Verbrauchsmaterial verworfen
        $withType = $svc->create([
            'manufacturer_id' => (string) $manufacturerId,
            'asset_type_id' => (string) $this->c->get(AssetTypeRepository::class)->findByCode('ZUB')['id'],
            'name' => 'Toner ohne Typ 0816',
            'is_active' => '1',
            'is_consumable' => '1',
            'stock_quantity' => '0',
        ], true);
        $this->assertNull($repo->find($withType)['asset_type_id']);

        // Verbrauchsmaterial erzeugt keine Assets und erscheint deshalb nicht im Asset-Picker
        $this->assertSame([], $repo->searchForPicker('Toner ohne Typ'));
        $this->assertContains($id, array_map('intval', array_column($repo->consumables(['q' => 'Toner ohne Typ']), 'id')));
    }

    public function testNonConsumableArticleStillRequiresAssetType(): void
    {
        $svc = $this->c->get(ArticleService::class);
        $manufacturerId = $this->c->get(ManufacturerService::class)->create(['name' => 'Mit-Typ GmbH', 'is_active' => '1'], true);

        $this->assertThrows(ValidationException::class, fn (): int => $svc->create([
            'manufacturer_id' => (string) $manufacturerId,
            'name' => 'Notebook ohne Typ 0815',
            'is_active' => '1',
            'stock_quantity' => '0',
        ], true), 'Assettyp');
    }

    public function testClearingConsumableResetsStockForArticleWithoutCategory(): void
    {
        $svc = $this->c->get(ArticleService::class);
        $repo = $this->c->get(ArticleRepository::class);
        $manufacturerId = $this->c->get(ManufacturerService::class)->create(['name' => 'Verbrauchstest GmbH', 'is_active' => '1'], true);
        $input = [
            'manufacturer_id' => (string) $manufacturerId,
            'asset_type_id' => (string) $this->c->get(AssetTypeRepository::class)->findByCode('ZUB')['id'],
            'asset_category_id' => '',
            'name' => 'Toner Testmodell 4711',
            'is_active' => '1',
            'is_consumable' => '1',
            'minimum_stock' => '5',
            'stock_quantity' => '12',
        ];
        $id = $svc->create($input, true);
        $created = $repo->find($id);
        $this->assertNull($created['asset_category_id']);
        $this->assertSame(5, (int) $created['minimum_stock']);
        $this->assertSame(12, (int) $created['stock_quantity']);

        // Haken „Verbrauchsmaterial“ entfernen: Bestandsfelder müssen auch ohne Kategorie zurückgesetzt werden
        $svc->update($id, $created, array_merge($input, ['is_consumable' => '0']), true);
        $updated = $repo->find($id);
        $this->assertSame(0, (int) $updated['is_consumable']);
        $this->assertNull($updated['minimum_stock']);
        $this->assertSame(0, (int) $updated['stock_quantity']);
    }

    public function testArticleWithExistingAssetsCannotBecomeConsumableOrChangeAssetType(): void
    {
        $this->c->get(CurrentUser::class)->login(['id' => 1, 'username' => 'tester', 'display_name' => 'Test User', 'role' => 'admin']);
        $svc = $this->c->get(ArticleService::class);
        $repo = $this->c->get(ArticleRepository::class);
        $types = $this->c->get(AssetTypeRepository::class);
        $pcTypeId = (int) $types->findByCode('PC')['id'];
        $zubTypeId = (int) $types->findByCode('ZUB')['id'];
        $manufacturerId = $this->c->get(ManufacturerService::class)->create(['name' => 'Bestandsschutz GmbH', 'is_active' => '1'], true);

        $articleId = $svc->create([
            'manufacturer_id' => (string) $manufacturerId,
            'asset_type_id' => (string) $pcTypeId,
            'name' => 'Workstation Pro 900',
            'is_active' => '1',
        ], true);
        $this->c->get(AssetService::class)->create([
            'asset_type_id' => (string) $pcTypeId,
            'article_id' => (string) $articleId,
            'name' => 'Rechner Buchhaltung',
        ]);
        $existing = $repo->find($articleId);
        $this->assertSame(1, (int) $existing['asset_count']);

        $this->assertThrows(ValidationException::class, fn () => $svc->update($articleId, $existing, [
            'manufacturer_id' => (string) $manufacturerId,
            'name' => 'Workstation Pro 900',
            'is_active' => '1',
            'is_consumable' => '1',
        ], true), 'Assets zugeordnet');

        $this->assertThrows(ValidationException::class, fn () => $svc->update($articleId, $existing, [
            'manufacturer_id' => (string) $manufacturerId,
            'asset_type_id' => (string) $zubTypeId,
            'name' => 'Workstation Pro 900',
            'is_active' => '1',
        ], true), 'Assettyp kann nicht geändert werden');

        // Normale Stammdatenpflege bei gleichem Assettyp bleibt uneingeschränkt möglich
        $svc->update($articleId, $existing, [
            'manufacturer_id' => (string) $manufacturerId,
            'asset_type_id' => (string) $pcTypeId,
            'name' => 'Workstation Pro 900 G2',
            'article_number' => 'WP-900-G2',
            'is_active' => '1',
        ], true);
        $this->assertSame('WP-900-G2', $repo->find($articleId)['article_number']);

        $pickerRows = $repo->searchForPicker('Workstation Pro 900 G2', $pcTypeId);
        $this->assertCount(1, $pickerRows);
        $this->assertSame(0, (int) $pickerRows[0]['is_consumable']);
    }

    public function testConsumableRelativeStockAdjustmentClampsAtZero(): void
    {
        $svc = $this->c->get(ArticleService::class);
        $repo = $this->c->get(ArticleRepository::class);
        $manufacturerId = $this->c->get(ManufacturerService::class)->create(['name' => 'Lagerkomfort GmbH', 'is_active' => '1'], true);
        $id = $svc->create([
            'manufacturer_id' => (string) $manufacturerId,
            'name' => 'Patchkabel Cat6a 2m',
            'is_active' => '1',
            'is_consumable' => '1',
            'is_handover_relevant' => '1',
            'stock_quantity' => '4',
        ], true);
        $created = $repo->find($id);
        $this->assertSame(0, (int) $created['is_handover_relevant']);

        $this->assertSame(3, $svc->setStock($id, $repo->find($id), ['delta' => '-1']));
        $this->assertSame(5, $svc->setStock($id, $repo->find($id), ['delta' => '2']));
        $this->assertSame(0, $svc->setStock($id, $repo->find($id), ['delta' => '-50']));
    }

    public function testSelectableMasterDataPreservesCurrentDeactivatedAssignments(): void
    {
        $locSvc = $this->c->get(LocationService::class);
        $locRepo = $this->c->get(LocationRepository::class);
        $siteId = $locSvc->create(['name' => 'Altstandort', 'type' => 'site', 'is_active' => '1']);
        $roomId = $locSvc->create(['name' => 'Serverraum Alt', 'type' => 'room', 'parent_id' => (string) $siteId, 'is_active' => '1']);
        $locSvc->setActive($siteId, $locRepo->find($siteId), false);

        $this->assertFalse(in_array($roomId, array_map('intval', array_column($locRepo->selectable(), 'id')), true));
        $withCurrent = LocationService::flatten($locRepo->selectable($roomId));
        $byId = array_column($withCurrent, null, 'id');
        $this->assertTrue(isset($byId[$siteId], $byId[$roomId]));
        $this->assertSame(1, (int) $byId[$roomId]['depth']);

        $empRepo = $this->c->get(EmployeeRepository::class);
        $empId = $empRepo->create(['display_name' => 'Erika Ausgeschieden', 'last_name' => 'Ausgeschieden', 'is_active' => 0]);
        $this->assertFalse(in_array($empId, array_map('intval', array_column($empRepo->activeForSelect(), 'id')), true));
        $this->assertContains($empId, array_map('intval', array_column($empRepo->activeForSelect($empId), 'id')));
    }
}
