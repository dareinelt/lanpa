<?php

declare(strict_types=1);

use App\Controllers\ReportController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\MovementRepository;
use App\Repositories\ReportRepository;
use App\Security\CurrentUser;
use App\Services\DashboardService;
use App\Services\ReportService;

return static function (Container $c): void {
    $c->singleton(ReportRepository::class, static fn (Container $c): ReportRepository => new ReportRepository($c->get(PDO::class)));

    $c->singleton(ReportService::class, static fn (Container $c): ReportService => new ReportService(
        $c->get(ReportRepository::class),
        $c->get(AssetRepository::class),
        $c->get(MovementRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CurrentUser::class)
    ));

    $c->singleton(ReportController::class, static fn (Container $c): ReportController => new ReportController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(ReportService::class),
        $c->get(ReportRepository::class),
        $c->get(DashboardService::class),
        $c->get(AssetTypeRepository::class),
        $c->get(AssetStatusRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(ManufacturerRepository::class),
        $c->get(EmployeeRepository::class)
    ));
};
