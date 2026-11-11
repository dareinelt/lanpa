<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/** „Auswertung & System → Kataloge“ (nur Admins, wie das gesamte Modul). */
return static function (Router $router, Container $c): void {
    $ctl = static fn (): CatalogController => $c->get(CatalogController::class);
    $router->get('/catalogs', static fn (Request $r): Response => $ctl()->index($r), 'settings.manage');
    foreach (array_keys(CatalogController::KINDS) as $kind) {
        $router->get('/catalogs/' . $kind . '/new', static fn (Request $r): Response => $ctl()->create($r), 'settings.manage');
        $router->post('/catalogs/' . $kind, static fn (Request $r): Response => $ctl()->store($r), 'settings.manage');
        $router->get('/catalogs/' . $kind . '/{id}/edit', static fn (Request $r): Response => $ctl()->edit($r), 'settings.manage');
        $router->post('/catalogs/' . $kind . '/{id}', static fn (Request $r): Response => $ctl()->update($r), 'settings.manage');
        $router->post('/catalogs/' . $kind . '/{id}/toggle-active', static fn (Request $r): Response => $ctl()->toggleActive($r), 'settings.manage');
    }
};
