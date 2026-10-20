<?php

declare(strict_types=1);

namespace App\Repositories;

final class SupplierRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT s.*, (SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = s.id) AS order_count
             FROM suppliers s WHERE s.id = :id',
            ['id' => $id]
        );
    }

    /**
     * @param array<string,mixed> $filters q, active
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(
            "SELECT s.*, (SELECT COUNT(*) FROM purchase_orders o WHERE o.supplier_id = s.id) AS order_count
             FROM suppliers s {$where} ORDER BY s.name LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM suppliers s {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForSelect(): array
    {
        return $this->fetchAll('SELECT id, name, customer_number FROM suppliers WHERE is_active = 1 ORDER BY name');
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name, ?int $excludeId = null): ?array
    {
        $params = ['n' => $name];
        $sql = 'SELECT id, name FROM suppliers WHERE LOWER(name) = LOWER(:n)';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('suppliers', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('suppliers', $id, $data);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(s.name LIKE :q OR s.city LIKE :q OR s.contact_person LIKE :q OR s.customer_number LIKE :q OR s.email LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $clauses[] = 's.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
