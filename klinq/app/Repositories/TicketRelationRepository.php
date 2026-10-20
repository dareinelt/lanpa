<?php

declare(strict_types=1);

namespace App\Repositories;

/** Beziehungen zwischen Tickets (Duplikat, Haupt-/Unterticket, verwandt, Problem→Incident, Change→Ticket). */
final class TicketRelationRepository extends BaseRepository
{
    public const TYPES = ['duplicate_of', 'parent_of', 'related', 'problem_of', 'change_for'];

    /**
     * Beziehungen in beiden Richtungen; „direction“ = out (ticket_id ist Ausgang) oder in.
     * @return array<int,array<string,mixed>>
     */
    public function forTicket(int $ticketId): array
    {
        return $this->fetchAll(
            "SELECT r.id, r.type, r.created_at, 'out' AS direction, o.id AS other_id, o.number AS other_number, o.subject AS other_subject,
                    st.name AS other_status_name, st.color AS other_status_color, st.category AS other_status_category
             FROM ticket_relations r JOIN tickets o ON o.id = r.related_ticket_id JOIN ticket_statuses st ON st.id = o.status_id
             WHERE r.ticket_id = :t
             UNION ALL
             SELECT r.id, r.type, r.created_at, 'in' AS direction, o.id, o.number, o.subject, st.name, st.color, st.category
             FROM ticket_relations r JOIN tickets o ON o.id = r.ticket_id JOIN ticket_statuses st ON st.id = o.status_id
             WHERE r.related_ticket_id = :t
             ORDER BY type, other_number",
            ['t' => $ticketId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_relations WHERE id = :id', ['id' => $id]);
    }

    public function exists(int $ticketId, int $relatedId, string $type): bool
    {
        return $this->fetchValue('SELECT 1 FROM ticket_relations WHERE ticket_id = :t AND related_ticket_id = :r AND type = :ty', ['t' => $ticketId, 'r' => $relatedId, 'ty' => $type]) !== null;
    }

    public function create(int $ticketId, int $relatedId, string $type, ?int $userId): int
    {
        return $this->insertRow('ticket_relations', ['ticket_id' => $ticketId, 'related_ticket_id' => $relatedId, 'type' => $type, 'created_by' => $userId]);
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM ticket_relations WHERE id = :id', ['id' => $id]);
    }

    /** Beim Zusammenführen: Beziehungen des Quelltickets auf das Zielticket umhängen (Selbstbezüge entfallen). */
    public function moveToTicket(int $fromTicketId, int $toTicketId): void
    {
        $this->execute('DELETE FROM ticket_relations WHERE (ticket_id = :from AND related_ticket_id = :to) OR (ticket_id = :to AND related_ticket_id = :from)', ['from' => $fromTicketId, 'to' => $toTicketId]);
        $this->execute('INSERT IGNORE INTO ticket_relations (ticket_id, related_ticket_id, type, created_by, created_at) SELECT :to, related_ticket_id, type, created_by, created_at FROM ticket_relations WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
        $this->execute('INSERT IGNORE INTO ticket_relations (ticket_id, related_ticket_id, type, created_by, created_at) SELECT ticket_id, :to, type, created_by, created_at FROM ticket_relations WHERE related_ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
        $this->execute('DELETE FROM ticket_relations WHERE ticket_id = :from OR related_ticket_id = :from', ['from' => $fromTicketId]);
    }

    /** Wiederkehrende Störungen: Tickets mit den meisten verknüpften Incidents/Duplikaten. @return array<int,array<string,mixed>> */
    public function recurring(int $limit = 20): array
    {
        return $this->fetchAll(
            "SELECT t.id, t.number, t.subject, ty.name AS type_name, COUNT(*) AS related_count
             FROM ticket_relations r
             JOIN tickets t ON t.id = CASE WHEN r.type = 'duplicate_of' THEN r.related_ticket_id ELSE r.ticket_id END
             JOIN ticket_types ty ON ty.id = t.ticket_type_id
             WHERE r.type IN ('duplicate_of','problem_of','parent_of')
             GROUP BY t.id, t.number, t.subject, ty.name
             ORDER BY related_count DESC, t.number
             LIMIT {$limit}"
        );
    }
}
