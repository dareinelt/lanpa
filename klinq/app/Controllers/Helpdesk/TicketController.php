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
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\ErrorOriginSettingsService;
use App\Services\Helpdesk\HelpdeskAiService;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\ReplyTemplateSettingsService;
use App\Services\Helpdesk\SupportShiftService;
use App\Services\Helpdesk\TicketAttachmentService;
use App\Services\Helpdesk\TicketMailService;
use App\Services\Helpdesk\TicketMergeService;
use App\Services\Helpdesk\TicketPriorityMatrix;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketService;
use App\Services\Helpdesk\TicketSlaService;
use App\Services\Helpdesk\TicketWorkflowService;
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
        private readonly HelpdeskAiService $ai,
        private readonly TicketAttachmentService $attachmentService,
        private readonly TicketMailService $mailService,
        private readonly UserRepository $users,
        private readonly ReplyTemplateSettingsService $replyTemplates,
        private readonly ErrorOriginSettingsService $errorOrigins
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
            'scripts' => ['/js/helpdesk.js', '/js/helpdesk-merge-bulk.js'],
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
        // Der Melder wird bewusst nicht mit dem angemeldeten Agenten vorbelegt: Agenten legen
        // Tickets für die Person an, die das Problem hat, und wählen sie im Formular aus.
        $wizardMode = $this->resolveFormMode($request->queryString('modus'));
        if ($request->queryString('modus') !== '') {
            // Explizite Wahl über den Umschalter: sofort als Benutzerpräferenz merken.
            $this->rememberFormMode($wizardMode);
        }
        if ($wizardMode && empty($prefill['assignee_user_id'])) {
            // Im Assistenten ist der Bearbeiter mit dem heutigen 2nd Level vorbelegt.
            $secondLevel = $this->supportShifts->secondLevelUserIdToday();
            if ($secondLevel !== null) {
                $prefill['assignee_user_id'] = $secondLevel;
            }
        }

        return $this->renderForm(null, $prefill, $templateId, $wizardMode, $request->query());
    }

    public function store(Request $request): Response
    {
        $wizardMode = $request->string('modus') === 'assistent';
        try {
            $ticket = $this->service->create($request->all(), 'web');
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            $this->withOldInput($request, $e->errors());
            $params = array_filter([
                'template' => $request->int('template'),
                'modus' => $wizardMode ? 'assistent' : 'klassisch',
            ], static fn ($v): bool => $v !== null && $v !== '');

            return $this->redirect('/helpdesk/tickets/new' . ($params === [] ? '' : '?' . http_build_query($params)));
        }
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $ticket['id'], 'number' => $ticket['number']], 201);
        }
        // Zuletzt verwendeter Modus wird zum Standard dieses Benutzers (geräteunabhängig).
        $this->rememberFormMode($wizardMode);
        $upload = $this->attachmentService->storeAll((int) $ticket['id'], $request->files()['attachments'] ?? null);
        if ($upload['errors'] !== []) {
            $this->flash('warning', 'Nicht übernommene Anhänge: ' . implode(' | ', $upload['errors']));
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
            'warning_percent' => $warning,
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
            'errorOrigins' => $this->errorOrigins->origins(),
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
            'mailCompose' => $this->mailService->composeData($ticket),
            'mergeCompose' => $this->mergeService->composeData($ticket),
            'scripts' => ['/js/helpdesk.js', '/js/helpdesk-ai.js', '/js/helpdesk-media.js', '/js/helpdesk-mail.js', '/js/helpdesk-merge.js'],
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
        // Beim Schließen entscheidet der Agent, ob der Melder eine Benachrichtigung erhält;
        // alle anderen Übergänge benachrichtigen wie bisher.
        $notify = $to !== TicketWorkflowService::CLOSED || $request->bool('notify_requester');
        $response = $this->attempt($request, function () use ($request, $id, $to, $notify): void {
            $this->service->changeStatus($id, $to, $request->stringOrNull('note'), $request->stringOrNull('resolution'), $request->stringOrNull('reason'), $request->int('version'), false, $request->stringOrNull('pause_until'), $notify, $request->stringOrNull('error_origin'));
        }, '/helpdesk/tickets/' . $id . '#status', false);

        return $response ?? $this->respond($request, 'Status geändert.', '/helpdesk/tickets/' . $id);
    }

    /**
     * E-Mail an die meldende und optional an die betroffene Person senden.
     * Die Signatur wird serverseitig angehängt; auf Wunsch wird das Ticket im
     * selben Schritt pausiert.
     */
    public function sendMail(Request $request): Response
    {
        $id = $request->paramInt('id');
        $result = [];
        $response = $this->attempt($request, function () use ($request, $id, &$result): void {
            $result = $this->mailService->send($id, [
                'subject' => $request->string('subject'),
                'body_html' => $request->string('body_html'),
                'to_affected' => $request->string('to_affected'),
                'pause_code' => $request->string('pause_code'),
                'pause_until' => $request->string('pause_until'),
            ]);
        }, '/helpdesk/tickets/' . $id, false);
        if ($response !== null) {
            return $response;
        }

        $message = 'E-Mail an ' . implode(', ', $result['sent'] ?? []) . ' gesendet.';
        if (!empty($result['paused'])) {
            $message .= ' Ticket pausiert (' . $result['pause_name'] . ').';
        }
        if (!empty($result['pause_warning'])) {
            $message .= ' ' . $result['pause_warning'];
        }

        return $this->respond($request, $message, '/helpdesk/tickets/' . $id, ['mail' => $result]);
    }

    /** Feste Signatur des angemeldeten Agenten für den E-Mail-Dialog (JSON). */
    public function mailSignature(Request $request): Response
    {
        $this->service->getVisible($request->paramInt('id'));
        $signature = $this->mailService->signature();

        return $this->json(['html' => $signature['html'], 'name' => $signature['name']]);
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
            $target = $this->mergeService->merge($id, $request->string('target'), [
                'reason_code' => $request->string('reason_code'),
                'reason_note' => $request->string('reason_note'),
                'notify' => $request->string('notify'),
                'mail_subject' => $request->string('subject'),
                'mail_body_html' => $request->string('body_html'),
            ]);
        }, '/helpdesk/tickets/' . $id . '#merge', false);
        if ($response !== null) {
            return $response;
        }

        $message = 'Ticket wurde in ' . $target['number'] . ' zusammengeführt.';
        if (!empty($target['merge_mail']['sent'])) {
            $message .= ' Info-E-Mail an ' . implode(', ', $target['merge_mail']['sent']) . ' gesendet.';
        }
        if (($target['merge_mail_error'] ?? '') !== '') {
            $message .= ' Die Info-E-Mail konnte nicht gesendet werden: ' . $target['merge_mail_error'];
        }

        return $this->respond($request, $message, '/helpdesk/tickets/' . $target['id'], ['target' => $target]);
    }

    /**
     * Sammelaktion aus der Ticketliste: mehrere ausgewählte Tickets in ein Zielticket zusammenführen.
     */
    public function mergeMany(Request $request): Response
    {
        $rawSources = $request->input('sources', []);
        $sources = is_array($rawSources) ? array_map(static fn (mixed $id): int => (int) $id, $rawSources) : [];
        $result = null;
        $response = $this->attempt($request, function () use ($sources, $request, &$result): void {
            $result = $this->mergeService->mergeMany($sources, $request->string('target'), [
                'reason_code' => $request->string('reason_code', 'duplicate'),
            ]);
        }, '/helpdesk/tickets', false);
        if ($response !== null) {
            return $response;
        }

        $count = count($result['merged']);
        $message = $count . ' ' . ($count === 1 ? 'Ticket wurde' : 'Tickets wurden') . ' in ' . $result['target']['number'] . ' zusammengeführt.';
        if ($result['errors'] !== []) {
            $this->flash('warning', 'Nicht zusammengeführt: ' . implode(' | ', $result['errors']));
        }

        return $this->respond($request, $message, '/helpdesk/tickets/' . $result['target']['id'], [
            'target' => $result['target'],
            'merged' => $result['merged'],
            'errors' => $result['errors'],
        ]);
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

    /** Eingabemodus für neue Tickets: 'assistent' (Assistent) oder 'klassisch' (Formular). */
    public const FORM_MODES = ['klassisch', 'assistent'];

    /**
     * Ermittelt den Eingabemodus: explizite Wahl im Request gewinnt, sonst die zuletzt
     * gespeicherte Benutzerpräferenz, sonst das klassische Formular.
     *
     * @param string $requested Wert des Query-Parameters `modus` ('' wenn nicht gesetzt)
     * @return bool true = Assistent
     */
    private function resolveFormMode(string $requested): bool
    {
        if (in_array($requested, self::FORM_MODES, true)) {
            return $requested === 'assistent';
        }

        $userId = $this->currentUser->id();
        if ($userId === null) {
            return false;
        }

        return $this->users->ticketFormMode($userId) === 'assistent';
    }

    /** Merkt sich den verwendeten Modus als Benutzerpräferenz (nur bei Änderung ein UPDATE). */
    private function rememberFormMode(bool $wizardMode): void
    {
        $userId = $this->currentUser->id();
        if ($userId === null) {
            return;
        }
        $this->users->rememberTicketFormMode($userId, $wizardMode ? 'assistent' : 'klassisch');
    }

    /**
     * @param array<string,mixed>|null $ticket
     * @param array<string,mixed> $prefill
     * @param array<string,mixed> $query Aktuelle Query-Parameter für Modus-Wechsel
     */
    private function renderForm(?array $ticket, array $prefill, ?int $templateId, bool $wizardMode = false, array $query = []): Response
    {
        $requesterId = (int) ($prefill['requester_employee_id'] ?? $ticket['requester_employee_id'] ?? 0);
        $affectedId = (int) ($prefill['affected_employee_id'] ?? $ticket['affected_employee_id'] ?? 0);
        $categoryId = (int) $this->oldInput('category_id', $prefill['category_id'] ?? $ticket['category_id'] ?? 0);
        $wizard = $wizardMode && $ticket === null;

        return $this->render('helpdesk.tickets.form', [
            'title' => $ticket === null ? 'Neues Ticket' : 'Ticket ' . $ticket['number'] . ' bearbeiten',
            'activeNav' => 'helpdesk-tickets',
            'areaLabel' => 'Help Desk',
            'ticket' => $ticket,
            'prefill' => $prefill,
            'templateId' => $templateId,
            'wizardMode' => $wizard,
            'wizardStart' => (int) $this->oldInput('wizard_step', 1),
            'confirmationText' => $wizard ? $this->replyTemplates->confirmationText() : '',
            'modeQuery' => $query,
            'secondLevelAssigneeId' => $wizard ? $this->supportShifts->secondLevelUserIdToday() : null,
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
            'maxUploadBytes' => (int) $this->config->get('uploads.max_bytes', 20 * 1024 * 1024),
            'scripts' => array_merge(
                ['/js/picker.js', '/js/helpdesk.js', '/js/helpdesk-ai.js', '/js/helpdesk-media.js'],
                $wizard ? ['/js/helpdesk-wizard.js'] : []
            ),
        ]);
    }
}
