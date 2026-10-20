<?php

declare(strict_types=1);

namespace App\Repositories;

/** Ticketanhänge: Verbindung zwischen documents (Datei) und Ticket/Kommentar inkl. Sichtbarkeit. */
final class TicketAttachmentRepository extends BaseRepository
{
    private const SELECT = 'SELECT d.*, ta.ticket_id, ta.comment_id, ta.is_internal
        FROM ticket_attachments ta
        JOIN documents d ON d.id = ta.document_id';

    /** @return array<int,array<string,mixed>> */
    public function forTicket(int $ticketId, bool $includeInternal): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE ta.ticket_id = :t' . ($includeInternal ? '' : ' AND ta.is_internal = 0') . ' ORDER BY d.created_at, d.id', ['t' => $ticketId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $documentId): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE ta.document_id = :d', ['d' => $documentId]);
    }

    public function link(int $documentId, int $ticketId, ?int $commentId, bool $internal): void
    {
        $this->execute('INSERT INTO ticket_attachments (document_id, ticket_id, comment_id, is_internal) VALUES (:d, :t, :c, :i)', ['d' => $documentId, 't' => $ticketId, 'c' => $commentId, 'i' => $internal ? 1 : 0]);
    }

    public function moveToTicket(int $fromTicketId, int $toTicketId): int
    {
        $this->execute("UPDATE documents d JOIN ticket_attachments ta ON ta.document_id = d.id SET d.entity_id = :to WHERE ta.ticket_id = :from AND d.entity_type = 'ticket'", ['to' => $toTicketId, 'from' => $fromTicketId]);

        return $this->execute('UPDATE ticket_attachments SET ticket_id = :to WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
    }

    public function countForTicket(int $ticketId): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM ticket_attachments WHERE ticket_id = :t', ['t' => $ticketId]);
    }
}
