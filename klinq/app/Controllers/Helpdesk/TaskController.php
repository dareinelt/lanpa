<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\TaskCategoryRepository;
use App\Repositories\TaskRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Services\Helpdesk\MentionService;
use App\Services\Helpdesk\TaskService;
use App\Support\Paginator;

/**
 * Aufgaben im Help Desk: „Alle Aufgaben“ (Annehmen, Zuweisen), „Meine Aufgaben“, Detailansicht und
 * die direkte Anlage ohne Ticketbezug. Aufgaben können zusätzlich über „In Aufgabe umwandeln“ auf
 * einem Ticket entstehen.
 */
final class TaskController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TaskService $service,
        private readonly TaskRepository $tasks,
        private readonly TaskCategoryRepository $categories,
        private readonly TicketMasterDataRepository $masterData,
        private readonly UserRepository $users,
        private readonly Config $config,
        private readonly MentionService $mentions
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

    /**
     * Sortierung der Aufgabenliste: eine explizite Wahl in der Adresse gewinnt und wird
     * als Benutzerpräferenz gemerkt, sonst gilt die zuletzt gespeicherte Wahl. Fehlt
     * beides, bleibt es bei der Standardsortierung (Aktualisiert, absteigend).
     *
     * @return array{0:string,1:string}
     */
    private function sortFrom(Request $request, bool $remember = false): array
    {
        $userId = (int) $this->currentUser->id();
        $sort = $request->queryString('sort');
        $dir = strtolower($request->queryString('dir'));
        $explicit = $sort !== '' || $dir !== '';

        if ($userId > 0 && !($sort !== '' && $dir !== '')) {
            $saved = $this->users->taskSort($userId);
            if ($sort === '') {
                $sort = $saved['sort'];
            }
            if ($dir === '') {
                $dir = $saved['dir'];
            }
        }
        if (!isset(TaskRepository::SORTABLE[$sort])) {
            $sort = 'updated_at';
        }
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'desc';
        }

        if ($explicit && $remember) {
            $this->users->rememberTaskSort($userId, $sort, $dir);
        }

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
        // Der Aufgabenbereich ist Help-Desk-Rollen vorbehalten (siehe TaskService::get()).
        $this->currentUser->require('helpdesk.tasks');
        $filters = $this->filtersFrom($request, $mine);
        [$sort, $dir] = $this->sortFrom($request, true);
        $perPage = max(10, min(200, $request->int('per_page', (int) $this->config->get('helpdesk.list_per_page', 25)) ?? 25));
        $paginator = new Paginator($this->tasks->countSearch($filters), $request->int('page', 1) ?? 1, $perPage);
        // Aufgelöste Sortierung in die Links übernehmen, damit die aktive Spalte auch
        // bei gespeicherter Präferenz markiert ist und Filter sie nicht zurücksetzen.
        $query = $request->query();
        $query['sort'] = $sort;
        $query['dir'] = $dir;

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
            'query' => $query,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function show(Request $request): Response
    {
        $task = $this->service->get($request->paramInt('id'));
        $this->mentions->markTaskRead((int) $task['id']);

        return $this->render('helpdesk.aufgaben.show', [
            'title' => $task['number'] . ' · ' . $task['subject'],
            'activeNav' => 'helpdesk-aufgaben',
            'areaLabel' => 'Help Desk',
            'task' => $task,
            'events' => $this->service->events((int) $task['id']),
            'comments' => $this->service->comments((int) $task['id']),
            'journal' => $this->service->journal((int) $task['id']),
            'attachments' => $this->service->attachments((int) $task['id']),
            'statuses' => TaskService::STATUSES,
            'statusColors' => TaskService::STATUS_COLORS,
            'agents' => $this->service->assignableAgents(),
            'categories' => $this->categories->selectable($task['category_id'] !== null ? (int) $task['category_id'] : null),
            'isMine' => $this->currentUser->id() !== null && (int) ($task['assignee_user_id'] ?? 0) === (int) $this->currentUser->id(),
            'canAssign' => $this->currentUser->can('helpdesk.tasks.assign'),
            'canManageAttachments' => $this->currentUser->can('helpdesk.tasks.assign'),
            'currentUserId' => $this->currentUser->id(),
            'mentionAgents' => $this->mentions->suggestions(),
            'scripts' => ['/js/helpdesk-pins.js', '/js/helpdesk-media.js', '/js/helpdesk-mail-frame.js', '/js/helpdesk-mentions.js'],
        ]);
    }

    /** Mail-HTML der Beschreibung oder eines Journal-Eintrags für den isolierten Anzeigerahmen. */
    public function mailHtml(Request $request): Response
    {
        $task = $this->service->get($request->paramInt('id'));
        $entryId = $request->param('entry') !== null ? $request->paramInt('entry') : null;

        return $this->mailFrame($task, $entryId !== null ? $this->service->journal((int) $task['id']) : [], 'body_html', $entryId);
    }

    // ------------------------------------------------------------------ Aktionen

    /** Formular für eine direkt angelegte Aufgabe (ohne Ticketbezug). */
    public function create(Request $request): Response
    {
        $this->currentUser->require('helpdesk.tasks');
        $default = $this->masterData->defaultPriority();

        return $this->render('helpdesk.aufgaben.form', [
            'title' => 'Neue Aufgabe',
            'activeNav' => 'helpdesk-aufgaben',
            'areaLabel' => 'Help Desk',
            'categories' => $this->categories->all(),
            'priorities' => $this->masterData->priorities(),
            'defaultPriorityId' => $default !== null ? (int) $default['id'] : null,
        ]);
    }

    public function store(Request $request): Response
    {
        $task = null;
        $response = $this->attempt($request, function () use ($request, &$task): void {
            $task = $this->service->create($request->all());
        }, '/helpdesk/aufgaben/neu');
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Aufgabe ' . (string) $task['number'] . ' angelegt.', '/helpdesk/aufgaben/' . (int) $task['id'], ['task' => $task]);
    }

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
        $comment = $request->stringOrNull('comment');
        $task = null;
        $response = $this->attempt($request, function () use ($id, $assigneeId, $comment, &$task): void {
            $task = $this->service->assign($id, $assigneeId !== null && $assigneeId > 0 ? $assigneeId : null, $comment);
        }, '/helpdesk/aufgaben/' . $id);
        if ($response !== null) {
            return $response;
        }

        $message = $task['assignee_name'] !== null
            ? 'Aufgabe an ' . (string) $task['assignee_name'] . ' zugewiesen.'
            : 'Zuweisung aufgehoben.';

        return $this->respond($request, $message, $this->safeBack($request, '/helpdesk/aufgaben/' . $id), ['task' => $task]);
    }

    /** Kategorie der Aufgabe setzen oder entfernen. */
    public function category(Request $request): Response
    {
        $id = $request->paramInt('id');
        $task = null;
        $response = $this->attempt($request, function () use ($request, $id, &$task): void {
            $task = $this->service->changeCategory($id, $request->all());
        }, '/helpdesk/aufgaben/' . $id);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Kategorie aktualisiert.', $this->safeBack($request, '/helpdesk/aufgaben/' . $id), ['task' => $task]);
    }

    /** Fälligkeit der Aufgabe nachtragen, ändern oder entfernen. */
    public function dueDate(Request $request): Response
    {
        $id = $request->paramInt('id');
        $task = null;
        $response = $this->attempt($request, function () use ($request, $id, &$task): void {
            $task = $this->service->changeDueDate($id, $request->all());
        }, '/helpdesk/aufgaben/' . $id);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Fälligkeit aktualisiert.', $this->safeBack($request, '/helpdesk/aufgaben/' . $id), ['task' => $task]);
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

    /** Kommentar an der Aufgabe speichern (chronologisch im Aufgabendetail). */
    public function comment(Request $request): Response
    {
        $id = $request->paramInt('id');
        $redirect = '/helpdesk/aufgaben/' . $id . '#kommentare';
        $comment = null;
        $response = $this->attempt($request, function () use ($request, $id, &$comment): void {
            $comment = $this->service->addComment($id, $request->all());
        }, $redirect);
        if ($response !== null) {
            return $response;
        }

        return $this->respond($request, 'Kommentar gespeichert.', $redirect, ['comment' => $comment]);
    }

    /** Kommentar über die Pinnadel oben rechts anheften bzw. die Anheftung lösen. */
    public function pinComment(Request $request): Response
    {
        $id = $request->paramInt('id');
        $commentId = $request->paramInt('comment');
        $pinned = $request->bool('pinned');
        $redirect = '/helpdesk/aufgaben/' . $id . '#kommentar-' . $commentId;

        $response = $this->attempt($request, function () use ($id, $commentId, $pinned): void {
            $this->service->setCommentPinned($id, $commentId, $pinned);
        }, $redirect, false);

        return $response ?? $this->respond($request, $pinned ? 'Kommentar angeheftet.' : 'Anheftung gelöst.', $redirect, [
            'comment' => $commentId,
            'pinned' => $pinned,
        ]);
    }
}
