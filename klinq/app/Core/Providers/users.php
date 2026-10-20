<?php

declare(strict_types=1);

use App\Controllers\ProfileController;
use App\Controllers\UserController;
use App\Core\Container;
use App\Core\SessionManager;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\PasswordHasher;
use App\Security\Permissions;
use App\Services\AuditLogService;
use App\Services\UserService;

return static function (Container $c): void {
    $c->singleton(UserService::class, static fn (Container $c) => new UserService(
        $c->get(UserRepository::class),
        $c->get(PasswordHasher::class),
        $c->get(Permissions::class),
        $c->get(CurrentUser::class),
        $c->get(AuditLogService::class)
    ));
    $c->singleton(UserController::class, static fn (Container $c) => new UserController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(UserRepository::class),
        $c->get(UserService::class),
        $c->get(Permissions::class)
    ));
    $c->singleton(ProfileController::class, static fn (Container $c) => new ProfileController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(UserRepository::class),
        $c->get(UserService::class),
        $c->get(SessionManager::class)
    ));
};
