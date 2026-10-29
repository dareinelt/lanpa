<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\TaskRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\TaskService;
use App\Support\Paginator;

/**
 * Aufgaben im Help Desk: „Alle Aufgaben“ (Annehmen, Zuweisen), „Meine Aufgaben“ und Detailansicht.
 * Aufgaben entstehen über „In Aufgabe umwandeln“ auf einem Ticket.
 */
final class TaskController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TaskService $service,
        private readonly TaskRepository $tasks,
        private readonly TicketMasterDataRepository $masterData,
        private readonly Config $config
    ) {
        parent::__construct($view, $currentUser);
    }

    /** @return array<string,mixed> */
    private function filtersFrom(Request $request, bool $mine): array
    {
        $views = $mine ? TaskService::viewsMine() : TaskService::viewsAll();
        $view = $request->queryString('view', $mine ? 'mine_open' : 'open');
        if (!isset($views[$view])) {
            $view = $mine ? 'mine_open' : 'open';
        }

        return [
            'view' => $view,
            'user_id' => $this->currentUser->id(),
            'q' => $request->queryString('q'),
            'status' => $request->queryString('status'),
            'priority_id' => $request->queryString('priority_id'),
            'assignee_user_id' => $request->queryString('assignee_user_id'),
            'created_from' => $request->queryString('created_from'),
            'created_to' => $request->queryString('created_to'),
        ];
    }

    /** @return array{0:string,1:string} */
    private function sortFrom(Request $request): array
    {
        $sort = $request->queryString('sort', 'updated_at');
        if (!isset(TaskRepository::SORTABLE[$sort])) {
            $sort = 'updated_at';
        }
        $dir = strtolower($request->queryString('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        return [$sort, $dir];
    }

    public function index(Request $request): Response
    {
        return $this->listTasks($request, false);
    }

    public function mine(Request $request): Response
    {
        return $this->listTasks($request, true);
    }

    private function listTasks(Request $request, bool $mine): Response
    {
        $filters = $this->filtersFrom($request, $mine);
        [$sort, $dir] = $this->sortFrom($request);
        $perPage = max(10, min(200, $request->int('per_page', (int) $this->config->get('helpdesk.list_per_page', 25)) ?? 25));
        $paginator = new Paginator($this->tasks->countSearch($filters), $request->int('page', 1) ?? 1, $perPage);

        return $this->render('helpdesk.aufgaben.index', [
            'title' => $mine ? 'Meine Aufgaben' : 'Alle Aufgaben',
            'activeNav' => $mine ? 'helpdesk-aufgaben-meine' : 'helpdesk-aufgaben',
            'areaLabel' => 'Help Desk',
            'rows' => $this->tasks->search($filters, $sort, $dir, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'mode' => $mine ? 'mine' : 'all',
            'views' => $mine ? TaskService::viewsMine() : TaskService::viewsAll(),
            'viewCounts' => $this->tasks->viewCounts((int) $this->currentUser->id()),
            'statuses' => TaskService::STATUSES,
            'statusColors' => TaskService::STATUS_COLORS,
            'priorities' => $this->masterData->priorities(),
            'agents' => $this->service->assignableAgents(),
            'paginator' => $paginator,
            'basePath' => $mine ? '/helpdesk/aufgaben/meine' : '/helpdesk/aufgaben',
            'query' => $request->query(),
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function show(Request $request): Response
    {
        $task = $this->service->get($request->paramInt('id'));

        return $this->render('helpdesk.aufgaben.show', [
            'title' => $task['number'] . ' · ' . $task['subject'],
            'activeNav' => 'helpdesk-aufgaben',
            'areaLabel' => 'Help Desk',
            'task' => $task,
            'events' => $this->service->events((int) $task['id']),
            'statuses' => TaskService::STATUSES,
            'statusColors' => TaskService::STATUS_COLORS,
            'agents' => $this->service->assignableAgents(),
            'isMine' => $this->currentUser->id() !== null && (int) ($task['assignee_user_id'] ?? 0) === (int) $this->currentUser->id(),
            'canAssign' => $this->currentUser->can('helpdesk.tasks.assign'),
        ]);
    }

    // ------------------------------------------------------------------ Aktionen

    /** Ticket in eine Aufgabe umwandeln (Schaltfläche auf der Ticketdetailseite). */
    public function convert(Request $request): Response
    {
        $ticketId = $request->paramInt('id');
        $task = null;
        $response = $this->attempt($request, function () use ($request, $ticketId, &$task): void {
            $task = $this->service->convert($ticketId, $request->all());
        }, '/helpdesk/tickets/' . $ticketId);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Ticket in Aufgabe ' . (string) $task['number'] . ' umgewandelt.', '/helpdesk/aufgaben/' . (int) $task['id'], ['task' => $task]);
    }

    public function claim(Request $request): Response
    {
        $id = $request->paramInt('id');
        $task = null;
        $response = $this->attempt($request, function () use ($id, &$task): void {
            $task = $this->service->claim($id);
        }, '/helpdesk/aufgaben/' . $id);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Aufgabe angenommen.', $this->safeBack($request, '/helpdesk/aufgaben/' . $id), ['task' => $task]);
    }

    public function assign(Request $request): Response
    {
        $id = $request->paramInt('id');
        $assigneeId = $request->int('assignee_user_id');
        $task = null;
        $response = $this->attempt($request, function () use ($id, $assigneeId, &$task): void {
            $task = $this->service->assign($id, $assigneeId !== null && $assigneeId > 0 ? $assigneeId : null);
        }, '/helpdesk/aufgaben/' . $id);
        if ($response !== null) {
            return $response;
        }

        $message = $task['assignee_name'] !== null
            ? 'Aufgabe an ' . (string) $task['assignee_name'] . ' zugewiesen.'
            : 'Zuweisung aufgehoben.';

        return $this->respond($request, $message, $this->safeBack($request, '/helpdesk/aufgaben/' . $id), ['task' => $task]);
    }

    public function status(Request $request): Response
    {
        $id = $request->paramInt('id');
        $task = null;
        $response = $this->attempt($request, function () use ($request, $id, &$task): void {
            $task = $this->service->changeStatus($id, $request->all());
        }, '/helpdesk/aufgaben/' . $id);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Aufgabe aktualisiert.', $this->safeBack($request, '/helpdesk/aufgaben/' . $id), ['task' => $task]);
    }
}
