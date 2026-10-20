<?php

declare(strict_types=1);

namespace App\Repositories;

final class CostCenterRepository extends BaseRepository
{
    private const SELECT = 'SELECT c.*, l.full_path AS location_path,
                                   (SELECT COUNT(*) FROM assets a WHERE a.cost_center_id = c.id) AS asset_count,
                                   (SELECT COUNT(*) FROM employees e WHERE e.cost_center_id = c.id AND e.is_active = 1) AS employee_count
                            FROM cost_centers c LEFT JOIN locations l ON l.id = c.location_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByNumber(string $number): ?array
    {
        return $this->fetchOne('SELECT * FROM cost_centers WHERE number = :n', ['n' => $number]);
    }

    /**
     * @param array<string,mixed> $filters q, active, location_id
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY c.number LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM cost_centers c {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForSelect(): array
    {
        return $this->fetchAll('SELECT id, number, description, location_id FROM cost_centers WHERE is_active = 1 ORDER BY number');
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('cost_centers', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('cost_centers', $id, $data);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(c.number LIKE :q OR c.description LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $clauses[] = 'c.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }
        if (!empty($filters['location_id'])) {
            $clauses[] = 'c.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
