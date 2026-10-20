<?php

declare(strict_types=1);

use App\Controllers\DocumentController;
use App\Controllers\Mobile\MobileController;
use App\Controllers\MovementController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $mv = static fn (): MovementController => $c->get(MovementController::class);
    $mob = static fn (): MobileController => $c->get(MobileController::class);
    $doc = static fn (): DocumentController => $c->get(DocumentController::class);

    // Desktop
    $router->get('/movements', static fn (Request $r): Response => $mv()->index($r), 'movements.view');
    $router->get('/movements/open', static fn (Request $r): Response => $mv()->open($r), 'movements.view');
    $router->get('/movements/checkout', static fn (Request $r): Response => $mv()->checkoutCaptureForm($r), 'movements.checkout');
    $router->post('/movements/checkout', static fn (Request $r): Response => $mv()->checkoutCaptureSubmit($r), 'movements.checkout');
    $router->get('/movements/return', static fn (Request $r): Response => $mv()->returnCaptureForm($r), 'movements.return');
    $router->post('/movements/return', static fn (Request $r): Response => $mv()->returnCaptureSubmit($r), 'movements.return');
    $router->get('/movements/{id}', static fn (Request $r): Response => $mv()->show($r), 'movements.view');
    $router->post('/movements/{id}', static fn (Request $r): Response => $mv()->update($r), 'movements.complete');
    $router->post('/movements/{id}/cancel', static fn (Request $r): Response => $mv()->cancel($r), 'movements.complete');

    // Dokumente (Fotos, Belege)
    $router->get('/documents/{id}', static fn (Request $r): Response => $doc()->show($r), 'documents.view');
    $router->post('/documents/{id}/delete', static fn (Request $r): Response => $doc()->delete($r), 'documents.view');

    // Mobile Erfassung
    $router->get('/m', static fn (Request $r): Response => $mob()->scan($r), 'assets.view');
    $router->get('/m/lookup', static fn (Request $r): Response => $mob()->lookup($r), 'assets.view');
    $router->get('/m/open', static fn (Request $r): Response => $mob()->open($r), 'movements.view');
    $router->get('/m/asset/{inventory}', static fn (Request $r): Response => $mob()->asset($r), 'assets.view');
    $router->get('/m/checkout', static fn (Request $r): Response => $mob()->checkoutForm($r), 'movements.checkout');
    $router->post('/m/checkout', static fn (Request $r): Response => $mob()->checkout($r), 'movements.checkout');
    $router->get('/m/return', static fn (Request $r): Response => $mob()->returnForm($r), 'movements.return');
    $router->post('/m/return', static fn (Request $r): Response => $mob()->returnAsset($r), 'movements.return');
    $router->get('/m/done/{id}', static fn (Request $r): Response => $mob()->done($r), 'movements.view');
};
