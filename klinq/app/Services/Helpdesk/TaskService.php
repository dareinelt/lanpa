<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\TaskCategoryRepository;
use App\Repositories\TaskRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;

/**
 * Fachlogik der Aufgaben: Umwandlung aus Tickets, Annahme durch Agenten, Zuweisung durch die
 * Berechtigungsgruppe „Aufgabenverteilung“ und Statuswechsel. Alle schreibenden Vorgänge sind
 * transaktional, schreiben den Aufgabenverlauf und das Audit-Log.
 */
final class TaskService
{
    public const STATUSES = [
        'open' => 'Offen',
        'in_progress' => 'In Bearbeitung',
        'done' => 'Erledigt',
        'cancelled' => 'Storniert',
    ];

    /** Statusfarben für die Anzeige (passend zu den Badge-Klassen). */
    public const STATUS_COLORS = [
        'open' => 'info',
        'in_progress' => 'warning',
        'done' => 'success',
        'cancelled' => 'neutral',
    ];

    /** Status, in denen eine Aufgabe fachlich abgeschlossen ist. */
    public const FINAL = ['done', 'cancelled'];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TaskCategoryRepository $categories,
        private readonly TaskNumberService $numbers,
        private readonly TicketService $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    // ------------------------------------------------------------------ Ansichten

    /** Ansichten der Liste „Alle Aufgaben“. @return array<string,string> */
    public static function viewsAll(): array
    {
        return [
            'open' => 'Offene',
            'unassigned' => 'Nicht zugewiesen',
            'done' => 'Erledigt',
            'all' => 'Alle',
        ];
    }

    /** Ansichten der Liste „Meine Aufgaben“. @return array<string,string> */
    public static function viewsMine(): array
    {
        return [
            'mine_open' => 'Offene',
            'mine_done' => 'Erledigt',
            'mine' => 'Alle meine',
        ];
    }

    // ------------------------------------------------------------------ Lesen

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $task = $this->tasks->find($id);
        if ($task === null) {
            throw new NotFoundException('Aufgabe nicht gefunden.');
        }

        return $task;
    }

    /** Aufgabe zu einem Ticket (oder null). @return array<string,mixed>|null */
    public function taskForTicket(int $ticketId): ?array
    {
        return $this->tasks->findByTicket($ticketId);
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $taskId): array
    {
        return $this->tasks->events($taskId);
    }

    /** Aktive Agenten, denen eine Aufgabe zugewiesen werden darf. @return array<int,array<string,mixed>> */
    public function assignableAgents(): array
    {
        return $this->masterData->agents(TicketService::AGENT_ROLES);
    }

    // ------------------------------------------------------------------ Umwandlung aus einem Ticket

    /**
     * Wandelt ein Ticket in eine Aufgabe um und schließt das Ticket anschließend (es ist mit der
     * Aufgabe verknüpft und wird dort weiterbearbeitet).
     *
     * @param array<string,mixed> $input subject, description, priority_id, due_at, category_id
     * @return array<string,mixed>
     */
    public function convert(int $ticketId, array $input): array
    {
        $this->currentUser->require('helpdesk.tasks');
        $ticket = $this->tickets->get($ticketId);
        if ($ticket['merged_into_ticket_id'] !== null) {
            throw ValidationException::single('ticket', 'Zusammengeführte Tickets können nicht in eine Aufgabe umgewandelt werden.');
        }
        if (in_array((string) $ticket['status_code'], TicketWorkflowService::FINAL, true)) {
            throw ValidationException::single('ticket', 'Geschlossene oder stornierte Tickets können nicht in eine Aufgabe umgewandelt werden.');
        }
        if ($this->tasks->findByTicket($ticketId) !== null) {
            throw ValidationException::single('ticket', 'Für dieses Ticket existiert bereits eine Aufgabe.');
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            $subject = (string) $ticket['subject'];
        }
        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            $description = (string) $ticket['description'];
        }
        $priorityId = $this->validPriorityId($input['priority_id'] ?? null) ?? (int) $ticket['priority_id'];
        $category = $this->validCategory($input['category_id'] ?? null);
        $dueAt = $this->normalizeDueAt($input['due_at'] ?? null);
        $now = gmdate('Y-m-d H:i:s');
        $userId = $this->currentUser->id();
        $userName = $this->currentUser->displayName();

        $taskId = $this->tasks->transaction(function () use ($ticketId, $ticket, $subject, $description, $priorityId, $category, $dueAt, $now, $userId, $userName): int {
            $number = $this->numbers->next();
            $id = $this->tasks->create([
                'number' => $number,
                'subject' => mb_substr($subject, 0, 255),
                'description' => mb_substr($description, 0, TicketService::MAX_TEXT_CHARS),
                'status' => 'open',
                'category_id' => $category['id'] ?? null,
                'priority_id' => $priorityId,
                'source_ticket_id' => $ticketId,
                'due_at' => $dueAt,
                'created_by' => $userId,
                'created_by_name' => mb_substr($userName, 0, 200),
                'updated_by' => $userId,
            ]);
            $this->tasks->addEvent($id, 'created', $userId, $userName, null, null, null, array_filter([
                'number' => $number,
                'ticket_id' => $ticketId,
                'ticket_number' => (string) $ticket['number'],
                'category' => $category['name'] ?? null,
            ], static fn (mixed $value): bool => $value !== null));
            // Das Ticket wird als Systemschritt geschlossen: die Bearbeitung läuft in der Aufgabe weiter.
            $this->tickets->changeStatus(
                $ticketId,
                TicketWorkflowService::CLOSED,
                'In Aufgabe ' . $number . ' umgewandelt.',
                null,
                null,
                null,
                true,
                null,
                true
            );
            $this->tasks->addEvent($id, 'converted_from_ticket', $userId, $userName, null, null, (string) $ticket['number'], [
                'ticket_id' => $ticketId,
                'ticket_number' => (string) $ticket['number'],
            ]);

            return $id;
        });

        $task = $this->get($taskId);
        $this->audit->log('task.convert', 'task', $taskId, (string) $task['number'], null, [
            'subject' => (string) $task['subject'],
            'source_ticket_id' => $ticketId,
            'category_id' => $task['category_id'],
        ]);

        return $task;
    }

    // ------------------------------------------------------------------ Annahme / Zuweisung

    /** Agent nimmt eine Aufgabe aus „Alle Aufgaben“ an; sie erscheint danach unter „Meine Aufgaben“. */
    public function claim(int $id): array
    {
        $this->currentUser->require('helpdesk.tasks');
        $userId = $this->currentUser->id();
        if ($userId === null) {
            throw new ForbiddenException('Zum Annehmen einer Aufgabe ist eine Anmeldung erforderlich.');
        }
        $task = $this->get($id);
        if (in_array((string) $task['status'], self::FINAL, true)) {
            throw ValidationException::single('status', 'Abgeschlossene Aufgaben können nicht angenommen werden.');
        }
        if ((int) ($task['assignee_user_id'] ?? 0) === $userId) {
            return $task;
        }
        if ($task['assignee_user_id'] !== null) {
            throw ValidationException::single('assignee_user_id', 'Die Aufgabe ist bereits einem Agenten zugewiesen.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $update = [
            'assignee_user_id' => $userId,
            'assigned_by_user_id' => null,
            'assigned_at' => null,
            'claimed_at' => $now,
            'status' => $task['status'] === 'open' ? 'in_progress' : $task['status'],
            'updated_by' => $userId,
        ];
        $this->tasks->transaction(function () use ($id, $update, $task, $userId): void {
            $this->save($id, $update, (int) $task['version']);
            $this->tasks->addEvent($id, 'claimed', $userId, $this->currentUser->displayName(), 'assignee_user_id', null, $this->currentUser->displayName());
        });
        $this->audit->log('task.claim', 'task', $id, (string) $task['number'], null, ['assignee_user_id' => $userId]);

        return $this->get($id);
    }

    /**
     * Weist eine Aufgabe einem Agenten zu (Berechtigungsgruppe „Aufgabenverteilung“).
     * Ohne Agent wird die Zuweisung aufgehoben; die Aufgabe liegt dann wieder in „Alle Aufgaben“.
     */
    public function assign(int $id, ?int $assigneeId): array
    {
        $this->currentUser->require('helpdesk.tasks.assign');
        $userId = $this->currentUser->id();
        $task = $this->get($id);
        if (in_array((string) $task['status'], self::FINAL, true)) {
            throw ValidationException::single('status', 'Abgeschlossene Aufgaben können nicht zugewiesen werden.');
        }

        $now = gmdate('Y-m-d H:i:s');
        if ($assigneeId === null) {
            $previous = $task['assignee_name'] !== null ? (string) $task['assignee_name'] : null;
            $update = [
                'assignee_user_id' => null,
                'assigned_by_user_id' => null,
                'assigned_at' => null,
                'claimed_at' => null,
                'status' => $task['status'] === 'in_progress' ? 'open' : $task['status'],
                'updated_by' => $userId,
            ];
            $this->tasks->transaction(function () use ($id, $update, $task, $userId, $previous): void {
                $this->save($id, $update, (int) $task['version']);
                $this->tasks->addEvent($id, 'unassigned', $userId, $this->currentUser->displayName(), 'assignee_user_id', $previous, null);
            });
            $this->audit->log('task.unassign', 'task', $id, (string) $task['number'], ['assignee_user_id' => $task['assignee_user_id']], ['assignee_user_id' => null]);

            return $this->get($id);
        }

        $assignee = null;
        foreach ($this->assignableAgents() as $agent) {
            if ((int) $agent['id'] === $assigneeId) {
                $assignee = $agent;
                break;
            }
        }
        if ($assignee === null) {
            throw ValidationException::single('assignee_user_id', 'Bitte einen aktiven Agenten auswählen.');
        }

        $update = [
            'assignee_user_id' => $assigneeId,
            'assigned_by_user_id' => $userId,
            'assigned_at' => $now,
            'claimed_at' => null,
            'status' => $task['status'] === 'open' ? 'in_progress' : $task['status'],
            'updated_by' => $userId,
        ];
        $this->tasks->transaction(function () use ($id, $update, $task, $userId, $assignee): void {
            $this->save($id, $update, (int) $task['version']);
            $this->tasks->addEvent($id, 'assigned', $userId, $this->currentUser->displayName(), 'assignee_user_id', $task['assignee_name'] !== null ? (string) $task['assignee_name'] : null, (string) $assignee['display_name']);
        });
        $this->audit->log('task.assign', 'task', $id, (string) $task['number'], ['assignee_user_id' => $task['assignee_user_id']], ['assignee_user_id' => $assigneeId]);

        return $this->get($id);
    }

    // ------------------------------------------------------------------ Kategorie

    /**
     * Setzt die Kategorie der Aufgabe (oder entfernt sie mit einer leeren Auswahl). Die Kategorie
     * erscheint als Pill vor dem Aufgabentitel und wird im Verlauf dokumentiert.
     * @param array<string,mixed> $input category_id, version
     * @return array<string,mixed>
     */
    public function changeCategory(int $id, array $input): array
    {
        $this->currentUser->require('helpdesk.tasks');
        $task = $this->get($id);
        $category = $this->validCategory($input['category_id'] ?? null);
        $oldId = (int) ($task['category_id'] ?? 0);
        $newId = (int) ($category['id'] ?? 0);
        if ($oldId === $newId) {
            return $task;
        }

        $userId = $this->currentUser->id();
        $version = isset($input['version']) ? (int) $input['version'] : (int) $task['version'];
        $this->tasks->transaction(function () use ($id, $category, $task, $userId, $version): void {
            $this->save($id, ['category_id' => $category['id'] ?? null, 'updated_by' => $userId], $version);
            $this->tasks->addEvent(
                $id,
                'category_changed',
                $userId,
                $this->currentUser->displayName(),
                'category_id',
                $task['category_name'] !== null ? (string) $task['category_name'] : null,
                $category['name'] ?? null
            );
        });
        $this->audit->log(
            'task.category',
            'task',
            $id,
            (string) $task['number'],
            ['category_id' => $task['category_id']],
            ['category_id' => $category['id'] ?? null]
        );

        return $this->get($id);
    }

    // ------------------------------------------------------------------ Statuswechsel

    /** @param array<string,mixed> $input status, note, version */
    public function changeStatus(int $id, array $input): array
    {
        $this->currentUser->require('helpdesk.tasks');
        $status = trim((string) ($input['status'] ?? ''));
        if (!isset(self::STATUSES[$status])) {
            throw ValidationException::single('status', 'Unbekannter Status.');
        }
        $task = $this->get($id);
        $userId = $this->currentUser->id();
        $isAssignee = $userId !== null && (int) ($task['assignee_user_id'] ?? 0) === $userId;
        if (!$isAssignee && !$this->currentUser->can('helpdesk.tasks.assign')) {
            throw new ForbiddenException('Nur der zugewiesene Agent oder die Aufgabenverteilung kann die Aufgabe ändern.');
        }
        if ((string) $task['status'] === $status) {
            return $task;
        }

        $note = trim((string) ($input['note'] ?? ''));
        $now = gmdate('Y-m-d H:i:s');
        $update = [
            'status' => $status,
            'updated_by' => $userId,
            'completed_at' => $status === 'done' ? $now : null,
            'completed_by' => $status === 'done' ? $userId : null,
        ];
        if ($note !== '') {
            $update['note'] = mb_substr($note, 0, 500);
        }
        if ($status === 'in_progress' && $task['assignee_user_id'] === null) {
            // „In Bearbeitung“ ohne Zuweisung übernimmt der ausführende Agent.
            $update['assignee_user_id'] = $userId;
            $update['claimed_at'] = $now;
            $update['assigned_by_user_id'] = null;
            $update['assigned_at'] = null;
        }
        $version = isset($input['version']) ? (int) $input['version'] : null;
        $this->tasks->transaction(function () use ($id, $update, $task, $userId, $version, $status): void {
            $this->save($id, $update, $version ?? (int) $task['version']);
            $this->tasks->addEvent($id, 'status_changed', $userId, $this->currentUser->displayName(), 'status', (string) $task['status'], $status);
        });
        $this->audit->log('task.status', 'task', $id, (string) $task['number'], ['status' => (string) $task['status']], ['status' => $status]);

        return $this->get($id);
    }

    // ------------------------------------------------------------------ Hilfen

    /**
     * @param array<string,mixed> $data
     * @throws ConflictException wenn die Aufgabe zwischenzeitlich geändert wurde
     */
    private function save(int $id, array $data, int $expectedVersion): void
    {
        if (!$this->tasks->update($id, $data, $expectedVersion)) {
            throw new ConflictException('Die Aufgabe wurde zwischenzeitlich geändert. Bitte neu laden.');
        }
    }

    private function validPriorityId(mixed $value): ?int
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }
        foreach ($this->masterData->priorities() as $priority) {
            if ((int) $priority['id'] === $id) {
                return $id;
            }
        }

        return null;
    }

    /** Kategorie aus dem Katalog; null bei leerer Auswahl, unbekannte IDs werden abgewiesen. @return array<string,mixed>|null */
    private function validCategory(mixed $value): ?array
    {
        $id = (int) $value;
        if ($id <= 0) {
            return null;
        }
        $category = $this->categories->find($id);
        if ($category === null) {
            throw ValidationException::single('category_id', 'Bitte eine Kategorie aus dem Katalog wählen.');
        }

        return $category;
    }

    /** Datumsangabe (JJJJ-MM-TT) als Fälligkeit am Tagesende; null bei leerer/ungültiger Eingabe. */
    private function normalizeDueAt(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $raw . ' 23:59:59';
    }
}
