<?php

declare(strict_types=1);

use App\Controllers\AdSyncController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): AdSyncController => $c->get(AdSyncController::class);
    $router->get('/admin/ad-sync', static fn (Request $r): Response => $ctl()->index($r), 'employees.sync');
    $router->get('/admin/ad-sync/runs/{id}', static fn (Request $r): Response => $ctl()->show($r), 'employees.sync');
    $router->post('/admin/ad-sync/run', static fn (Request $r): Response => $ctl()->run($r), 'employees.sync');
};
