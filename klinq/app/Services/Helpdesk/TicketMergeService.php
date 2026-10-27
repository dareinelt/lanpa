<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Logger;
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
use App\Support\RichText;

/**
 * Zusammenführen von Duplikaten: Das Quellticket wird storniert („Duplikat“), alle Inhalte
 * (Kommentare, Anhänge, Arbeitszeiten, Assets, Tags, Watcher, Beziehungen) wandern zum Zielticket.
 * Die Ticketnummer des Quelltickets bleibt als Verweis erhalten.
 */
final class TicketMergeService
{
    /** Auswählbare Zusammenführungsgründe (Code → Bezeichnung). */
    public const REASONS = [
        'duplicate' => 'Duplikat',
        'same_topic' => 'Thema identisch',
        'same_incident' => 'Gehört zum selben Vorgang',
        'accidental_split' => 'Versehentlich getrennt erfasst',
        'other' => 'Sonstiger Grund',
    ];

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
        private readonly CurrentUser $currentUser,
        private readonly TicketMailService $mail,
        private readonly Logger $logger
    ) {}

    /**
     * Angaben für den Zusammenführen-Dialog: Gründe, Empfänger und die
     * bearbeitbare Info-E-Mail.
     *
     * @param array<string,mixed> $source
     * @return array{reasons:list<array{code:string,name:string}>,default_reason:string,
     *               mail_available:bool,mail_reason:string,requester:array{name:string,email:string},
     *               subject:string,body_html:string,max_chars:int}
     */
    public function composeData(array $source): array
    {
        $mail = $this->mail->mergeComposeData($source);
        $reasons = [];
        foreach (self::REASONS as $code => $name) {
            $reasons[] = ['code' => $code, 'name' => $name];
        }

        return [
            'reasons' => $reasons,
            'default_reason' => 'duplicate',
            'mail_available' => $mail['available'],
            'mail_reason' => $mail['reason'],
            'requester' => $mail['requester'],
            'subject' => $mail['subject'],
            'body_html' => $mail['body_html'],
            'max_chars' => $mail['max_chars'],
        ];
    }

    /**
     * @param array<string,mixed> $options reason_code, reason_note, notify, mail_subject, mail_body_html
     * @return array<string,mixed> Zielticket; zusätzlich merge_mail bzw. merge_mail_error
     */
    public function merge(int $sourceId, string $targetNumberOrId, array $options = []): array
    {
        $this->currentUser->require('helpdesk.merge');
        $reason = $this->resolveReason((string) ($options['reason_code'] ?? ''), (string) ($options['reason_note'] ?? ''));
        $source = $this->tickets->find($sourceId);
        if ($source === null) {
            throw ValidationException::single('target', 'Quellticket nicht gefunden.');
        }
        $target = $this->findTarget($targetNumberOrId);
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

        $this->tickets->transaction(function () use ($source, $target, $sourceId, $targetId, $cancelled, $userId, $userName, $reason): void {
            $moved = [
                'comments' => $this->comments->moveToTicket($sourceId, $targetId),
                'attachments' => $this->attachments->moveToTicket($sourceId, $targetId),
                'worklogs' => $this->worklogs->moveToTicket($sourceId, $targetId),
                'reason' => $reason['code'],
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
                'body' => "Zusammengeführt aus {$source['number']} „{$source['subject']}“ (Melder: " . ($source['requester_name'] ?? $source['requester_user_name'] ?? '–') . ")\nGrund: {$reason['label']}\n\n" . RichText::toPlain((string) $source['description']),
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
                'close_reason' => mb_substr($reason['label'] . ' (' . $target['number'] . ')', 0, 255),
                'sla_paused_at' => null,
                'paused_until' => null,
                'updated_by' => $userId,
            ]);
            $this->tickets->update($targetId, ['updated_by' => $userId]);
            $this->tickets->addEvent($sourceId, 'merged', $userId, $userName, 'merged_into', null, (string) $target['number'], $moved);
            $this->tickets->addEvent($targetId, 'merge_received', $userId, $userName, 'merged_from', null, (string) $source['number'], $moved);
        });

        $this->audit->log('merge', 'ticket', $sourceId, $source['number'] . ' → ' . $target['number'], ['status' => $source['status_name']], ['status' => $cancelled['name'], 'merged_into' => $target['number'], 'reason' => $reason['label']]);

        $result = $this->tickets->find($targetId) ?? $target;
        $result['merge_mail'] = null;
        $result['merge_mail_error'] = '';
        if (!empty($options['notify'])) {
            try {
                $result['merge_mail'] = $this->mail->sendMergeNotice($source, $result, $reason['label'], [
                    'subject' => (string) ($options['mail_subject'] ?? ''),
                    'body_html' => (string) ($options['mail_body_html'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Info-E-Mail zur Zusammenführung fehlgeschlagen', ['ticket' => $source['number'], 'target' => $target['number'], 'error' => $e->getMessage()]);
                $result['merge_mail_error'] = $e instanceof ValidationException ? $e->getMessage() : 'Der E-Mail-Versand ist fehlgeschlagen.';
            }
        }

        return $result;
    }

    /**
     * Führt mehrere Quelltickets in ein gemeinsames Zielticket zusammen.
     *
     * Das Zielticket selbst wird aus den Quellen entfernt; einzelne Fehler
     * (z. B. bereits zusammengeführte Tickets) brechen die übrigen
     * Zusammenführungen nicht ab.
     *
     * @param list<int|string> $sourceIds
     * @param array<string,mixed> $options siehe merge()
     * @return array{target:array<string,mixed>,merged:list<array{id:int,number:string}>,errors:list<string>}
     */
    public function mergeMany(array $sourceIds, string $targetNumberOrId, array $options = []): array
    {
        $this->currentUser->require('helpdesk.merge');
        $target = $this->findTarget($targetNumberOrId);
        $targetId = (int) $target['id'];

        $ids = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = (int) $sourceId;
            if ($sourceId > 0 && $sourceId !== $targetId) {
                $ids[$sourceId] = $sourceId;
            }
        }
        if (count($ids) < 2) {
            throw ValidationException::single('sources', 'Bitte mindestens zwei Tickets auswählen, die zusammengeführt werden sollen.');
        }

        $merged = [];
        $errors = [];
        foreach ($ids as $sourceId) {
            $source = $this->tickets->find($sourceId);
            $label = $source !== null ? (string) $source['number'] : '#' . $sourceId;
            try {
                $this->merge($sourceId, (string) $targetId, $options);
                $merged[] = ['id' => $sourceId, 'number' => $label];
            } catch (ValidationException $e) {
                $errors[] = $label . ': ' . implode(' ', $e->errors());
            }
        }

        return [
            'target' => $this->tickets->find($targetId) ?? $target,
            'merged' => $merged,
            'errors' => $errors,
        ];
    }

    /**
     * Zielticket anhand von Nummer oder ID laden.
     *
     * @return array<string,mixed>
     */
    private function findTarget(string $targetNumberOrId): array
    {
        $target = ctype_digit($targetNumberOrId)
            ? $this->tickets->find((int) $targetNumberOrId)
            : $this->tickets->findByNumber(TicketNumberService::normalize($targetNumberOrId) ?? $targetNumberOrId);
        if ($target === null) {
            throw ValidationException::single('target', 'Zielticket nicht gefunden.');
        }

        return $target;
    }

    /**
     * Grund prüfen und Bezeichnung für Protokoll, Abschlussgrund und Info-Mail bauen.
     *
     * @return array{code:string,name:string,note:string,label:string}
     */
    private function resolveReason(string $code, string $note): array
    {
        $code = trim($code);
        if ($code === '') {
            $code = 'duplicate';
        }
        if (!isset(self::REASONS[$code])) {
            throw ValidationException::single('reason_code', 'Unbekannter Zusammenführungsgrund.');
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) > 160) {
            $note = mb_substr($note, 0, 160);
        }
        $name = self::REASONS[$code];

        return [
            'code' => $code,
            'name' => $name,
            'note' => $note,
            'label' => $note === '' ? $name : $name . ' – ' . $note,
        ];
    }
}
