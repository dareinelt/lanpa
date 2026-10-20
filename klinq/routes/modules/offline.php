<?php

declare(strict_types=1);

use App\Controllers\Api\OfflineController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): OfflineController => $c->get(OfflineController::class);

    // Stammdaten-Cache für die Offline-Erfassung
    $router->get('/api/offline/bootstrap', static fn (Request $r): Response => $ctl()->bootstrap($r), 'assets.view');
    // Synchronisation offline erfasster Vorgänge; die Rechte je Vorgang prüft der MovementService
    // Basisrecht; Entnahme/Retoure/Ausmusterung werden je Vorgang im MovementService geprüft
    $router->post('/api/offline/sync', static fn (Request $r): Response => $ctl()->sync($r), 'movements.view');
};
