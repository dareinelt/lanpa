<?php

declare(strict_types=1);

use App\Controllers\PurchaseOrderController;
use App\Controllers\ProcurementController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $ctl = static fn (): PurchaseOrderController => $c->get(PurchaseOrderController::class);
    $procurement = static fn (): ProcurementController => $c->get(ProcurementController::class);

    $router->get('/orders', static fn (Request $r): Response => $ctl()->index($r), 'orders.view');
    $router->get('/orders/templates', static fn (Request $r): Response => $procurement()->templates($r), 'orders.view');
    $router->post('/orders/templates/{id}/use', static fn (Request $r): Response => $procurement()->useTemplate($r), 'orders.manage');
    $router->get('/orders/requests', static fn (Request $r): Response => $procurement()->requests($r), 'orders.view');
    $router->post('/orders/requests', static fn (Request $r): Response => $procurement()->storeRequest($r), 'orders.view');
    $router->post('/orders/requests/{id}/convert', static fn (Request $r): Response => $procurement()->convertRequest($r), 'orders.manage');
    $router->get('/orders/replenishment', static fn (Request $r): Response => $procurement()->replenishment($r), 'orders.view');
    $router->post('/orders/replenishment', static fn (Request $r): Response => $procurement()->createReplenishment($r), 'orders.manage');
    $router->get('/orders/new', static fn (Request $r): Response => $ctl()->create($r), 'orders.manage');
    $router->post('/orders', static fn (Request $r): Response => $ctl()->store($r), 'orders.manage');
    $router->get('/orders/{id}', static fn (Request $r): Response => $ctl()->show($r), 'orders.view');
    $router->get('/orders/{id}/edit', static fn (Request $r): Response => $ctl()->edit($r), 'orders.manage');
    $router->post('/orders/{id}', static fn (Request $r): Response => $ctl()->update($r), 'orders.manage');
    $router->post('/orders/{id}/status', static fn (Request $r): Response => $ctl()->status($r), 'orders.manage');

    $router->post('/orders/{id}/items', static fn (Request $r): Response => $ctl()->storeItem($r), 'orders.manage');
    $router->post('/orders/{id}/items/{item}', static fn (Request $r): Response => $ctl()->updateItem($r), 'orders.manage');
    $router->post('/orders/{id}/items/{item}/delete', static fn (Request $r): Response => $ctl()->deleteItem($r), 'orders.manage');

    $router->get('/orders/{id}/receive', static fn (Request $r): Response => $ctl()->receiveForm($r), 'orders.receive');
    $router->post('/orders/{id}/receive', static fn (Request $r): Response => $ctl()->receive($r), 'orders.receive');
    $router->get('/orders/{id}/receipts/{receipt}', static fn (Request $r): Response => $ctl()->receipt($r), 'orders.view');

    $router->post('/orders/{id}/documents', static fn (Request $r): Response => $ctl()->uploadDocument($r), 'documents.manage');
    $router->post('/orders/{id}/templates', static fn (Request $r): Response => $procurement()->saveTemplate($r), 'orders.manage');
};
