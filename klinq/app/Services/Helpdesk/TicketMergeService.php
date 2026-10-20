<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Exceptions\ValidationException;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketWorklogRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;

/**
 * Zusammenführen von Duplikaten: Das Quellticket wird storniert („Duplikat“), alle Inhalte
 * (Kommentare, Anhänge, Arbeitszeiten, Assets, Tags, Watcher, Beziehungen) wandern zum Zielticket.
 * Die Ticketnummer des Quelltickets bleibt als Verweis erhalten.
 */
final class TicketMergeService
{
    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketCommentRepository $comments,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketWorklogRepository $worklogs,
        private readonly TicketRelationRepository $relations,
        private readonly TicketTagRepository $tags,
        private readonly TicketWorkflowService $workflow,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /** @return array<string,mixed> Zielticket */
    public function merge(int $sourceId, string $targetNumberOrId): array
    {
        $this->currentUser->require('helpdesk.merge');
        $source = $this->tickets->find($sourceId);
        if ($source === null) {
            throw ValidationException::single('target', 'Quellticket nicht gefunden.');
        }
        $target = ctype_digit($targetNumberOrId) ? $this->tickets->find((int) $targetNumberOrId) : $this->tickets->findByNumber(TicketNumberService::normalize($targetNumberOrId) ?? $targetNumberOrId);
        if ($target === null) {
            throw ValidationException::single('target', 'Zielticket nicht gefunden.');
        }
        if ((int) $target['id'] === $sourceId) {
            throw ValidationException::single('target', 'Ein Ticket kann nicht mit sich selbst zusammengeführt werden.');
        }
        if (!empty($source['merged_into_ticket_id'])) {
            throw ValidationException::single('target', 'Das Quellticket wurde bereits zusammengeführt.');
        }
        if (!empty($target['merged_into_ticket_id'])) {
            throw ValidationException::single('target', 'Das Zielticket wurde selbst bereits zusammengeführt – bitte ' . $target['merged_into_number'] . ' verwenden.');
        }
        if ($this->workflow->isFinal((string) $target['status_code'])) {
            throw ValidationException::single('target', 'Das Zielticket ist bereits abgeschlossen.');
        }
        $cancelled = $this->masterData->statusByCode(TicketWorkflowService::CANCELLED);
        if ($cancelled === null) {
            throw ValidationException::single('target', 'Status „storniert“ fehlt in den Stammdaten.');
        }

        $userId = $this->currentUser->id();
        $userName = $this->currentUser->displayName();
        $targetId = (int) $target['id'];

        $this->tickets->transaction(function () use ($source, $target, $sourceId, $targetId, $cancelled, $userId, $userName): void {
            $moved = [
                'comments' => $this->comments->moveToTicket($sourceId, $targetId),
                'attachments' => $this->attachments->moveToTicket($sourceId, $targetId),
                'worklogs' => $this->worklogs->moveToTicket($sourceId, $targetId),
            ];
            $this->tickets->moveAssets($sourceId, $targetId);
            $this->tickets->moveWatchers($sourceId, $targetId);
            $this->tags->copyToTicket($sourceId, $targetId);
            $this->relations->moveToTicket($sourceId, $targetId);
            if (!$this->relations->exists($sourceId, $targetId, 'duplicate_of')) {
                $this->relations->create($sourceId, $targetId, 'duplicate_of', $userId);
            }
            // Melder des Quelltickets wird Watcher am Ziel, damit er weiterhin informiert wird
            if (!empty($source['requester_user_id'])) {
                $this->tickets->addWatcher($targetId, (int) $source['requester_user_id']);
            }
            // Beschreibung des Quelltickets als öffentlichen Kommentar im Ziel erhalten
            $this->comments->create([
                'ticket_id' => $targetId,
                'type' => 'internal',
                'body' => "Zusammengeführt aus {$source['number']} „{$source['subject']}“ (Melder: " . ($source['requester_name'] ?? $source['requester_user_name'] ?? '–') . ")\n\n" . $source['description'],
                'author_user_id' => $userId,
                'author_name' => $userName,
                'is_requester' => 0,
                'source' => 'web',
            ]);
            $now = gmdate('Y-m-d H:i:s');
            $this->tickets->update($sourceId, [
                'status_id' => (int) $cancelled['id'],
                'merged_into_ticket_id' => $targetId,
                'cancelled_at' => $now,
                'close_reason' => 'Duplikat von ' . $target['number'],
                'sla_paused_at' => null,
                'updated_by' => $userId,
            ]);
            $this->tickets->update($targetId, ['updated_by' => $userId]);
            $this->tickets->addEvent($sourceId, 'merged', $userId, $userName, 'merged_into', null, (string) $target['number'], $moved);
            $this->tickets->addEvent($targetId, 'merge_received', $userId, $userName, 'merged_from', null, (string) $source['number'], $moved);
        });

        $this->audit->log('merge', 'ticket', $sourceId, $source['number'] . ' → ' . $target['number'], ['status' => $source['status_name']], ['status' => $cancelled['name'], 'merged_into' => $target['number']]);

        return $this->tickets->find($targetId) ?? $target;
    }
}
