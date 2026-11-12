<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Checklisten an Terminen des Help-Desk-Kalenders (`helpdesk_calendar_checklist_items`) und deren
 * Bestätigungen je Vorkommen (`helpdesk_calendar_checklist_confirmations`).
 *
 * `occurrence_key` ist beim Einzeltermin '' und bei Serienterminen der lokale Termintag (Y-m-d).
 */
final class HelpdeskCalendarChecklistRepository extends BaseRepository
{
    /** Punkte eines Termins in Anzeigereihenfolge. @return list<array<string,mixed>> */
    public function items(int $eventId): array
    {
        return $this->fetchAll(
            'SELECT * FROM helpdesk_calendar_checklist_items WHERE event_id = :e ORDER BY position, id',
            ['e' => $eventId]
        );
    }

    /**
     * Punkte eines Termins mit der Bestätigung für ein Vorkommen (confirmed_* = NULL, wenn offen).
     * @return list<array<string,mixed>>
     */
    public function itemsWithConfirmations(int $eventId, string $occurrenceKey): array
    {
        return $this->fetchAll(
            'SELECT i.*, c.confirmed_by, c.confirmed_by_name, c.confirmed_at
                FROM helpdesk_calendar_checklist_items i
                LEFT JOIN helpdesk_calendar_checklist_confirmations c ON c.item_id = i.id AND c.occurrence_key = :k
                WHERE i.event_id = :e ORDER BY i.position, i.id',
            ['e' => $eventId, 'k' => $occurrenceKey]
        );
    }

    /**
     * Anzahl der Punkte je Termin.
     * @param list<int> $eventIds
     * @return array<int,int>
     */
    public function countItems(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = $this->fetchAll(
            'SELECT event_id, COUNT(*) AS cnt FROM helpdesk_calendar_checklist_items
                WHERE event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ') GROUP BY event_id',
            array_values($eventIds)
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['event_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Anzahl bestätigter Punkte je Termin und Vorkommen.
     * @param list<int> $eventIds
     * @return array<int,array<string,int>> event_id → occurrence_key → Anzahl
     */
    public function countConfirmations(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }
        $rows = $this->fetchAll(
            'SELECT i.event_id, c.occurrence_key, COUNT(*) AS cnt
                FROM helpdesk_calendar_checklist_confirmations c
                JOIN helpdesk_calendar_checklist_items i ON i.id = c.item_id
                WHERE i.event_id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ')
                GROUP BY i.event_id, c.occurrence_key',
            array_values($eventIds)
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['event_id']][(string) $row['occurrence_key']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /** @param array<string,mixed> $data */
    public function createItem(array $data): int
    {
        return $this->insertRow('helpdesk_calendar_checklist_items', $data);
    }

    public function updateItem(int $id, string $label, int $position): int
    {
        return $this->execute(
            'UPDATE helpdesk_calendar_checklist_items SET label = :l, position = :p WHERE id = :id',
            ['l' => $label, 'p' => $position, 'id' => $id]
        );
    }

    public function deleteItem(int $id): int
    {
        return $this->execute('DELETE FROM helpdesk_calendar_checklist_items WHERE id = :id', ['id' => $id]);
    }

    public function confirm(int $itemId, string $occurrenceKey, ?int $userId, string $userName, string $confirmedAtUtc): void
    {
        $this->execute(
            'INSERT IGNORE INTO helpdesk_calendar_checklist_confirmations (item_id, occurrence_key, confirmed_by, confirmed_by_name, confirmed_at)
                VALUES (:i, :k, :u, :n, :at)',
            ['i' => $itemId, 'k' => $occurrenceKey, 'u' => $userId, 'n' => $userName, 'at' => $confirmedAtUtc]
        );
    }

    public function revoke(int $itemId, string $occurrenceKey): int
    {
        return $this->execute(
            'DELETE FROM helpdesk_calendar_checklist_confirmations WHERE item_id = :i AND occurrence_key = :k',
            ['i' => $itemId, 'k' => $occurrenceKey]
        );
    }
}
