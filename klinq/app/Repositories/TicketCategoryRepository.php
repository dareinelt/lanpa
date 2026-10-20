<?php

declare(strict_types=1);

namespace App\Repositories;

/** Hierarchische Ticketkategorien (Hauptkategorie → Unterkategorie). */
final class TicketCategoryRepository extends BaseRepository
{
    private const SELECT = 'SELECT c.*, p.name AS parent_name, g.name AS default_group_name, pr.name AS default_priority_name,
            (SELECT COUNT(*) FROM ticket_categories ch WHERE ch.parent_id = c.id) AS child_count,
            (SELECT COUNT(*) FROM tickets t WHERE t.category_id = c.id OR t.subcategory_id = c.id) AS ticket_count
        FROM ticket_categories c
        LEFT JOIN ticket_categories p ON p.id = c.parent_id
        LEFT JOIN ticket_groups g ON g.id = c.default_group_id
        LEFT JOIN ticket_priorities pr ON pr.id = c.default_priority_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    /** Alle Kategorien flach, sortiert nach Hauptkategorie und Unterkategorie. @return array<int,array<string,mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return $this->fetchAll(self::SELECT . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY COALESCE(p.sort_order, c.sort_order), COALESCE(p.name, c.name), (c.parent_id IS NOT NULL), c.sort_order, c.name');
    }

    /** Hauptkategorien. @return array<int,array<string,mixed>> */
    public function roots(bool $activeOnly = true): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE c.parent_id IS NULL' . ($activeOnly ? ' AND c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    /** Unterkategorien einer Hauptkategorie. @return array<int,array<string,mixed>> */
    public function children(int $parentId, bool $activeOnly = true): array
    {
        return $this->fetchAll(self::SELECT . ' WHERE c.parent_id = :p' . ($activeOnly ? ' AND c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name', ['p' => $parentId]);
    }

    /**
     * Baum: Hauptkategorien mit eingebetteten Unterkategorien (Schlüssel „children“).
     * @return array<int,array<string,mixed>>
     */
    public function tree(bool $activeOnly = true): array
    {
        $rows = $this->all($activeOnly);
        $roots = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] === null) {
                $row['children'] = [];
                $roots[(int) $row['id']] = $row;
            }
        }
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null && isset($roots[(int) $row['parent_id']])) {
                $roots[(int) $row['parent_id']]['children'][] = $row;
            }
        }

        return array_values($roots);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name, ?int $parentId): ?array
    {
        if ($parentId === null) {
            return $this->fetchOne(self::SELECT . ' WHERE c.parent_id IS NULL AND c.name = :n', ['n' => $name]);
        }

        return $this->fetchOne(self::SELECT . ' WHERE c.parent_id = :p AND c.name = :n', ['p' => $parentId, 'n' => $name]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_categories', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('ticket_categories', $id, $data);
    }
}
