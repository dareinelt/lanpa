<?php

declare(strict_types=1);

use App\Controllers\AuditController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $router->get('/audit', static fn (Request $r): Response => $c->get(AuditController::class)->index($r), 'audit.view');
};
