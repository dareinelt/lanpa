<?php

declare(strict_types=1);

namespace App\Repositories;

/** Ticketvorlagen für wiederkehrende Anfragen. */
final class TicketTemplateRepository extends BaseRepository
{
    private const SELECT = 'SELECT tp.*, ty.name AS type_name, c.name AS category_name, sc.name AS subcategory_name,
            p.name AS priority_name, g.name AS group_name, u.display_name AS assignee_name, s.name AS sla_name
        FROM ticket_templates tp
        LEFT JOIN ticket_types ty ON ty.id = tp.ticket_type_id
        LEFT JOIN ticket_categories c ON c.id = tp.category_id
        LEFT JOIN ticket_categories sc ON sc.id = tp.subcategory_id
        LEFT JOIN ticket_priorities p ON p.id = tp.priority_id
        LEFT JOIN ticket_groups g ON g.id = tp.group_id
        LEFT JOIN users u ON u.id = tp.assignee_user_id
        LEFT JOIN ticket_slas s ON s.id = tp.sla_id';

    /**
     * @param int|null $viewerId Wenn gesetzt, werden nur Vorlagen geliefert, die dieser Benutzer
     *                           sehen darf: für alle freigegebene sowie seine eigenen persönlichen.
     * @return array<int,array<string,mixed>>
     */
    public function all(bool $activeOnly = false, bool $portalOnly = false, ?int $viewerId = null): array
    {
        $where = [];
        $params = [];
        if ($activeOnly) {
            $where[] = 'tp.is_active = 1';
        }
        if ($portalOnly) {
            // Persönliche Vorlagen (is_shared = 0) erreichen das Portal nur über ihren Ersteller.
            $where[] = $viewerId === null
                ? 'tp.is_portal_visible = 1'
                : '(tp.is_portal_visible = 1 OR (tp.is_shared = 0 AND tp.created_by = :viewer))';
        }
        if ($viewerId !== null) {
            $where[] = '(tp.is_shared = 1 OR tp.created_by = :viewer)';
            $params['viewer'] = $viewerId;
        }

        return $this->fetchAll(
            self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY tp.sort_order, tp.name',
            $params
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE tp.id = :id', ['id' => $id]);
    }

    /**
     * Namenskonflikt im Umfang der Vorlage: Freigegebene Vorlagen tragen einen eindeutigen Namen
     * (Datenbank: uq_ticket_templates_shared_name); zusätzlich doppelt keine Vorlage einen Namen
     * desselben Erstellers. Fremde persönliche Vorlagen zählen nicht – sie bleiben unsichtbar.
     *
     * @return array<string,mixed>|null
     */
    public function findNameConflict(string $name, int $ownerId, ?int $excludeId = null): ?array
    {
        $sql = 'SELECT tp.id, tp.name FROM ticket_templates tp WHERE tp.name = :n AND tp.id <> :exclude AND (tp.is_shared = 1';
        $params = ['n' => $name, 'exclude' => $excludeId ?? 0];
        if ($ownerId > 0) {
            $sql .= ' OR tp.created_by = :owner';
            $params['owner'] = $ownerId;
        }

        return $this->fetchOne($sql . ') LIMIT 1', $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_templates', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('ticket_templates', $id, $data);
    }
}
