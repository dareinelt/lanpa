<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Repositories\TaskAttachmentRepository;
use App\Repositories\TaskRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\DocumentService;

/**
 * Anhänge einer Aufgabe: Ablage über den DocumentService (Datei im Dateisystem, Metadaten in
 * `documents`), Verknüpfung in `task_attachments` samt Ereignis und Audit. Beim Umwandeln eines
 * Tickets in eine Aufgabe übernimmt {@see copyFromTicket()} die Ticketanhänge als eigene Kopien,
 * damit auf beiden Seiten nichts verloren geht.
 */
final class TaskAttachmentService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly TaskAttachmentRepository $attachments,
        private readonly TaskRepository $tasks,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /** Anhänge der Aufgabe; erfordert den Zugriff auf den Aufgabenbereich. @return array<int,array<string,mixed>> */
    public function forTask(int $taskId): array
    {
        $this->task($taskId);

        return $this->attachments->forTask($taskId);
    }

    /** Anhang über die Dokument-ID (für Download/Löschen); die Zuordnung prüft der Aufrufer. @return array<string,mixed>|null */
    public function find(int $documentId): ?array
    {
        return $this->attachments->find($documentId);
    }

    /**
     * Eine hochgeladene Datei ablegen und mit der Aufgabe verknüpfen.
     *
     * @param array<string,mixed> $file $_FILES-Eintrag
     * @return int ID des documents-Datensatzes
     */
    public function store(int $taskId, array $file, ?string $note = null): int
    {
        $task = $this->task($taskId);
        $documentId = $this->documents->store('task', $taskId, 'attachment', $file, $note);
        $this->attachments->link($documentId, $taskId);
        $this->tasks->addEvent(
            $taskId,
            'attachment_added',
            $this->currentUser->id(),
            $this->currentUser->displayName(),
            'attachment',
            null,
            (string) $file['name'],
            ['document_id' => $documentId]
        );
        $this->tasks->update($taskId, ['updated_by' => $this->currentUser->id()]);
        $this->audit->log('upload', 'attachment', $documentId, (string) $task['number'] . ' ' . (string) $file['name'], null, [
            'file' => (string) $file['name'],
            'task_id' => $taskId,
        ]);

        return $documentId;
    }

    /**
     * Übernimmt einen Ticketanhang als eigenen Anhang der Aufgabe (eigene Datei, eigener Datensatz).
     * Die Berechtigung prüft die aufrufende Umwandlung.
     *
     * @param array<string,mixed> $document Zeile aus `documents` (ggf. mit Ticketfeldern)
     * @return int ID des neuen documents-Datensatzes
     */
    public function copyFromTicket(int $taskId, array $document): int
    {
        $documentId = $this->documents->copy($document, 'task', $taskId);
        $this->attachments->link($documentId, $taskId);
        $this->audit->log('copy', 'attachment', $documentId, (string) $document['original_name'], null, [
            'task_id' => $taskId,
            'source_document_id' => (int) $document['id'],
            'source_ticket_id' => $document['ticket_id'] ?? null,
        ]);

        return $documentId;
    }

    /** Anhang löschen – der hochladende Agent oder die Aufgabenverteilung. */
    public function delete(int $taskId, int $documentId): void
    {
        $task = $this->task($taskId);
        $document = $this->attachments->find($documentId);
        if ($document === null) {
            throw new NotFoundException('Anhang nicht gefunden.');
        }
        if ((int) $document['task_id'] !== $taskId) {
            throw new ForbiddenException('Anhang gehört nicht zu dieser Aufgabe.');
        }
        $own = (int) ($document['uploaded_by'] ?? 0) === (int) $this->currentUser->id();
        if (!$own && !$this->currentUser->can('helpdesk.tasks.assign')) {
            throw new ForbiddenException('Keine Berechtigung zum Löschen dieses Anhangs.');
        }

        $this->documents->delete($document);
        $this->tasks->addEvent(
            $taskId,
            'attachment_removed',
            $this->currentUser->id(),
            $this->currentUser->displayName(),
            'attachment',
            (string) $document['original_name'],
            null
        );
        $this->audit->log('delete', 'attachment', $documentId, (string) $task['number'] . ' ' . (string) $document['original_name'], [
            'file' => (string) $document['original_name'],
        ], null);
    }

    /** Aufgabe laden und den Aufgabenbereich prüfen (wie TaskService::get()). @return array<string,mixed> */
    private function task(int $taskId): array
    {
        $this->currentUser->require('helpdesk.tasks');
        $task = $this->tasks->find($taskId);
        if ($task === null) {
            throw new NotFoundException('Aufgabe nicht gefunden.');
        }

        return $task;
    }
}
