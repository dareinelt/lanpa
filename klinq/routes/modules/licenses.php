<?php

declare(strict_types=1);

use App\Controllers\LicenseController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): LicenseController => $c->get(LicenseController::class);

    $router->get('/licenses', static fn (Request $r): Response => $ctl()->index($r), 'licenses.view');
    $router->get('/licenses/new', static fn (Request $r): Response => $ctl()->create($r), 'licenses.manage');
    $router->post('/licenses', static fn (Request $r): Response => $ctl()->store($r), 'licenses.manage');
    $router->get('/licenses/{id}', static fn (Request $r): Response => $ctl()->show($r), 'licenses.view');
    $router->get('/licenses/{id}/edit', static fn (Request $r): Response => $ctl()->edit($r), 'licenses.manage');
    $router->post('/licenses/{id}', static fn (Request $r): Response => $ctl()->update($r), 'licenses.manage');
    $router->post('/licenses/{id}/toggle', static fn (Request $r): Response => $ctl()->toggle($r), 'licenses.manage');

    $router->post('/licenses/{id}/assign', static fn (Request $r): Response => $ctl()->assign($r), 'licenses.manage');
    $router->post('/licenses/{id}/assignments/{assignment}/release', static fn (Request $r): Response => $ctl()->release($r), 'licenses.manage');

    $router->post('/licenses/{id}/documents', static fn (Request $r): Response => $ctl()->uploadDocument($r), 'documents.manage');
};
