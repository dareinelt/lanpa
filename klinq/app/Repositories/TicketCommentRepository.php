<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Kommunikationsverlauf eines Tickets (öffentliche Antworten, interne Notizen). Einträge sind inhaltlich
 * unveränderlich; lediglich die Anheftung (`is_pinned`) lässt sich setzen und lösen. Angeheftete Einträge
 * stehen oben, untereinander und zu den übrigen weiterhin chronologisch.
 */
final class TicketCommentRepository extends BaseRepository
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function forTicket(int $ticketId, bool $includeInternal): array
    {
        return $this->fetchAll(
            'SELECT c.*, u.username AS author_username,
                    (SELECT COUNT(*) FROM ticket_attachments ta WHERE ta.comment_id = c.id) AS attachment_count
             FROM ticket_comments c
             LEFT JOIN users u ON u.id = c.author_user_id
             WHERE c.ticket_id = :t' . ($includeInternal ? '' : " AND c.type = 'public'") . '
             ORDER BY c.is_pinned DESC, c.created_at, c.id',
            ['t' => $ticketId]
        );
    }

    /** Heftet einen Kommentar an bzw. löst die Anheftung. */
    public function setPinned(int $id, bool $pinned): int
    {
        return $this->execute(
            'UPDATE ticket_comments SET is_pinned = :p, pinned_at = :at WHERE id = :id',
            ['p' => $pinned ? 1 : 0, 'at' => $pinned ? gmdate('Y-m-d H:i:s') : null, 'id' => $id]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_comments WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_comments', $data);
    }

    /** Verschiebt alle Kommentare eines Tickets (Zusammenführen). */
    public function moveToTicket(int $fromTicketId, int $toTicketId): int
    {
        return $this->execute('UPDATE ticket_comments SET ticket_id = :to WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
    }

    public function countForTicket(int $ticketId, ?string $type = null): int
    {
        $params = ['t' => $ticketId];
        $sql = 'SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = :t';
        if ($type !== null) {
            $sql .= ' AND type = :ty';
            $params['ty'] = $type;
        }

        return (int) $this->fetchValue($sql, $params);
    }

    /** Ob der Benutzer das Ticket im Zeitfenster [startUtc, endUtc) kommentiert hat (2nd-Level-Erinnerung). */
    public function hasCommentByUserBetween(int $ticketId, int $userId, string $startUtc, string $endUtc): bool
    {
        return (int) $this->fetchValue(
            'SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = :t AND author_user_id = :u AND created_at >= :s AND created_at < :e',
            ['t' => $ticketId, 'u' => $userId, 's' => $startUtc, 'e' => $endUtc]
        ) > 0;
    }
}
