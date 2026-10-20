<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AssetController;
use App\Controllers\LabelController;
use App\Core\Config;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\LabelService;
use App\Services\SettingsService;

return static function (Container $c): void {
    $c->singleton(LabelService::class, static fn (Container $c): LabelService => new LabelService(
        $c->get(SettingsService::class),
        $c->get(AssetRepository::class),
        $c->get(AssetHistoryRepository::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(Config::class),
        $c->get('basePath') . '/storage'
    ));
    $c->singleton(LabelController::class, static fn (Container $c): LabelController => new LabelController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(LabelService::class),
        $c->get(AssetRepository::class),
        $c->get(AssetController::class)
    ));
    $c->singleton(AdminController::class, static fn (Container $c): AdminController => new AdminController($c->get(View::class), $c->get(CurrentUser::class)));
};
