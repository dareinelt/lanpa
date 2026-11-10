<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Termine des Help-Desk-Kalenders (`helpdesk_calendar_events`). Alle Zeitpunkte in UTC.
 */
final class HelpdeskCalendarRepository extends BaseRepository
{
    private const SELECT = "SELECT e.*, t.number AS ticket_number, t.subject AS ticket_subject
        FROM helpdesk_calendar_events e
        LEFT JOIN tickets t ON t.id = e.ticket_id";

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE e.id = :id', ['id' => $id]);
    }

    /**
     * Termine, die den Zeitraum [von, bis) berühren (auch mehrtägige und ganztägige Termine).
     * @return list<array<string,mixed>>
     */
    public function between(string $fromUtc, string $toUtc): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE e.starts_at < :to AND e.ends_at > :from ORDER BY e.all_day DESC, e.starts_at, e.ends_at, e.id',
            ['from' => $fromUtc, 'to' => $toUtc]
        );
    }

    /** Termine eines Tickets, neueste zuerst. @return list<array<string,mixed>> */
    public function forTicket(int $ticketId): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE e.ticket_id = :t ORDER BY e.starts_at, e.id', ['t' => $ticketId]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('helpdesk_calendar_events', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): int
    {
        return $this->updateRow('helpdesk_calendar_events', $id, $data);
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM helpdesk_calendar_events WHERE id = :id', ['id' => $id]);
    }
}
