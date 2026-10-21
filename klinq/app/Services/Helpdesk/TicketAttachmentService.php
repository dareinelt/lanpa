<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\DocumentService;

/**
 * Anhänge eines Tickets: Ablage über den DocumentService (Datei im Dateisystem,
 * Metadaten in `documents`), Verknüpfung in `ticket_attachments` samt Ereignis und Audit.
 * Wird von der Kommentar-/Upload-Aktion und von der Ticketanlage (Assistent und
 * klassisches Formular) genutzt.
 */
final class TicketAttachmentService
{
    /** Höchstzahl Dateien je Formularvorgang. */
    public const MAX_FILES_PER_UPLOAD = 5;

    public function __construct(
        private readonly TicketService $tickets,
        private readonly DocumentService $documents,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketRepository $ticketRepository,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {
    }

    /**
     * Eine hochgeladene Datei ablegen und mit dem Ticket verknüpfen.
     *
     * @param array<string,mixed> $file $_FILES-Eintrag
     * @return int ID des documents-Datensatzes
     */
    public function store(int $ticketId, ?int $commentId, bool $internal, array $file, ?string $note = null): int
    {
        $ticket = $this->tickets->getVisible($ticketId);
        if (!$this->tickets->isAgent() && !$this->tickets->isOwnTicket($ticket)) {
            throw new ForbiddenException('Keine Berechtigung für dieses Ticket.');
        }
        $documentId = $this->documents->store('ticket', $ticketId, 'attachment', $file, $note);
        $this->attachments->link($documentId, $ticketId, $commentId, $internal);
        $this->ticketRepository->addEvent(
            $ticketId,
            'attachment_added',
            $this->currentUser->id(),
            $this->currentUser->displayName(),
            'attachment',
            null,
            (string) $file['name'],
            ['document_id' => $documentId, 'internal' => $internal]
        );
        $this->ticketRepository->update($ticketId, ['updated_by' => $this->currentUser->id()]);
        $this->audit->log('upload', 'attachment', $documentId, $ticket['number'] . ' ' . $file['name'], null, ['file' => $file['name'], 'internal' => $internal]);

        return $documentId;
    }

    /**
     * Mehrfachauswahl eines Formularfelds (`name="attachments[]"`) ablegen.
     * Fehlerhafte Dateien brechen den Vorgang nicht ab, sondern werden gemeldet.
     *
     * @param array<string,mixed>|null $upload $_FILES-Eintrag des Feldes
     * @return array{saved:int,errors:list<string>}
     */
    public function storeAll(int $ticketId, ?array $upload, bool $internal = false): array
    {
        $saved = 0;
        $errors = [];
        foreach ($this->normalize($upload ?? []) as $file) {
            if ($saved >= self::MAX_FILES_PER_UPLOAD) {
                $errors[] = 'Es wurden nur die ersten ' . self::MAX_FILES_PER_UPLOAD . ' Dateien übernommen.';

                break;
            }
            try {
                $this->store($ticketId, null, $internal, $file);
                $saved++;
            } catch (ValidationException $exception) {
                $errors[] = (string) $file['name'] . ': ' . implode(' ', $exception->errors());
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Normalisiert Einzel- und Mehrfachauswahl aus $_FILES zu einer Liste.
     *
     * @param array<string,mixed> $upload
     * @return list<array<string,mixed>>
     */
    private function normalize(array $upload): array
    {
        if (!isset($upload['name'])) {
            return [];
        }
        if (!is_array($upload['name'])) {
            return (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$upload];
        }
        $files = [];
        foreach (array_keys($upload['name']) as $index) {
            if ((int) ($upload['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => (string) $upload['name'][$index],
                'type' => (string) ($upload['type'][$index] ?? ''),
                'tmp_name' => (string) ($upload['tmp_name'][$index] ?? ''),
                'error' => (int) $upload['error'][$index],
                'size' => (int) ($upload['size'][$index] ?? 0),
            ];
        }

        return $files;
    }
}
