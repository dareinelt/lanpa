<?php

declare(strict_types=1);

use App\Controllers\ImportController;
use App\Core\Config;
use App\Core\Container;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\ImportRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\ArticleService;
use App\Services\AuditLogService;
use App\Services\ImportService;
use App\Services\ManufacturerService;

return static function (Container $c): void {
    $c->singleton(ImportRepository::class, static fn (Container $c): ImportRepository => new ImportRepository($c->get(PDO::class)));

    $c->singleton(ImportService::class, static fn (Container $c): ImportService => new ImportService(
        $c->get(ImportRepository::class),
        $c->get(AssetService::class),
        $c->get(AssetRepository::class),
        $c->get(AssetTypeRepository::class),
        $c->get(AssetStatusRepository::class),
        $c->get(ManufacturerRepository::class),
        $c->get(ManufacturerService::class),
        $c->get(ArticleRepository::class),
        $c->get(ArticleService::class),
        $c->get(EmployeeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(SupplierRepository::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(Config::class)
    ));

    $c->singleton(ImportController::class, static fn (Container $c): ImportController => new ImportController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(ImportService::class),
        $c->get(ImportRepository::class)
    ));
};
