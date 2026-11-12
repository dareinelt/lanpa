<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Termine des Help-Desk-Kalenders (`helpdesk_calendar_events`). Alle Zeitpunkte in UTC.
 *
 * Serientermine stehen einmal in der Tabelle (`recurrence`); abweichende oder gelöschte Einzeltermine
 * verweisen über `series_parent_id` + `series_date` auf ihren Serientermin.
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
     * Gelöschte Einzeltermine einer Serie bleiben außen vor.
     * @return list<array<string,mixed>>
     */
    public function overlapping(string $fromUtc, string $toUtc): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE e.starts_at < :to AND e.ends_at > :from AND e.series_canceled = 0
                ORDER BY e.all_day DESC, e.starts_at, e.ends_at, e.id',
            ['from' => $fromUtc, 'to' => $toUtc]
        );
    }

    /**
     * Serientermine, die vor dem Zeitraum beginnen und dort weiterlaufen (unbefristet oder bis `fromDay`).
     * @param string $fromDay erster Tag des Zeitraums (lokal, Y-m-d) für den Abgleich mit `recurrence_until`
     * @return list<array<string,mixed>>
     */
    public function recurring(string $fromDay, string $toUtc): array
    {
        return $this->fetchAll(
            self::SELECT . " WHERE e.recurrence <> '' AND e.series_parent_id IS NULL AND e.starts_at < :to
                AND (e.recurrence_until IS NULL OR e.recurrence_until >= :fromDay) AND e.series_canceled = 0
                ORDER BY e.starts_at, e.id",
            ['fromDay' => $fromDay, 'to' => $toUtc]
        );
    }

    /**
     * Einzeltermine von Serien mit Ursprungstag im Zeitraum (verschoben, geändert oder gelöscht).
     * Gelöschte Termine dienen nur als Ausblendemarke und werden von den Ansichten übersprungen.
     * @return list<array<string,mixed>>
     */
    public function seriesChildren(string $fromDay, string $toDay): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE e.series_parent_id IS NOT NULL AND e.series_date >= :fromDay AND e.series_date <= :toDay
                ORDER BY e.series_date, e.id',
            ['fromDay' => $fromDay, 'toDay' => $toDay]
        );
    }

    /** Alle Einzeltermine eines Serientermins (auch gelöschte). @return list<array<string,mixed>> */
    public function childrenOf(int $parentId): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE e.series_parent_id = :p ORDER BY e.series_date, e.id',
            ['p' => $parentId]
        );
    }

    /** Termine eines Tickets, älteste zuerst; gelöschte Einzeltermine einer Serie entfallen. @return list<array<string,mixed>> */
    public function forTicket(int $ticketId): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE e.ticket_id = :t AND e.series_canceled = 0 ORDER BY e.starts_at, e.id',
            ['t' => $ticketId]
        );
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

    /** Entfernt alle Einzeltermine eines Serientermins (z. B. wenn die ganze Serie geändert wird). */
    public function deleteChildren(int $parentId): int
    {
        return $this->execute('DELETE FROM helpdesk_calendar_events WHERE series_parent_id = :p', ['p' => $parentId]);
    }
}
