<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Services\ArticleService;
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
}
