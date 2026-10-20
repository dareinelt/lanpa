<?php

declare(strict_types=1);

use App\Controllers\LicenseController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\AuditLogService;
use App\Services\DocumentService;
use App\Services\LicenseService;
use App\Services\MovementService;

return static function (Container $c): void {
    $c->singleton(LicenseRepository::class, static fn (Container $c): LicenseRepository => new LicenseRepository($c->get(PDO::class)));

    $c->singleton(LicenseService::class, static fn (Container $c): LicenseService => new LicenseService(
        $c->get(LicenseRepository::class),
        $c->get(ManufacturerRepository::class),
        $c->get(SupplierRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(PurchaseOrderRepository::class),
        $c->get(AssetRepository::class),
        $c->get(AssetService::class),
        $c->get(MovementService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));

    $c->singleton(LicenseController::class, static fn (Container $c): LicenseController => new LicenseController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(LicenseRepository::class),
        $c->get(LicenseService::class),
        $c->get(ManufacturerRepository::class),
        $c->get(SupplierRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(PurchaseOrderRepository::class),
        $c->get(DocumentRepository::class),
        $c->get(DocumentService::class)
    ));
};
