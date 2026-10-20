<?php

declare(strict_types=1);

use App\Controllers\ArticleController;
use App\Controllers\CostCenterController;
use App\Controllers\EmployeeController;
use App\Controllers\LocationController;
use App\Controllers\ManufacturerController;
use App\Controllers\SupplierController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\ArticleService;
use App\Services\AuditLogService;
use App\Services\CostCenterService;
use App\Services\EmployeeService;
use App\Services\HandoverService;
use App\Services\LocationService;
use App\Services\ManufacturerService;
use App\Services\SupplierService;

return static function (Container $c): void {
    $c->singleton(ManufacturerRepository::class, static fn (Container $c) => new ManufacturerRepository($c->get(PDO::class)));
    $c->singleton(ArticleRepository::class, static fn (Container $c) => new ArticleRepository($c->get(PDO::class)));
    $c->singleton(AssetTypeRepository::class, static fn (Container $c) => new AssetTypeRepository($c->get(PDO::class)));
    $c->singleton(SupplierRepository::class, static fn (Container $c) => new SupplierRepository($c->get(PDO::class)));
    $c->singleton(CostCenterRepository::class, static fn (Container $c) => new CostCenterRepository($c->get(PDO::class)));
    $c->singleton(LocationRepository::class, static fn (Container $c) => new LocationRepository($c->get(PDO::class)));

    $c->singleton(ManufacturerService::class, static fn (Container $c) => new ManufacturerService($c->get(ManufacturerRepository::class), $c->get(AuditLogService::class)));
    $c->singleton(ArticleService::class, static fn (Container $c) => new ArticleService($c->get(ArticleRepository::class), $c->get(ManufacturerRepository::class), $c->get(AssetTypeRepository::class), $c->get(AuditLogService::class)));
    $c->singleton(SupplierService::class, static fn (Container $c) => new SupplierService($c->get(SupplierRepository::class), $c->get(AuditLogService::class)));
    $c->singleton(CostCenterService::class, static fn (Container $c) => new CostCenterService($c->get(CostCenterRepository::class), $c->get(AuditLogService::class)));
    $c->singleton(LocationService::class, static fn (Container $c) => new LocationService($c->get(LocationRepository::class), $c->get(AuditLogService::class)));
    $c->singleton(EmployeeService::class, static fn (Container $c) => new EmployeeService($c->get(EmployeeRepository::class), $c->get(AuditLogService::class)));

    $c->singleton(ManufacturerController::class, static fn (Container $c) => new ManufacturerController($c->get(View::class), $c->get(CurrentUser::class), $c->get(ManufacturerRepository::class), $c->get(ManufacturerService::class)));
    $c->singleton(ArticleController::class, static fn (Container $c) => new ArticleController($c->get(View::class), $c->get(CurrentUser::class), $c->get(ArticleRepository::class), $c->get(ManufacturerRepository::class), $c->get(AssetTypeRepository::class), $c->get(ArticleService::class)));
    $c->singleton(SupplierController::class, static fn (Container $c) => new SupplierController($c->get(View::class), $c->get(CurrentUser::class), $c->get(SupplierRepository::class), $c->get(SupplierService::class)));
    $c->singleton(CostCenterController::class, static fn (Container $c) => new CostCenterController($c->get(View::class), $c->get(CurrentUser::class), $c->get(CostCenterRepository::class), $c->get(LocationRepository::class), $c->get(CostCenterService::class)));
    $c->singleton(LocationController::class, static fn (Container $c) => new LocationController($c->get(View::class), $c->get(CurrentUser::class), $c->get(LocationRepository::class), $c->get(LocationService::class)));
    $c->singleton(EmployeeController::class, static fn (Container $c) => new EmployeeController($c->get(View::class), $c->get(CurrentUser::class), $c->get(EmployeeRepository::class), $c->get(LocationRepository::class), $c->get(CostCenterRepository::class), $c->get(EmployeeService::class), $c->get(AssetRepository::class), $c->get(HandoverService::class)));
};
