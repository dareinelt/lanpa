<?php

declare(strict_types=1);

use App\Controllers\HandoverController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $h = static fn (): HandoverController => $c->get(HandoverController::class);

    // Desktop
    $router->get('/handover', static fn (Request $r): Response => $h()->index($r), 'handover.view');
    $router->get('/handover/print.css', static fn (Request $r): Response => $h()->stylesheet($r), 'handover.view');
    $router->get('/handover/employee/{employee}', static fn (Request $r): Response => $h()->employee($r), 'handover.view');
    $router->post('/handover/employee/{employee}/draft', static fn (Request $r): Response => $h()->createDraft($r), 'handover.manage');
    $router->get('/handover/{id}', static fn (Request $r): Response => $h()->show($r), 'handover.view');
    $router->get('/handover/{id}/pdf', static fn (Request $r): Response => $h()->pdf($r), 'handover.view');
    $router->post('/handover/{id}/refresh', static fn (Request $r): Response => $h()->refresh($r), 'handover.manage');
    $router->post('/handover/{id}/cancel', static fn (Request $r): Response => $h()->cancel($r), 'handover.manage');
    $router->post('/handover/{id}/pdf', static fn (Request $r): Response => $h()->regeneratePdf($r), 'handover.manage');

    // Mobile Unterschrift (iPhone/iPad)
    $router->get('/m/handover', static fn (Request $r): Response => $h()->mobileIndex($r), 'handover.sign');
    $router->get('/m/handover/{id}/sign', static fn (Request $r): Response => $h()->mobileSign($r), 'handover.sign');
    $router->post('/m/handover/{id}/sign', static fn (Request $r): Response => $h()->mobileSubmit($r), 'handover.sign');
    $router->get('/m/handover/{id}/done', static fn (Request $r): Response => $h()->mobileDone($r), 'handover.sign');

    // Administration: Vorlage (Baukasten)
    $router->get('/admin/handover-template', static fn (Request $r): Response => $h()->template($r), 'handover.template');
    $router->post('/admin/handover-template', static fn (Request $r): Response => $h()->saveTemplate($r), 'handover.template');
    $router->post('/admin/handover-template/preview', static fn (Request $r): Response => $h()->previewTemplate($r), 'handover.template');
};
