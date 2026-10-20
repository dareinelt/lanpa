<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Exceptions\ValidationException;
use App\Repositories\CostCenterRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
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
}
