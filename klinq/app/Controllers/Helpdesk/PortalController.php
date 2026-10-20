<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTemplateRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\TicketPriorityMatrix;
use App\Services\Helpdesk\TicketService;
use App\Services\Helpdesk\TicketSlaService;
use App\Support\Paginator;

/**
 * Benutzerportal: eigene Tickets anlegen, verfolgen, kommentieren, wieder öffnen; Wissensdatenbank lesen.
 * Sichtbar sind nur Tickets, bei denen der Benutzer Melder, Betroffener, Ersteller oder Beobachter ist.
 */
final class PortalController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketService $service,
        private readonly KnowledgeBaseService $knowledge,
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketTemplateRepository $templates,
        private readonly KnowledgeBaseRepository $articles,
        private readonly TicketSlaService $slaService,
        private readonly Config $config
    ) {
        parent::__construct($view, $currentUser);
    }

    /** @return array<string,mixed> */
    private function baseFilters(string $view): array
    {
        return ['visible_user_id' => (int) $this->currentUser->id(), 'view' => $view, 'user_id' => (int) $this->currentUser->id()];
    }

    public function index(Request $request): Response
    {
        $userId = (int) $this->currentUser->id();

        return $this->render('helpdesk.portal.index', [
            'title' => 'Mein Help Desk',
            'activeNav' => 'portal',
            'areaLabel' => 'Portal',
            'open' => $this->tickets->search($this->baseFilters('portal_open'), 'updated_at', 'desc', 10, 0),
            'resolved' => $this->tickets->search($this->baseFilters('portal_resolved'), 'updated_at', 'desc', 5, 0),
            'openCount' => $this->tickets->countSearch($this->baseFilters('portal_open')),
            'resolvedCount' => $this->tickets->countSearch($this->baseFilters('portal_resolved')),
            'templates' => $this->templates->all(true, true),
            'articles' => $this->articles->search($this->knowledge->restrictFilters([]), 'view_count', 'desc', 6, 0),
            'canCreate' => $this->currentUser->can('portal.create'),
            'isAgent' => $this->service->isAgent(),
            'userId' => $userId,
        ]);
    }

    public function tickets(Request $request): Response
    {
        $view = $request->queryString('view', 'portal_open');
        if (!in_array($view, ['portal_open', 'portal_resolved', 'all'], true)) {
            $view = 'portal_open';
        }
        $filters = $this->baseFilters($view) + ['q' => $request->queryString('q')];
        $paginator = new Paginator($this->tickets->countSearch($filters), $request->int('page', 1) ?? 1, (int) $this->config->get('helpdesk.list_per_page', 25));

        return $this->render('helpdesk.portal.tickets', [
            'title' => 'Meine Tickets',
            'activeNav' => 'portal-tickets',
            'areaLabel' => 'Portal',
            'rows' => $this->tickets->search($filters, 'updated_at', 'desc', $paginator->perPage, $paginator->offset()),
            'view' => $view,
            'q' => $filters['q'],
            'counts' => [
                'portal_open' => $this->tickets->countSearch($this->baseFilters('portal_open')),
                'portal_resolved' => $this->tickets->countSearch($this->baseFilters('portal_resolved')),
                'all' => $this->tickets->countSearch($this->baseFilters('all')),
            ],
            'paginator' => $paginator,
            'basePath' => '/portal/tickets',
            'query' => $request->query(),
            'canCreate' => $this->currentUser->can('portal.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->currentUser->require('portal.create');
        $prefill = [];
        $templateId = $request->int('template');
        if ($templateId !== null) {
            $prefill = $this->service->prefillFromTemplate($templateId, true);
        }
        $categoryId = (int) $this->oldInput('category_id', $prefill['category_id'] ?? 0);
        $employeeId = $this->service->currentEmployeeId();

        return $this->render('helpdesk.portal.form', [
            'title' => 'Neue Anfrage',
            'activeNav' => 'portal-tickets',
            'areaLabel' => 'Portal',
            'prefill' => $prefill,
            'templateId' => $templateId,
            'templates' => $this->templates->all(true, true),
            'types' => $this->masterData->types(),
            'categories' => $this->categories->roots(),
            'subcategories' => $categoryId > 0 ? $this->categories->children($categoryId) : [],
            'myAssets' => $this->service->assetsForEmployee($employeeId),
            'hasEmployee' => $employeeId !== null,
            'impactLabels' => TicketPriorityMatrix::IMPACT_LABELS,
            'urgencyLabels' => TicketPriorityMatrix::URGENCY_LABELS,
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $ticket = $this->service->create($request->all(), 'portal');
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/portal/tickets/new');
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $ticket['id'], 'number' => $ticket['number']], 201);
        }
        $this->flash('success', 'Ihre Anfrage wurde unter der Nummer ' . $ticket['number'] . ' angelegt.');

        return $this->redirect('/portal/tickets/' . $ticket['id']);
    }

    public function show(Request $request): Response
    {
        $ticket = $this->service->getVisible($request->paramInt('id'));
        $id = (int) $ticket['id'];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->render('helpdesk.portal.show', [
            'title' => $ticket['number'] . ' · ' . $ticket['subject'],
            'activeNav' => 'portal-tickets',
            'areaLabel' => 'Portal',
            'ticket' => $ticket,
            'comments' => $this->service->comments($ticket),
            'attachments' => $this->service->attachments($ticket),
            'assets' => $this->tickets->assets($id),
            'articles' => $this->knowledge->articlesForTicket($id),
            'canReopen' => $this->service->canReopenFromPortal($ticket),
            'canComment' => !in_array($ticket['status_code'], ['closed', 'cancelled'], true) && $ticket['merged_into_ticket_id'] === null,
            'isAgent' => $this->service->isAgent(),
            'reopenDays' => (int) $this->config->get('helpdesk.reopen_days', 14),
            'now' => $now,
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }

    public function reopen(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->reopenFromPortal($id, $request->string('note')), '/portal/tickets/' . $id, false);

        return $response ?? $this->respond($request, 'Das Ticket wurde wieder geöffnet.', '/portal/tickets/' . $id);
    }

    public function knowledge(Request $request): Response
    {
        $filters = $this->knowledge->restrictFilters(['q' => $request->queryString('q'), 'category_id' => $request->queryString('category_id')]);
        $paginator = new Paginator($this->articles->countSearch($filters), $request->int('page', 1) ?? 1, (int) $this->config->get('helpdesk.list_per_page', 25));

        return $this->render('helpdesk.portal.knowledge', [
            'title' => 'Hilfe & Anleitungen',
            'activeNav' => 'portal-knowledge',
            'areaLabel' => 'Portal',
            'rows' => $this->articles->search($filters, 'view_count', 'desc', $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'categories' => $this->categories->roots(),
            'paginator' => $paginator,
            'basePath' => '/portal/knowledge',
            'query' => $request->query(),
        ]);
    }

    public function article(Request $request): Response
    {
        $article = $this->knowledge->getVisible($request->paramInt('id'), true);

        return $this->render('helpdesk.portal.article', [
            'title' => $article['title'],
            'activeNav' => 'portal-knowledge',
            'areaLabel' => 'Portal',
            'article' => $article,
        ]);
    }
}
