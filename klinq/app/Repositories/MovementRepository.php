<?php

declare(strict_types=1);

namespace App\Repositories;

/** Bewegungen: Entnahmen und Retouren (auch offene/unvollständige). */
final class MovementRepository extends BaseRepository
{
    private const SELECT = 'SELECT m.*,
            a.inventory_number, a.name AS asset_name, a.serial_number, a.status_id AS asset_status_id, a.employee_id AS asset_employee_id,
            a.location_id AS asset_location_id, a.cost_center_id AS asset_cost_center_id, a.version AS asset_version,
            t.name AS asset_type_name, t.code AS asset_type_code, t.icon AS asset_type_icon,
            mf.name AS manufacturer_name, ar.name AS article_name,
            s.code AS asset_status_code, s.name AS asset_status_name, s.color AS asset_status_color,
            e.display_name AS employee_name, e.department AS employee_department, e.username AS employee_username, e.email AS employee_email,
            lf.full_path AS from_location_path, lt.full_path AS to_location_path,
            cc.number AS cost_center_number, cc.description AS cost_center_name,
            u.display_name AS completed_by_name,
            (SELECT COUNT(*) FROM documents d WHERE d.entity_type = \'movement\' AND d.entity_id = m.id) AS document_count
        FROM movements m
        JOIN assets a ON a.id = m.asset_id
        JOIN asset_types t ON t.id = a.asset_type_id
        JOIN asset_statuses s ON s.id = a.status_id
        LEFT JOIN manufacturers mf ON mf.id = a.manufacturer_id
        LEFT JOIN articles ar ON ar.id = a.article_id
        LEFT JOIN employees e ON e.id = m.employee_id
        LEFT JOIN locations lf ON lf.id = m.from_location_id
        LEFT JOIN locations lt ON lt.id = m.to_location_id
        LEFT JOIN cost_centers cc ON cc.id = m.cost_center_id
        LEFT JOIN users u ON u.id = m.completed_by';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE m.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByClientTransaction(string $clientTransactionId): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE m.client_transaction_id = :tx', ['tx' => $clientTransactionId]);
    }

    /** Offener Vorgang (Entnahme oder Retoure) zu einem Asset. @return array<string,mixed>|null */
    public function openForAsset(int $assetId, ?string $type = null): ?array
    {
        $sql = self::SELECT . " WHERE m.asset_id = :id AND m.status = 'open'";
        $params = ['id' => $assetId];
        if ($type !== null) {
            $sql .= ' AND m.type = :type';
            $params['type'] = $type;
        }

        return $this->fetchOne($sql . ' ORDER BY m.id DESC LIMIT 1', $params);
    }

    /** Letzte nicht stornierte Entnahme eines Assets. @return array<string,mixed>|null */
    public function lastCheckoutForAsset(int $assetId): ?array
    {
        return $this->fetchOne(
            self::SELECT . " WHERE m.asset_id = :id AND m.type = 'checkout' AND m.status <> 'cancelled' ORDER BY m.movement_at DESC, m.id DESC LIMIT 1",
            ['id' => $assetId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forAsset(int $assetId, int $limit = 50): array
    {
        return $this->fetchAll(self::SELECT . " WHERE m.asset_id = :id ORDER BY m.movement_at DESC, m.id DESC LIMIT {$limit}", ['id' => $assetId]);
    }

    /**
     * @param array<string,mixed> $filters type, status, date_from, date_to, employee_id, location_id, cost_center_id, asset_type_id, q, source, missing
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY m.movement_at DESC, m.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue(
            "SELECT COUNT(*) FROM movements m
             JOIN assets a ON a.id = m.asset_id
             JOIN asset_types t ON t.id = a.asset_type_id
             LEFT JOIN employees e ON e.id = m.employee_id
             LEFT JOIN locations lt ON lt.id = m.to_location_id
             LEFT JOIN cost_centers cc ON cc.id = m.cost_center_id
             {$where}",
            $params
        );
    }

    /**
     * Kennzahlen für die Tagesübersicht.
     * @return array{checkouts:int,returns:int,open_checkouts:int,open_returns:int}
     */
    public function summary(string $from, string $to): array
    {
        $row = $this->fetchOne(
            "SELECT
                SUM(m.type = 'checkout' AND m.status <> 'cancelled' AND m.movement_date BETWEEN :from AND :to) AS checkouts,
                SUM(m.type = 'return' AND m.status <> 'cancelled' AND m.movement_date BETWEEN :from AND :to) AS returns_,
                SUM(m.type = 'checkout' AND m.status = 'open') AS open_checkouts,
                SUM(m.type = 'return' AND m.status = 'open') AS open_returns
             FROM movements m",
            ['from' => $from, 'to' => $to]
        ) ?? [];

        return [
            'checkouts' => (int) ($row['checkouts'] ?? 0),
            'returns' => (int) ($row['returns_'] ?? 0),
            'open_checkouts' => (int) ($row['open_checkouts'] ?? 0),
            'open_returns' => (int) ($row['open_returns'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('movements', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('movements', $id, $data);
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['type']) && in_array($filters['type'], ['checkout', 'return'], true)) {
            $clauses[] = 'm.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['open', 'completed', 'cancelled'], true)) {
            $clauses[] = 'm.status = :status';
            $params['status'] = $filters['status'];
        } elseif (empty($filters['status']) || $filters['status'] !== 'all') {
            $clauses[] = "m.status <> 'cancelled'";
        }
        if (!empty($filters['date_from'])) {
            $clauses[] = 'm.movement_date >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $clauses[] = 'm.movement_date <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }
        foreach (['employee_id' => 'm.employee_id', 'cost_center_id' => 'm.cost_center_id', 'asset_type_id' => 'a.asset_type_id', 'asset_id' => 'm.asset_id'] as $key => $col) {
            if (!empty($filters[$key])) {
                $clauses[] = "{$col} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        if (!empty($filters['location_id'])) {
            $clauses[] = '(m.to_location_id = :location_id OR m.from_location_id = :location_id)';
            $params['location_id'] = (int) $filters['location_id'];
        }
        if (!empty($filters['source']) && in_array($filters['source'], ['web', 'mobile', 'offline_sync', 'import'], true)) {
            $clauses[] = 'm.source = :source';
            $params['source'] = $filters['source'];
        }
        if (!empty($filters['missing']) && in_array($filters['missing'], ['location', 'cost_center', 'employee', 'condition'], true)) {
            $clauses[] = 'JSON_CONTAINS(m.missing_fields, :missing)';
            $params['missing'] = json_encode($filters['missing']);
        }
        if (!empty($filters['q'])) {
            $clauses[] = '(a.inventory_number LIKE :q OR a.name LIKE :q OR a.serial_number LIKE :q OR e.display_name LIKE :q OR lt.full_path LIKE :q OR cc.number LIKE :q)';
            $params['q'] = $this->like(trim((string) $filters['q']));
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
