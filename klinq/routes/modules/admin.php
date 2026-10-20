<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\HelpdeskAiController;
use App\Controllers\HelpdeskMailboxController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return static function (Router $router, Container $c): void {
    $router->get('/admin', static fn (Request $r): Response => $c->get(AdminController::class)->index($r), 'settings.manage');

    // Help-Desk-Postfach: vollständige Konfiguration des E-Mail-Eingangs in der Anwendung
    $mailbox = static fn (): HelpdeskMailboxController => $c->get(HelpdeskMailboxController::class);
    $router->get('/admin/helpdesk-mail', static fn (Request $r): Response => $mailbox()->index($r), 'settings.manage');
    $router->post('/admin/helpdesk-mail', static fn (Request $r): Response => $mailbox()->save($r), 'settings.manage');
    $router->post('/admin/helpdesk-mail/test', static fn (Request $r): Response => $mailbox()->test($r), 'settings.manage');
    $router->post('/admin/helpdesk-mail/run', static fn (Request $r): Response => $mailbox()->run($r), 'settings.manage');

    // Help-Desk-KI: Endpunkt, Schlüssel und Modell pflegen, Verbindung testen, Modelle ermitteln
    $ai = static fn (): HelpdeskAiController => $c->get(HelpdeskAiController::class);
    $router->get('/admin/helpdesk-ai', static fn (Request $r): Response => $ai()->index($r), 'settings.manage');
    $router->post('/admin/helpdesk-ai', static fn (Request $r): Response => $ai()->save($r), 'settings.manage');
    $router->post('/admin/helpdesk-ai/test', static fn (Request $r): Response => $ai()->test($r), 'settings.manage');
    $router->post('/admin/helpdesk-ai/models', static fn (Request $r): Response => $ai()->models($r), 'settings.manage');
};
