<?php

declare(strict_types=1);

namespace App\Repositories;

final class LocationRepository extends BaseRepository
{
    public const TYPES = [
        'site' => 'Standort',
        'building' => 'Gebäude',
        'floor' => 'Etage',
        'room' => 'Raum',
        'workplace' => 'Arbeitsplatz',
        'warehouse' => 'Lager',
        'other' => 'Sonstiges',
    ];

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT l.*, p.name AS parent_name,
                    (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id) AS asset_count,
                    (SELECT COUNT(*) FROM locations c WHERE c.parent_id = l.id) AS child_count,
                    (SELECT COUNT(*) FROM employees e WHERE e.location_id = l.id AND e.is_active = 1) AS employee_count
             FROM locations l LEFT JOIN locations p ON p.id = l.parent_id WHERE l.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<int,array<string,mixed>> Alle Standorte, sortiert nach Pfad */
    public function all(bool $onlyActive = false): array
    {
        return $this->fetchAll(
            'SELECT l.*, (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id) AS asset_count
             FROM locations l ' . ($onlyActive ? 'WHERE l.is_active = 1 ' : '') . 'ORDER BY l.full_path'
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function children(?int $parentId): array
    {
        if ($parentId === null) {
            return $this->fetchAll('SELECT * FROM locations WHERE parent_id IS NULL ORDER BY sort_order, name');
        }

        return $this->fetchAll('SELECT * FROM locations WHERE parent_id = :p ORDER BY sort_order, name', ['p' => $parentId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function search(string $term, int $limit = 20, bool $onlyActive = true): array
    {
        return $this->fetchAll(
            'SELECT id, name, type, full_path, code FROM locations WHERE (name LIKE :q OR full_path LIKE :q OR code LIKE :q)'
            . ($onlyActive ? ' AND is_active = 1' : '') . " ORDER BY full_path LIMIT {$limit}",
            ['q' => $this->like($term)]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByPath(string $fullPath): ?array
    {
        return $this->fetchOne('SELECT * FROM locations WHERE full_path = :p LIMIT 1', ['p' => $fullPath]);
    }

    /** Liefert den Standort nur, wenn Name oder Kürzel eindeutig passen. @return array<string,mixed>|null */
    public function findUniqueByNameOrCode(string $term): ?array
    {
        $rows = $this->fetchAll('SELECT * FROM locations WHERE is_active = 1 AND (name = :t OR code = :t) LIMIT 2', ['t' => $term]);

        return count($rows) === 1 ? $rows[0] : null;
    }

    /** @return array<string,mixed>|null */
    public function findSibling(?int $parentId, string $name, ?int $excludeId = null): ?array
    {
        $params = ['n' => $name];
        $sql = 'SELECT id, name FROM locations WHERE LOWER(name) = LOWER(:n) AND parent_id ' . ($parentId === null ? 'IS NULL' : '= :p');
        if ($parentId !== null) {
            $params['p'] = $parentId;
        }
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    /** @return array<int,int> IDs aller Nachfahren (rekursiv) */
    public function descendantIds(int $id): array
    {
        $rows = $this->fetchAll(
            'WITH RECURSIVE tree AS (
                SELECT id FROM locations WHERE parent_id = :id
                UNION ALL
                SELECT l.id FROM locations l JOIN tree t ON l.parent_id = t.id
             ) SELECT id FROM tree',
            ['id' => $id]
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('locations', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('locations', $id, $data);
    }

    public function updatePath(int $id, string $fullPath, int $depth): void
    {
        $this->execute('UPDATE locations SET full_path = ?, depth = ? WHERE id = ?', [$fullPath, $depth, $id]);
    }
}
