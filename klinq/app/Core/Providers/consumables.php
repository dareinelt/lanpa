<?php

declare(strict_types=1);

use App\Controllers\ConsumableController;
use App\Core\Container;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Security\CurrentUser;
use App\Services\ArticleService;

/** Verbrauchsmaterial-Übersicht der Assetverwaltung. */
return static function (Container $c): void {
    $c->singleton(ConsumableController::class, static fn (Container $c): ConsumableController => new ConsumableController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(ArticleRepository::class),
        $c->get(ArticleService::class)
    ));
};
