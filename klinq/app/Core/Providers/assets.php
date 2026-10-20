<?php

declare(strict_types=1);

use App\Controllers\AssetController;
use App\Controllers\SearchController;
use App\Core\Config;
use App\Core\Container;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\MovementRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\AuditLogService;
use App\Services\InventoryNumberService;
use App\Services\SearchService;

return static function (Container $c): void {
    $c->singleton(AssetRepository::class, static fn (Container $c): AssetRepository => new AssetRepository($c->get(PDO::class)));
    $c->singleton(AssetStatusRepository::class, static fn (Container $c): AssetStatusRepository => new AssetStatusRepository($c->get(PDO::class)));
    $c->singleton(AssetHistoryRepository::class, static fn (Container $c): AssetHistoryRepository => new AssetHistoryRepository($c->get(PDO::class)));
    $c->singleton(InventoryNumberService::class, static fn (Container $c): InventoryNumberService => new InventoryNumberService($c->get(PDO::class)));

    $c->singleton(AssetService::class, static fn (Container $c): AssetService => new AssetService(
        $c->get(AssetRepository::class),
        $c->get(AssetTypeRepository::class),
        $c->get(AssetStatusRepository::class),
        $c->get(ArticleRepository::class),
        $c->get(AssetHistoryRepository::class),
        $c->get(InventoryNumberService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(SearchService::class, static fn (Container $c): SearchService => new SearchService(
        $c->get(AssetRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(ManufacturerRepository::class),
        $c->get(ArticleRepository::class),
        $c->get(SupplierRepository::class),
        $c->get(CurrentUser::class),
        $c->get(TicketRepository::class),
        (bool) $c->get(Config::class)->get('helpdesk.enabled', true)
    ));

    $c->singleton(AssetController::class, static fn (Container $c): AssetController => new AssetController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(AssetRepository::class),
        $c->get(AssetTypeRepository::class),
        $c->get(AssetStatusRepository::class),
        $c->get(AssetHistoryRepository::class),
        $c->get(ManufacturerRepository::class),
        $c->get(ArticleRepository::class),
        $c->get(SupplierRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(MovementRepository::class),
        $c->get(LicenseRepository::class),
        $c->get(AssetService::class),
        $c->get(InventoryNumberService::class),
        $c->get(TicketRepository::class),
        (bool) $c->get(Config::class)->get('helpdesk.enabled', true)
    ));
    $c->singleton(SearchController::class, static fn (Container $c): SearchController => new SearchController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(SearchService::class)
    ));
};
