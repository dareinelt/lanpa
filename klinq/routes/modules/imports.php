<?php

declare(strict_types=1);

use App\Controllers\ImportController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): ImportController => $c->get(ImportController::class);

    $router->get('/imports', static fn (Request $r): Response => $ctl()->index($r), 'imports.manage');
    $router->get('/imports/template', static fn (): Response => $ctl()->template(), 'imports.manage');
    $router->post('/imports', static fn (Request $r): Response => $ctl()->upload($r), 'imports.manage');
    $router->get('/imports/{id}', static fn (Request $r): Response => $ctl()->show($r), 'imports.manage');
    $router->post('/imports/{id}/commit', static fn (Request $r): Response => $ctl()->commit($r), 'imports.manage');
    $router->post('/imports/{id}/cancel', static fn (Request $r): Response => $ctl()->cancel($r), 'imports.manage');
    $router->get('/imports/{id}/errors', static fn (Request $r): Response => $ctl()->errors($r), 'imports.manage');
};
