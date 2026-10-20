<?php

declare(strict_types=1);

namespace App\Repositories;

/** Arbeitszeiterfassung je Ticket. */
final class TicketWorklogRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function forTicket(int $ticketId): array
    {
        return $this->fetchAll('SELECT w.* FROM ticket_worklogs w WHERE w.ticket_id = :t ORDER BY COALESCE(w.started_at, w.created_at) DESC, w.id DESC', ['t' => $ticketId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_worklogs WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_worklogs', $data);
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM ticket_worklogs WHERE id = :id', ['id' => $id]);
    }

    public function totalMinutes(int $ticketId): int
    {
        return (int) $this->fetchValue('SELECT COALESCE(SUM(minutes), 0) FROM ticket_worklogs WHERE ticket_id = :t', ['t' => $ticketId]);
    }

    public function moveToTicket(int $fromTicketId, int $toTicketId): int
    {
        return $this->execute('UPDATE ticket_worklogs SET ticket_id = :to WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
    }
}
