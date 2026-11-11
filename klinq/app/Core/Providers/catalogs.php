<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AssetTypeRepository;
use App\Security\CurrentUser;
use App\Services\AssetCatalogService;
use App\Services\AuditLogService;

/** Kataloge der Assetverwaltung (Assettypen, Kategorien) unter „Auswertung & System“. */
return static function (Container $c): void {
    $c->singleton(AssetCatalogService::class, static fn (Container $c): AssetCatalogService => new AssetCatalogService(
        $c->get(AssetTypeRepository::class),
        $c->get(AuditLogService::class)
    ));
    $c->singleton(CatalogController::class, static fn (Container $c): CatalogController => new CatalogController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(AssetTypeRepository::class),
        $c->get(AssetCatalogService::class)
    ));
};
