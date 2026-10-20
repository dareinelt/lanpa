<?php

declare(strict_types=1);

use App\Controllers\Helpdesk\HelpdeskAdminController;
use App\Controllers\Helpdesk\HelpdeskApiController;
use App\Controllers\Helpdesk\HelpdeskDashboardController;
use App\Controllers\Helpdesk\HelpdeskReportController;
use App\Controllers\Helpdesk\HelpdeskSupportShiftController;
use App\Controllers\Helpdesk\KnowledgeBaseController;
use App\Controllers\Helpdesk\PortalController;
use App\Controllers\Helpdesk\QuickReportController;
use App\Controllers\Helpdesk\TicketAttachmentController;
use App\Controllers\Helpdesk\TicketController;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

/**
 * Help Desk / Ticketsystem: Agentenbereich (/helpdesk), Benutzerportal (/portal), JSON-API (/api/helpdesk).
 * Jede Route trägt eine Berechtigung (siehe config/permissions.php). Der Schalter HELPDESK_ENABLED
 * steuert die Navigation und den Scheduler; die Routen bleiben registriert und rein berechtigungsgeschützt.
 */
return static function (Router $router, Container $c): void {
    $dash = static fn (): HelpdeskDashboardController => $c->get(HelpdeskDashboardController::class);
    $shifts = static fn (): HelpdeskSupportShiftController => $c->get(HelpdeskSupportShiftController::class);
    $tickets = static fn (): TicketController => $c->get(TicketController::class);
    $files = static fn (): TicketAttachmentController => $c->get(TicketAttachmentController::class);
    $kb = static fn (): KnowledgeBaseController => $c->get(KnowledgeBaseController::class);
    $reports = static fn (): HelpdeskReportController => $c->get(HelpdeskReportController::class);
    $admin = static fn (): HelpdeskAdminController => $c->get(HelpdeskAdminController::class);
    $api = static fn (): HelpdeskApiController => $c->get(HelpdeskApiController::class);
    $portal = static fn (): PortalController => $c->get(PortalController::class);
    $quick = static fn (): QuickReportController => $c->get(QuickReportController::class);

    // ------------------------------------------------------------------ Öffentliche Störungsmeldung
    // Ohne Anmeldung erreichbar (Link aus der Help-Desk-Administration). Melder, Rechner und
    // AD-Daten werden serverseitig ermittelt; CSRF-Schutz und Netz-/Ratenbegrenzung greifen im Controller.
    $router->get('/stoerung', static fn (Request $r): Response => $quick()->form($r), null, true);
    $router->post('/stoerung', static fn (Request $r): Response => $quick()->store($r), null, true);
    $router->get('/stoerung/gesendet', static fn (Request $r): Response => $quick()->done($r), null, true);

    // ------------------------------------------------------------------ Agentenbereich
    $router->get('/helpdesk', static fn (Request $r): Response => $dash()->index($r), 'helpdesk.view');
    $router->post('/helpdesk/support-shift/claim', static fn (Request $r): Response => $shifts()->claim($r), 'helpdesk.view');

    $router->get('/helpdesk/tickets', static fn (Request $r): Response => $tickets()->index($r), 'helpdesk.view');
    $router->get('/helpdesk/tickets/export', static fn (Request $r): Response => $tickets()->export($r), 'helpdesk.export');
    $router->get('/helpdesk/tickets/new', static fn (Request $r): Response => $tickets()->create($r), 'helpdesk.create');
    $router->post('/helpdesk/tickets', static fn (Request $r): Response => $tickets()->store($r), 'helpdesk.create');
    $router->get('/helpdesk/tickets/{id}', static fn (Request $r): Response => $tickets()->show($r), 'helpdesk.view');
    $router->get('/helpdesk/tickets/{id}/edit', static fn (Request $r): Response => $tickets()->edit($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}', static fn (Request $r): Response => $tickets()->update($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/status', static fn (Request $r): Response => $tickets()->status($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/assign', static fn (Request $r): Response => $tickets()->assign($r), 'helpdesk.assign');
    $router->post('/helpdesk/tickets/{id}/assign-second-level', static fn (Request $r): Response => $tickets()->assignSecondLevel($r), 'helpdesk.assign');
    $router->post('/helpdesk/tickets/{id}/take', static fn (Request $r): Response => $tickets()->takeOver($r), 'helpdesk.assign');
    $router->post('/helpdesk/tickets/{id}/merge', static fn (Request $r): Response => $tickets()->merge($r), 'helpdesk.merge');
    $router->post('/helpdesk/tickets/{id}/tags', static fn (Request $r): Response => $tickets()->tags($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/watch', static fn (Request $r): Response => $tickets()->watch($r), 'helpdesk.view');
    $router->post('/helpdesk/tickets/{id}/watchers', static fn (Request $r): Response => $tickets()->addWatcher($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/watchers/{user}/remove', static fn (Request $r): Response => $tickets()->removeWatcher($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/assets', static fn (Request $r): Response => $tickets()->addAsset($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/assets/{asset}/remove', static fn (Request $r): Response => $tickets()->removeAsset($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/worklogs', static fn (Request $r): Response => $tickets()->addWorklog($r), 'helpdesk.worklog');
    $router->post('/helpdesk/tickets/{id}/worklogs/{worklog}/delete', static fn (Request $r): Response => $tickets()->removeWorklog($r), 'helpdesk.worklog');
    $router->post('/helpdesk/tickets/{id}/relations', static fn (Request $r): Response => $tickets()->addRelation($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/relations/{relation}/delete', static fn (Request $r): Response => $tickets()->removeRelation($r), 'helpdesk.update');
    $router->post('/helpdesk/tickets/{id}/delete', static fn (Request $r): Response => $tickets()->delete($r), 'helpdesk.delete');

    // Kommentare und Anhänge (Agent)
    $router->post('/helpdesk/tickets/{id}/comments', static fn (Request $r): Response => $files()->comment($r), 'helpdesk.comment');
    $router->post('/helpdesk/tickets/{id}/attachments', static fn (Request $r): Response => $files()->upload($r), 'helpdesk.comment');
    $router->get('/helpdesk/tickets/{id}/attachments/{document}', static fn (Request $r): Response => $files()->download($r), 'portal.view');
    $router->post('/helpdesk/tickets/{id}/attachments/{document}/delete', static fn (Request $r): Response => $files()->delete($r), 'helpdesk.update');

    // Wissensdatenbank
    $router->get('/helpdesk/knowledge', static fn (Request $r): Response => $kb()->index($r), 'knowledgebase.view');
    $router->get('/helpdesk/knowledge/new', static fn (Request $r): Response => $kb()->create($r), 'knowledgebase.manage');
    $router->post('/helpdesk/knowledge', static fn (Request $r): Response => $kb()->store($r), 'knowledgebase.manage');
    $router->get('/helpdesk/knowledge/{id}', static fn (Request $r): Response => $kb()->show($r), 'knowledgebase.view');
    $router->get('/helpdesk/knowledge/{id}/edit', static fn (Request $r): Response => $kb()->edit($r), 'knowledgebase.manage');
    $router->post('/helpdesk/knowledge/{id}', static fn (Request $r): Response => $kb()->update($r), 'knowledgebase.manage');
    $router->post('/helpdesk/knowledge/{id}/tickets', static fn (Request $r): Response => $kb()->linkTicket($r), 'knowledgebase.manage');
    $router->post('/helpdesk/knowledge/{id}/tickets/{ticket}/unlink', static fn (Request $r): Response => $kb()->unlinkTicket($r), 'knowledgebase.manage');

    // Berichte
    $router->get('/helpdesk/reports', static fn (Request $r): Response => $reports()->index($r), 'helpdesk.reports');
    $router->get('/helpdesk/reports/export', static fn (Request $r): Response => $reports()->export($r), 'helpdesk.export');

    // Administration – je Bereich eigene Berechtigung; der Bereich wird aus dem Pfad gelesen
    $router->get('/helpdesk/admin', static fn (Request $r): Response => $admin()->index($r), 'helpdesk.categories');
    $router->get('/helpdesk/admin/mail', static fn (Request $r): Response => $admin()->mail($r), 'helpdesk.admin');
    foreach (HelpdeskAdminController::KINDS as $kind => [, $permission]) {
        $router->get('/helpdesk/admin/' . $kind . '/new', static fn (Request $r): Response => $admin()->create($r), $permission);
        $router->post('/helpdesk/admin/' . $kind, static fn (Request $r): Response => $admin()->store($r), $permission);
        $router->get('/helpdesk/admin/' . $kind . '/{id}/edit', static fn (Request $r): Response => $admin()->edit($r), $permission);
        $router->post('/helpdesk/admin/' . $kind . '/{id}', static fn (Request $r): Response => $admin()->update($r), $permission);
        $router->post('/helpdesk/admin/' . $kind . '/{id}/toggle', static fn (Request $r): Response => $admin()->toggle($r), $permission);
    }

    // ------------------------------------------------------------------ Benutzerportal
    $router->get('/portal', static fn (Request $r): Response => $portal()->index($r), 'portal.view');
    $router->get('/portal/tickets', static fn (Request $r): Response => $portal()->tickets($r), 'portal.view');
    $router->get('/portal/tickets/new', static fn (Request $r): Response => $portal()->create($r), 'portal.create');
    $router->post('/portal/tickets', static fn (Request $r): Response => $portal()->store($r), 'portal.create');
    $router->get('/portal/tickets/{id}', static fn (Request $r): Response => $portal()->show($r), 'portal.view');
    $router->post('/portal/tickets/{id}/comments', static fn (Request $r): Response => $files()->comment($r), 'portal.view');
    $router->post('/portal/tickets/{id}/attachments', static fn (Request $r): Response => $files()->upload($r), 'portal.view');
    $router->get('/portal/tickets/{id}/attachments/{document}', static fn (Request $r): Response => $files()->download($r), 'portal.view');
    $router->post('/portal/tickets/{id}/reopen', static fn (Request $r): Response => $portal()->reopen($r), 'portal.view');
    $router->get('/portal/knowledge', static fn (Request $r): Response => $portal()->knowledge($r), 'knowledgebase.view');
    $router->get('/portal/knowledge/{id}', static fn (Request $r): Response => $portal()->article($r), 'knowledgebase.view');

    // ------------------------------------------------------------------ JSON-API
    $router->get('/api/helpdesk/tickets/search', static fn (Request $r): Response => $api()->searchTickets($r), 'portal.view');
    $router->get('/api/helpdesk/tickets/{id}', static fn (Request $r): Response => $api()->ticket($r), 'portal.view');
    $router->get('/api/helpdesk/tags/suggest', static fn (Request $r): Response => $api()->suggestTags($r), 'helpdesk.view');
    $router->get('/api/helpdesk/categories/{id}/children', static fn (Request $r): Response => $api()->children($r), 'portal.view');
    $router->get('/api/helpdesk/agents', static fn (Request $r): Response => $api()->agents($r), 'helpdesk.view');
    $router->get('/api/helpdesk/templates', static fn (Request $r): Response => $api()->templates($r), 'portal.view');
    $router->get('/api/helpdesk/templates/{id}', static fn (Request $r): Response => $api()->template($r), 'portal.view');
    $router->get('/api/helpdesk/employees/{id}/assets', static fn (Request $r): Response => $api()->employeeAssets($r), 'helpdesk.view');
    $router->get('/api/helpdesk/knowledge/suggest', static fn (Request $r): Response => $api()->suggestArticles($r), 'knowledgebase.view');
    $router->post('/api/helpdesk/ai/verbessern', static fn (Request $r): Response => $api()->improve($r), 'portal.view');
};
