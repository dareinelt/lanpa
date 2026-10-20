<?php

declare(strict_types=1);

use App\Controllers\ReportController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): ReportController => $c->get(ReportController::class);

    $router->get('/reports', static fn (Request $r): Response => $ctl()->index($r), 'reports.view');
    $router->get('/reports/inventory', static fn (Request $r): Response => $ctl()->inventory($r), 'reports.view');
    $router->get('/reports/stock', static fn (Request $r): Response => $ctl()->stock($r), 'reports.view');
    $router->get('/reports/checkouts', static fn (Request $r): Response => $ctl()->checkouts($r), 'reports.view');
    $router->get('/reports/returns', static fn (Request $r): Response => $ctl()->returns($r), 'reports.view');
};
