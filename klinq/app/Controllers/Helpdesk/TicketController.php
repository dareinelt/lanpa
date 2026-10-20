<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Repositories\TicketWorklogRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\HelpdeskAiService;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\SupportShiftService;
use App\Services\Helpdesk\TicketMergeService;
use App\Services\Helpdesk\TicketPriorityMatrix;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketService;
use App\Services\Helpdesk\TicketSlaService;
use App\Services\LocationService;
use App\Support\CsvWriter;
use App\Support\Paginator;

/** Ticketliste, Anlage, Detail und alle Aktionen auf einem Ticket (Agentenansicht). */
final class TicketController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketService $service,
        private readonly TicketMergeService $mergeService,
        private readonly TicketReportService $reports,
        private readonly KnowledgeBaseService $knowledge,
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketSlaRepository $slas,
        private readonly TicketTagRepository $tags,
        private readonly TicketTemplateRepository $templates,
        private readonly TicketWorklogRepository $worklogs,
        private readonly TicketRelationRepository $relations,
        private readonly TicketRuleRepository $rules,
        private readonly TicketSlaService $slaService,
        private readonly EmployeeRepository $employees,
        private readonly AssetRepository $assets,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly Config $config,
        private readonly SupportShiftService $supportShifts,
        private readonly HelpdeskAiService $ai
    ) {
        parent::__construct($view, $currentUser);
    }

    // ------------------------------------------------------------------ Liste

    /** @return array<string,mixed> */
    private function filtersFrom(Request $request): array
    {
        $view = $request->queryString('view', 'open');
        if (!isset(TicketService::views()[$view])) {
            $view = 'open';
        }

        return [
            'view' => $view,
            'user_id' => $this->currentUser->id(),
            'q' => $request->queryString('q'),
            'status_id' => $request->queryString('status_id'),
            'priority_id' => $request->queryString('priority_id'),
            'type_id' => $request->queryString('type_id'),
            'group_id' => $request->queryString('group_id'),
            'assignee_user_id' => $request->queryString('assignee_user_id'),
            'category_id' => $request->queryString('category_id'),
            'requester_employee_id' => $request->queryString('requester_employee_id'),
            'employee_id' => $request->queryString('employee_id'),
            'asset_id' => $request->queryString('asset_id'),
            'tag' => $request->queryString('tag'),
            'sla' => $request->queryString('sla'),
            'created_from' => $request->queryString('created_from'),
            'created_to' => $request->queryString('created_to'),
            'include_merged' => $request->queryString('include_merged') === '1',
        ];
    }

    /** @return array{0:string,1:string} */
    private function sortFrom(Request $request, string $defaultSort = 'updated_at', string $defaultDir = 'desc'): array
    {
        $sort = $request->queryString('sort', $defaultSort);
        if (!isset(TicketRepository::SORTABLE[$sort])) {
            $sort = $defaultSort;
        }
        $dir = strtolower($request->queryString('dir', $defaultDir)) === 'asc' ? 'asc' : 'desc';

        return [$sort, $dir];
    }

    public function index(Request $request): Response
    {
        $filters = $this->filtersFrom($request);
        [$sort, $dir] = $this->sortFrom($request);
        $perPage = max(10, min(200, $request->int('per_page', (int) $this->config->get('helpdesk.list_per_page', 25)) ?? 25));
        $paginator = new Paginator($this->tickets->countSearch($filters), $request->int('page', 1) ?? 1, $perPage);

        return $this->render('helpdesk.tickets.index', [
            'title' => 'Tickets',
            'activeNav' => 'helpdesk-tickets',
            'areaLabel' => 'Help Desk',
            'rows' => $this->tickets->search($filters, $sort, $dir, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'views' => TicketService::views(),
            'viewCounts' => $this->tickets->viewCounts((int) $this->currentUser->id()),
            'statuses' => $this->masterData->statuses(),
            'priorities' => $this->masterData->priorities(),
            'types' => $this->masterData->types(),
            'groups' => $this->masterData->groups(),
            'agents' => $this->masterData->agents(TicketService::AGENT_ROLES),
            'categories' => $this->categories->roots(),
            'paginator' => $paginator,
            'basePath' => '/helpdesk/tickets',
            'query' => $request->query(),
            'sort' => $sort,
            'dir' => $dir,
            'slaService' => $this->slaService,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'scripts' => ['/js/helpdesk.js'],
        ]);
    }

    public function export(Request $request): Response
    {
        [$sort, $dir] = $this->sortFrom($request);
        $csv = $this->reports->exportListCsv($this->filtersFrom($request), $sort, $dir);

        return Response::download($csv, CsvWriter::MIME, CsvWriter::filename('tickets'));
    }

    // ------------------------------------------------------------------ Anlage

    public function create(Request $request): Response
    {
        $prefill = [];
        $templateId = $request->int('template');
        if ($templateId !== null) {
            $prefill = $this->service->prefillFromTemplate($templateId, false);
        }
        foreach (['requester_employee_id', 'affected_employee_id', 'asset_id', 'category_id', 'group_id', 'location_id'] as $key) {
            if ($request->queryString($key) !== '') {
                $prefill[$key] = $request->queryString($key);
            }
        }
        if (!empty($prefill['asset_id']) && empty($prefill['requester_employee_id'])) {
            $asset = $this->assets->find((int) $prefill['asset_id']);
            if ($asset !== null && !empty($asset['employee_id'])) {
                $prefill['requester_employee_id'] = (int) $asset['employee_id'];
            }
        }
        if (empty($prefill['requester_employee_id']) && $this->service->currentEmployeeId() !== null) {
            $prefill['requester_employee_id'] = $this->service->currentEmployeeId();
        }

        return $this->renderForm(null, $prefill, $templateId);
    }

    public function store(Request $request): Response
    {
        try {
            $ticket = $this->service->create($request->all(), 'web');
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/helpdesk/tickets/new');
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $ticket['id'], 'number' => $ticket['number']], 201);
        }
        $this->flash('success', 'Ticket ' . $ticket['number'] . ' angelegt.');

        return $this->redirect('/helpdesk/tickets/' . $ticket['id']);
    }

    // ------------------------------------------------------------------ Detail

    public function show(Request $request): Response
    {
        $ticket = $this->service->getVisible($request->paramInt('id'));
        $id = (int) $ticket['id'];
        $this->service->recordView($id);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $shift = $this->supportShifts->today();
        $sla = !empty($ticket['sla_id']) ? $this->slas->find((int) $ticket['sla_id']) : null;
        $warning = $sla !== null ? $this->slaService->warningPercent($sla) : (int) $this->config->get('helpdesk.sla_warning_percent', 75);
        $slaInfo = [
            'response' => [
                'state' => $this->slaService->evaluate($ticket['response_due_at'], $ticket['first_response_at'], $now, $ticket['created_at'], $warning),
                'due' => $ticket['response_due_at'],
                'remaining' => $this->slaService->remainingMinutes($ticket['response_due_at'], $now),
                'percent' => $this->slaService->consumedPercent($ticket['created_at'], $ticket['response_due_at'], $now),
            ],
            'resolution' => [
                'state' => $this->slaService->evaluate($ticket['resolution_due_at'], $ticket['resolved_at'], $now, $ticket['created_at'], $warning),
                'due' => $ticket['resolution_due_at'],
                'remaining' => $this->slaService->remainingMinutes($ticket['resolution_due_at'], $now),
                'percent' => $this->slaService->consumedPercent($ticket['created_at'], $ticket['resolution_due_at'], $now),
            ],
            'paused' => $ticket['sla_paused_at'] !== null,
            'paused_minutes' => (int) ($ticket['sla_paused_minutes'] ?? 0),
        ];

        return $this->render('helpdesk.tickets.show', [
            'title' => $ticket['number'] . ' · ' . $ticket['subject'],
            'activeNav' => 'helpdesk-tickets',
            'areaLabel' => 'Help Desk',
            'ticket' => $ticket,
            'sla' => $sla,
            'slaInfo' => $slaInfo,
            'comments' => $this->service->comments($ticket),
            'attachments' => $this->service->attachments($ticket),
            'assets' => $this->tickets->assets($id),
            'tags' => $this->tags->forTicket($id),
            'watchers' => $this->tickets->watchers($id),
            'isWatching' => $this->currentUser->id() !== null && $this->tickets->isWatching($id, (int) $this->currentUser->id()),
            'worklogs' => $this->worklogs->forTicket($id),
            'worklogTotal' => $this->worklogs->totalMinutes($id),
            'relations' => $this->relations->forTicket($id),
            'merged' => $this->tickets->mergedInto($id),
            'timeline' => $this->service->timelineVisible($id),
            'transitions' => $this->service->availableTransitions($ticket),
            'articles' => $this->knowledge->suggestionsFor($ticket),
            'linkedArticles' => $this->currentUser->can('knowledgebase.view') ? $this->knowledge->articlesForTicket($id) : [],
            'notifications' => $this->currentUser->can('helpdesk.admin') ? $this->rules->notificationsForTicket($id) : [],
            'agents' => $this->masterData->agents(TicketService::AGENT_ROLES),
            'groups' => $this->masterData->groups(),
            'priorities' => $this->masterData->priorities(),
            'slas' => $this->slas->all(true),
            'employeeAssets' => $this->service->assetsForEmployee(!empty($ticket['affected_employee_id']) ? (int) $ticket['affected_employee_id'] : (!empty($ticket['requester_employee_id']) ? (int) $ticket['requester_employee_id'] : null)),
            'worklogActivities' => TicketService::WORKLOG_ACTIVITIES,
            'relationLabels' => TicketService::RELATION_LABELS,
            'sources' => TicketService::SOURCES,
            'impactLabels' => TicketPriorityMatrix::IMPACT_LABELS,
            'urgencyLabels' => TicketPriorityMatrix::URGENCY_LABELS,
            'isAgent' => $this->service->isAgent(),
            'now' => $now,
            'isFirstLevelSupport' => $shift['first'] !== null && (int) $shift['first']['user_id'] === (int) $this->currentUser->id(),
            'secondLevelSupport' => $shift['second'],
            'aiEnabled' => $this->ai->isEnabled(),
            'scripts' => ['/js/helpdesk.js', '/js/helpdesk-ai.js'],
        ]);
    }

    public function edit(Request $request): Response
    {
        $ticket = $this->service->get($request->paramInt('id'));

        return $this->renderForm($ticket, [], null);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $ticket = null;
        $response = $this->attempt($request, function () use ($request, $id, &$ticket): void {
            $ticket = $this->service->update($id, $request->all(), $request->int('version'));
        }, '/helpdesk/tickets/' . $id . '/edit');
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Ticket gespeichert.', '/helpdesk/tickets/' . $id, ['ticket' => $ticket]);
    }

    // ------------------------------------------------------------------ Aktionen

    public function status(Request $request): Response
    {
        $id = $request->paramInt('id');
        $to = $request->string('status');
        $response = $this->attempt($request, function () use ($request, $id, $to): void {
            $this->service->changeStatus($id, $to, $request->stringOrNull('note'), $request->stringOrNull('resolution'), $request->stringOrNull('reason'), $request->int('version'));
        }, '/helpdesk/tickets/' . $id . '#status', false);

        return $response ?? $this->respond($request, 'Status geändert.', '/helpdesk/tickets/' . $id);
    }

    public function assign(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, function () use ($request, $id): void {
            $this->service->assign($id, $request->int('assignee_user_id'), $request->int('group_id'), $request->int('deputy_user_id'), $request->bool('keep_group'), $request->int('version'));
        }, '/helpdesk/tickets/' . $id . '#assign', false);

        return $response ?? $this->respond($request, 'Zuweisung gespeichert.', '/helpdesk/tickets/' . $id);
    }

    /** Weist das Ticket dem 2nd Level des Tages zu (nur der heutige 1st Level); Kommentar ist Pflicht. */
    public function assignSecondLevel(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, function () use ($request, $id): void {
            $this->supportShifts->assignToSecondLevel($id, $request->string('note'));
        }, '/helpdesk/tickets/' . $id . '#second-level', false);

        return $response ?? $this->respond($request, 'An den 2nd Level Support zugewiesen.', '/helpdesk/tickets/' . $id);
    }

    public function takeOver(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->takeOver($id), '/helpdesk/tickets/' . $id, false);

        return $response ?? $this->respond($request, 'Ticket übernommen.', '/helpdesk/tickets/' . $id);
    }

    public function merge(Request $request): Response
    {
        $id = $request->paramInt('id');
        $target = null;
        $response = $this->attempt($request, function () use ($request, $id, &$target): void {
            $target = $this->mergeService->merge($id, $request->string('target'));
        }, '/helpdesk/tickets/' . $id . '#merge', false);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Ticket wurde in ' . $target['number'] . ' zusammengeführt.', '/helpdesk/tickets/' . $target['id'], ['target' => $target]);
    }

    public function tags(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->setTags($id, $request->string('tags')), '/helpdesk/tickets/' . $id . '#tags', false);

        return $response ?? $this->respond($request, 'Tags gespeichert.', '/helpdesk/tickets/' . $id . '#tags');
    }

    public function watch(Request $request): Response
    {
        $id = $request->paramInt('id');
        $watching = $this->service->toggleWatch($id);

        return $this->respond($request, $watching ? 'Sie beobachten dieses Ticket.' : 'Beobachtung beendet.', $this->safeBack($request, '/helpdesk/tickets/' . $id), ['watching' => $watching]);
    }

    public function addWatcher(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->addWatcher($id, $request->int('user_id') ?? 0), '/helpdesk/tickets/' . $id . '#watchers', false);

        return $response ?? $this->respond($request, 'Beobachter hinzugefügt.', '/helpdesk/tickets/' . $id . '#watchers');
    }

    public function removeWatcher(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->service->removeWatcher($id, $request->paramInt('user'));

        return $this->respond($request, 'Beobachter entfernt.', '/helpdesk/tickets/' . $id . '#watchers');
    }

    public function addAsset(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->addAsset($id, $request->int('asset_id') ?? 0, $request->stringOrNull('note')), '/helpdesk/tickets/' . $id . '#assets', false);

        return $response ?? $this->respond($request, 'Asset verknüpft.', '/helpdesk/tickets/' . $id . '#assets');
    }

    public function removeAsset(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->service->removeAsset($id, $request->paramInt('asset'));

        return $this->respond($request, 'Asset-Verknüpfung entfernt.', '/helpdesk/tickets/' . $id . '#assets');
    }

    public function addWorklog(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->addWorklog($id, $request->all()), '/helpdesk/tickets/' . $id . '#worklog', false);

        return $response ?? $this->respond($request, 'Arbeitszeit erfasst.', '/helpdesk/tickets/' . $id . '#worklog');
    }

    public function removeWorklog(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->service->deleteWorklog($id, $request->paramInt('worklog'));

        return $this->respond($request, 'Arbeitszeit gelöscht.', '/helpdesk/tickets/' . $id . '#worklog');
    }

    public function addRelation(Request $request): Response
    {
        $id = $request->paramInt('id');
        $response = $this->attempt($request, fn () => $this->service->addRelation($id, $request->string('related'), $request->string('type', 'related')), '/helpdesk/tickets/' . $id . '#relations', false);

        return $response ?? $this->respond($request, 'Beziehung angelegt.', '/helpdesk/tickets/' . $id . '#relations');
    }

    public function removeRelation(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->service->removeRelation($id, $request->paramInt('relation'));

        return $this->respond($request, 'Beziehung entfernt.', '/helpdesk/tickets/' . $id . '#relations');
    }

    public function delete(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->service->delete($id);

        return $this->respond($request, 'Ticket gelöscht.', '/helpdesk/tickets');
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed>|null $ticket @param array<string,mixed> $prefill */
    private function renderForm(?array $ticket, array $prefill, ?int $templateId): Response
    {
        $requesterId = (int) ($prefill['requester_employee_id'] ?? $ticket['requester_employee_id'] ?? 0);
        $affectedId = (int) ($prefill['affected_employee_id'] ?? $ticket['affected_employee_id'] ?? 0);
        $categoryId = (int) $this->oldInput('category_id', $prefill['category_id'] ?? $ticket['category_id'] ?? 0);

        return $this->render('helpdesk.tickets.form', [
            'title' => $ticket === null ? 'Neues Ticket' : 'Ticket ' . $ticket['number'] . ' bearbeiten',
            'activeNav' => 'helpdesk-tickets',
            'areaLabel' => 'Help Desk',
            'ticket' => $ticket,
            'prefill' => $prefill,
            'templateId' => $templateId,
            'templates' => $this->templates->all(true),
            'types' => $this->masterData->types(),
            'priorities' => $this->masterData->priorities(),
            'groups' => $this->masterData->groups(),
            'agents' => $this->masterData->agents(TicketService::AGENT_ROLES),
            'categories' => $this->categories->roots(),
            'subcategories' => $categoryId > 0 ? $this->categories->children($categoryId) : [],
            'slas' => $this->slas->all(true),
            'employees' => $this->employees->activeForSelect(),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'employeeAssets' => $this->service->assetsForEmployee($affectedId > 0 ? $affectedId : ($requesterId > 0 ? $requesterId : null)),
            'linkedAssets' => $ticket !== null ? $this->tickets->assets((int) $ticket['id']) : [],
            'tags' => $ticket !== null ? $this->tags->forTicket((int) $ticket['id']) : [],
            'impactLabels' => TicketPriorityMatrix::IMPACT_LABELS,
            'urgencyLabels' => TicketPriorityMatrix::URGENCY_LABELS,
            'aiEnabled' => $this->ai->isEnabled(),
            'scripts' => ['/js/picker.js', '/js/helpdesk.js', '/js/helpdesk-ai.js'],
        ]);
    }
}
