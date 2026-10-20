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

    /** @return array<int,array<string,mixed>> */
    public function all(bool $activeOnly = false, bool $portalOnly = false): array
    {
        $where = [];
        if ($activeOnly) {
            $where[] = 'tp.is_active = 1';
        }
        if ($portalOnly) {
            $where[] = 'tp.is_portal_visible = 1';
        }

        return $this->fetchAll(self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY tp.sort_order, tp.name');
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE tp.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE tp.name = :n', ['n' => $name]);
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
