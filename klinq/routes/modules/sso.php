<?php

declare(strict_types=1);

use App\Controllers\LanpaSsoController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Anmeldung über das Intranet (LANPA).
 *
 * Die Windows-Anmeldung findet am auth-Container des Intranets statt. Das Intranet
 * leitet nach erfolgreicher Anmeldung mit einem kurzlebigen, signierten
 * Einstiegsschein auf /sso/lanpa. Die Route ist bewusst ohne Anmeldung erreichbar
 * und legt keine Daten an, die nicht im Schein stehen.
 */
return static function (Router $router, Container $c): void {
    $router->get('/sso/lanpa', static fn (Request $r): Response => $c->get(LanpaSsoController::class)->enter($r), null, true);
};
