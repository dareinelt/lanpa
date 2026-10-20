<?php

declare(strict_types=1);

use App\Controllers\LabelController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): LabelController => $c->get(LabelController::class);

    $router->get('/labels', static fn (Request $r): Response => $ctl()->print($r), 'labels.print');
    $router->post('/api/labels/printed', static fn (Request $r): Response => $ctl()->printed($r), 'labels.print');
    $router->get('/labels/style.css', static fn (Request $r): Response => $ctl()->stylesheet($r), 'labels.print');
    $router->get('/labels/logo', static fn (Request $r): Response => $ctl()->logo($r), 'assets.view');
    // QR-Ziel: Inventarnummer auflösen
    $router->get('/a/{inventory}', static fn (Request $r): Response => $ctl()->resolve($r), 'assets.view');

    $router->get('/admin/labels', static fn (Request $r): Response => $ctl()->settings($r), 'settings.manage');
    $router->post('/admin/labels', static fn (Request $r): Response => $ctl()->saveSettings($r), 'settings.manage');
    $router->post('/api/labels/preview', static fn (Request $r): Response => $ctl()->preview($r), 'settings.manage');
};
