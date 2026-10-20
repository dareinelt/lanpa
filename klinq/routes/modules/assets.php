<?php

declare(strict_types=1);

use App\Controllers\AssetController;
use App\Controllers\SearchController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): AssetController => $c->get(AssetController::class);

    $router->get('/assets', static fn (Request $r): Response => $ctl()->index($r), 'assets.view');
    $router->get('/assets/new', static fn (Request $r): Response => $ctl()->create($r), 'assets.manage');
    $router->post('/assets', static fn (Request $r): Response => $ctl()->store($r), 'assets.manage');
    $router->get('/assets/{id}/edit', static fn (Request $r): Response => $ctl()->edit($r), 'assets.manage');
    $router->post('/assets/{id}', static fn (Request $r): Response => $ctl()->update($r), 'assets.manage');
    $router->post('/assets/{id}/status', static fn (Request $r): Response => $ctl()->changeStatus($r), 'assets.manage');
    $router->post('/assets/{id}/note', static fn (Request $r): Response => $ctl()->addNote($r), 'assets.manage');
    $router->get('/assets/{id}', static fn (Request $r): Response => $ctl()->show($r), 'assets.view');

    $router->get('/api/assets/search', static fn (Request $r): Response => $ctl()->search($r), 'assets.view');
    $router->get('/api/assets/check', static fn (Request $r): Response => $ctl()->check($r), 'assets.view');
    $router->get('/api/assets/last-inventory-number', static fn (Request $r): Response => $ctl()->lastInventoryNumber($r), 'assets.manage');

    $search = static fn (): SearchController => $c->get(SearchController::class);
    $router->get('/api/search', static fn (Request $r): Response => $search()->api($r), 'dashboard.view');
    $router->get('/search', static fn (Request $r): Response => $search()->page($r), 'dashboard.view');
};
