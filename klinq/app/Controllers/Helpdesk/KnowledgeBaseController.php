<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketTagRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\TicketService;
use App\Support\Paginator;

/** Wissensdatenbank: Liste, Artikel, Anlage/Bearbeitung, Artikel aus Ticket, Ticketverknüpfung. */
final class KnowledgeBaseController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly KnowledgeBaseService $service,
        private readonly KnowledgeBaseRepository $articles,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketTagRepository $tags,
        private readonly TicketService $tickets,
        private readonly Config $config
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $filters = $this->service->restrictFilters([
            'q' => $request->queryString('q'),
            'category_id' => $request->queryString('category_id'),
            'status' => $request->queryString('status'),
            'tag' => $request->queryString('tag'),
        ]);
        $sort = $request->queryString('sort', 'updated_at');
        if (!isset(KnowledgeBaseRepository::SORTABLE[$sort])) {
            $sort = 'updated_at';
        }
        $dir = strtolower($request->queryString('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $paginator = new Paginator($this->articles->countSearch($filters), $request->int('page', 1) ?? 1, (int) $this->config->get('helpdesk.list_per_page', 25));

        return $this->render('helpdesk.knowledge.index', [
            'title' => 'Wissensdatenbank',
            'activeNav' => 'helpdesk-knowledge',
            'areaLabel' => 'Help Desk',
            'rows' => $this->articles->search($filters, $sort, $dir, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'categories' => $this->categories->roots(),
            'counts' => $this->service->canManage() ? $this->articles->counts() : [],
            'statuses' => KnowledgeBaseService::STATUSES,
            'visibilities' => KnowledgeBaseService::VISIBILITIES,
            'canManage' => $this->service->canManage(),
            'paginator' => $paginator,
            'basePath' => '/helpdesk/knowledge',
            'query' => $request->query(),
        ]);
    }

    public function show(Request $request): Response
    {
        $article = $this->service->getVisible($request->paramInt('id'), true);

        return $this->render('helpdesk.knowledge.show', [
            'title' => $article['title'],
            'activeNav' => 'helpdesk-knowledge',
            'areaLabel' => 'Help Desk',
            'article' => $article,
            'tags' => $this->tags->forArticle((int) $article['id']),
            'linkedTickets' => $this->currentUser->can('helpdesk.view') ? $this->articles->linkedTickets((int) $article['id']) : [],
            'canManage' => $this->service->canManage(),
            'statuses' => KnowledgeBaseService::STATUSES,
            'visibilities' => KnowledgeBaseService::VISIBILITIES,
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->currentUser->require('knowledgebase.manage');
        $prefill = [];
        $ticketId = $request->int('ticket');
        if ($ticketId !== null) {
            $prefill = $this->service->prefillFromTicket($this->tickets->get($ticketId));
        }

        return $this->renderForm(null, $prefill, $ticketId);
    }

    public function store(Request $request): Response
    {
        $ticketId = $request->int('source_ticket_id');
        $article = null;
        $response = $this->attempt($request, function () use ($request, $ticketId, &$article): void {
            $article = $this->service->create($request->all(), $ticketId);
        }, '/helpdesk/knowledge/new' . ($ticketId !== null ? '?ticket=' . $ticketId : ''));
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Artikel angelegt.', '/helpdesk/knowledge/' . $article['id'], ['article' => $article]);
    }

    public function edit(Request $request): Response
    {
        $this->currentUser->require('knowledgebase.manage');
        $article = $this->findOrFail($this->articles->find($request->paramInt('id')), 'Artikel nicht gefunden');

        return $this->renderForm($article, [], null);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->update($id, $request->all()), '/helpdesk/knowledge/' . $id . '/edit');

        return $response ?? $this->respond($request, 'Artikel gespeichert.', '/helpdesk/knowledge/' . $id);
    }

    public function linkTicket(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->linkTicket($id, $request->string('ticket')), '/helpdesk/knowledge/' . $id . '#tickets', false);

        return $response ?? $this->respond($request, 'Ticket verknüpft.', $this->safeBack($request, '/helpdesk/knowledge/' . $id . '#tickets'));
    }

    public function unlinkTicket(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->service->unlinkTicket($id, $request->paramInt('ticket'));

        return $this->respond($request, 'Verknüpfung entfernt.', $this->safeBack($request, '/helpdesk/knowledge/' . $id . '#tickets'));
    }

    /** @param array<string,mixed>|null $article @param array<string,mixed> $prefill */
    private function renderForm(?array $article, array $prefill, ?int $ticketId): Response
    {
        return $this->render('helpdesk.knowledge.form', [
            'title' => $article === null ? 'Neuer Artikel' : 'Artikel bearbeiten',
            'activeNav' => 'helpdesk-knowledge',
            'areaLabel' => 'Help Desk',
            'article' => $article,
            'prefill' => $prefill,
            'sourceTicketId' => $ticketId,
            'categories' => $this->categories->all(true),
            'tags' => $article !== null ? $this->tags->forArticle((int) $article['id']) : [],
            'statuses' => KnowledgeBaseService::STATUSES,
            'visibilities' => KnowledgeBaseService::VISIBILITIES,
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }
}
