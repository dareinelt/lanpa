<?php

declare(strict_types=1);

use App\Controllers\PurchaseOrderController;
use App\Controllers\ProcurementController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\AuditLogService;
use App\Services\DocumentService;
use App\Services\PurchaseOrderService;

return static function (Container $c): void {
    $c->singleton(PurchaseOrderRepository::class, static fn (Container $c): PurchaseOrderRepository => new PurchaseOrderRepository($c->get(PDO::class)));
    $c->singleton(ProcurementRepository::class, static fn (Container $c): ProcurementRepository => new ProcurementRepository($c->get(PDO::class)));

    $c->singleton(PurchaseOrderService::class, static fn (Container $c): PurchaseOrderService => new PurchaseOrderService(
        $c->get(PurchaseOrderRepository::class),
        $c->get(SupplierRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(ArticleRepository::class),
        $c->get(AssetTypeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(AssetRepository::class),
        $c->get(AssetService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));

    $c->singleton(PurchaseOrderController::class, static fn (Container $c): PurchaseOrderController => new PurchaseOrderController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(PurchaseOrderRepository::class),
        $c->get(PurchaseOrderService::class),
        $c->get(SupplierRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(ArticleRepository::class),
        $c->get(AssetTypeRepository::class),
        $c->get(LocationRepository::class),
        $c->get(DocumentRepository::class),
        $c->get(DocumentService::class)
    ));
    $c->singleton(ProcurementController::class, static fn (Container $c): ProcurementController => new ProcurementController(
        $c->get(View::class), $c->get(CurrentUser::class), $c->get(ProcurementRepository::class),
        $c->get(PurchaseOrderRepository::class), $c->get(PurchaseOrderService::class), $c->get(ArticleRepository::class),
        $c->get(SupplierRepository::class), $c->get(CostCenterRepository::class)
    ));
};
