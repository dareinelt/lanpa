<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Routen-Registrierung. Signatur: get/post(pattern, handler, permission, public)
 *  - permission = null  → nur Anmeldung erforderlich
 *  - permission = 'x.y' → Berechtigung erforderlich
 *  - public = true      → ohne Anmeldung erreichbar
 */
return static function (Router $router, Container $c): void {
    $basePath = $c->get('basePath');

    // Öffentlich
    $router->get('/login', static fn (Request $r): Response => $c->get(AuthController::class)->showLogin($r), null, true);
    $router->post('/login', static fn (Request $r): Response => $c->get(AuthController::class)->login($r), null, true);
    $router->post('/logout', static fn (Request $r): Response => $c->get(AuthController::class)->logout($r));
    $router->get('/health', static fn (): Response => Response::json(['status' => 'ok']), null, true);

    // Angemeldet
    $router->get('/', static fn (): Response => Response::redirect('/dashboard'));
    $router->get('/dashboard', static fn (Request $r): Response => $c->get(DashboardController::class)->index($r));

    foreach (glob($basePath . '/routes/modules/*.php') ?: [] as $module) {
        $register = require $module;
        $register($router, $c);
    }
};
