<?php

declare(strict_types=1);

use App\Controllers\ArticleController;
use App\Controllers\CostCenterController;
use App\Controllers\EmployeeController;
use App\Controllers\LocationController;
use App\Controllers\ManufacturerController;
use App\Controllers\SupplierController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    // Standard-CRUD-Routen: [Pfad, Controller, Rechtebereich]
    $crud = [
        ['/manufacturers', ManufacturerController::class, 'manufacturers'],
        ['/articles', ArticleController::class, 'articles'],
        ['/suppliers', SupplierController::class, 'suppliers'],
        ['/cost-centers', CostCenterController::class, 'costcenters'],
        ['/employees', EmployeeController::class, 'employees'],
    ];
    foreach ($crud as [$path, $controller, $area]) {
        $ctl = static fn (): object => $c->get($controller);
        $router->get($path, static fn (Request $r): Response => $ctl()->index($r), "{$area}.view");
        $router->get("{$path}/new", static fn (Request $r): Response => $ctl()->create($r), "{$area}.manage");
        $router->post($path, static fn (Request $r): Response => $ctl()->store($r), "{$area}.manage");
        $router->get("{$path}/{id}/edit", static fn (Request $r): Response => $ctl()->edit($r), "{$area}.manage");
        $router->post("{$path}/{id}", static fn (Request $r): Response => $ctl()->update($r), "{$area}.manage");
        $router->post("{$path}/{id}/toggle-active", static fn (Request $r): Response => $ctl()->toggleActive($r), "{$area}.manage");
        if (method_exists($controller, 'show')) {
            $router->get("{$path}/{id}", static fn (Request $r): Response => $ctl()->show($r), "{$area}.view");
        }
    }

    $router->get('/api/manufacturers/check', static fn (Request $r): Response => $c->get(ManufacturerController::class)->checkDuplicates($r), 'manufacturers.view');
    $router->get('/api/articles/check', static fn (Request $r): Response => $c->get(ArticleController::class)->checkDuplicates($r), 'articles.view');
    $router->get('/api/articles/search', static fn (Request $r): Response => $c->get(ArticleController::class)->search($r), 'articles.view');
    $router->get('/api/employees/search', static fn (Request $r): Response => $c->get(EmployeeController::class)->search($r), 'employees.view');
    $router->get('/api/locations/search', static fn (Request $r): Response => $c->get(LocationController::class)->search($r), 'locations.view');

    // Standorte (Baum)
    $loc = static fn (): LocationController => $c->get(LocationController::class);
    $router->get('/locations', static fn (Request $r): Response => $loc()->index($r), 'locations.view');
    $router->get('/locations/new', static fn (Request $r): Response => $loc()->create($r), 'locations.manage');
    $router->post('/locations', static fn (Request $r): Response => $loc()->store($r), 'locations.manage');
    $router->get('/locations/{id}/edit', static fn (Request $r): Response => $loc()->edit($r), 'locations.manage');
    $router->post('/locations/{id}', static fn (Request $r): Response => $loc()->update($r), 'locations.manage');
    $router->post('/locations/{id}/toggle-active', static fn (Request $r): Response => $loc()->toggleActive($r), 'locations.manage');
    $router->get('/locations/{id}', static fn (Request $r): Response => $loc()->show($r), 'locations.view');
};
