<?php

declare(strict_types=1);

use App\Controllers\ConsumableController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** „Bestand → Verbrauchsmaterial“ (Verbrauchsartikel mit Bestandsmenge). */
return static function (Router $router, Container $c): void {
    $ctl = static fn (): ConsumableController => $c->get(ConsumableController::class);
    $router->get('/consumables', static fn (Request $r): Response => $ctl()->index($r), 'articles.view');
    $router->post('/consumables/{id}/stock', static fn (Request $r): Response => $ctl()->stock($r), 'articles.manage');
};
