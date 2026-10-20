<?php

declare(strict_types=1);

use App\Controllers\Api\OfflineController;
use App\Core\Config;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\MovementService;
use App\Services\OfflineSyncService;

return static function (Container $c): void {
    $c->singleton(OfflineSyncService::class, static fn (Container $c): OfflineSyncService => new OfflineSyncService(
        $c->get(MovementService::class),
        $c->get(MovementRepository::class),
        $c->get(AssetRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(DocumentService::class),
        $c->get(CurrentUser::class),
        $c->get(Config::class)
    ));

    $c->singleton(OfflineController::class, static fn (Container $c): OfflineController => new OfflineController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(OfflineSyncService::class)
    ));
};
