<?php

declare(strict_types=1);

use App\Controllers\AuditController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\AuditLogRepository;
use App\Security\CurrentUser;

return static function (Container $c): void {
    $c->singleton(AuditController::class, static fn (Container $c): AuditController => new AuditController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(AuditLogRepository::class)
    ));
};
