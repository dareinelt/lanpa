<?php

declare(strict_types=1);

namespace App\Repositories;

final class AssetRepository extends BaseRepository
{
    private const SELECT = 'SELECT a.*,
            t.name AS asset_type_name, t.code AS asset_type_code, t.icon AS asset_type_icon,
            t.has_serial_number, t.has_mac_address, t.has_imei, t.supports_licenses,
            cat.name AS category_name,
            m.name AS manufacturer_name,
            ar.name AS article_name, ar.article_number,
            sup.name AS supplier_name,
            po.order_number,
            l.full_path AS location_path, l.name AS location_name,
            cc.number AS cost_center_number, cc.description AS cost_center_name,
            e.display_name AS employee_name, e.username AS employee_username, e.department AS employee_department, e.is_active AS employee_active,
            s.code AS status_code, s.name AS status_name, s.color AS status_color, s.is_available AS status_available, s.is_final AS status_final,
            p.inventory_number AS parent_inventory_number,
            (SELECT MAX(mv.movement_date) FROM movements mv WHERE mv.asset_id = a.id AND mv.type = \'checkout\' AND mv.status <> \'cancelled\') AS assigned_at
        FROM assets a
        JOIN asset_types t ON t.id = a.asset_type_id
        JOIN asset_statuses s ON s.id = a.status_id
        LEFT JOIN asset_categories cat ON cat.id = a.asset_category_id
        LEFT JOIN manufacturers m ON m.id = a.manufacturer_id
        LEFT JOIN articles ar ON ar.id = a.article_id
        LEFT JOIN suppliers sup ON sup.id = a.supplier_id
        LEFT JOIN purchase_orders po ON po.id = a.purchase_order_id
        LEFT JOIN locations l ON l.id = a.location_id
        LEFT JOIN cost_centers cc ON cc.id = a.cost_center_id
        LEFT JOIN employees e ON e.id = a.employee_id
        LEFT JOIN assets p ON p.id = a.parent_asset_id';

    /** Sortierbare Spalten der Liste → SQL-Ausdruck */
    private const SORTABLE = [
        'inventory_number' => 'a.inventory_number',
        'name' => 'a.name',
        'type' => 't.sort_order, a.inventory_number',
        'status' => 's.sort_order, a.inventory_number',
        'employee' => 'e.display_name',
        'location' => 'l.full_path',
        'purchase_date' => 'a.purchase_date',
        'warranty_until' => 'a.warranty_until',
        'updated_at' => 'a.updated_at',
    ];

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByInventoryNumber(string $number): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.inventory_number = :n', ['n' => strtoupper(trim($number))]);
    }

    /** @return array<string,mixed>|null */
    public function findBySerial(int $assetTypeId, string $normalizedSerial, ?int $excludeId = null): ?array
    {
        return $this->fetchOne(
            'SELECT id, inventory_number, name FROM assets WHERE asset_type_id = :t AND serial_number_normalized = :s' . ($excludeId ? ' AND id <> :x' : '') . ' LIMIT 1',
            $excludeId ? ['t' => $assetTypeId, 's' => $normalizedSerial, 'x' => $excludeId] : ['t' => $assetTypeId, 's' => $normalizedSerial]
        );
    }

    /** @return array<int,array<string,mixed>> Assets anderer Typen mit gleicher Seriennummer (nur Hinweis). */
    public function findSerialElsewhere(int $assetTypeId, string $normalizedSerial, ?int $excludeId = null): array
    {
        return $this->fetchAll(
            'SELECT a.id, a.inventory_number, a.name, t.name AS asset_type_name FROM assets a JOIN asset_types t ON t.id = a.asset_type_id
             WHERE a.asset_type_id <> :t AND a.serial_number_normalized = :s' . ($excludeId ? ' AND a.id <> :x' : '') . ' LIMIT 5',
            $excludeId ? ['t' => $assetTypeId, 's' => $normalizedSerial, 'x' => $excludeId] : ['t' => $assetTypeId, 's' => $normalizedSerial]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByUniqueField(string $column, string $value, ?int $excludeId = null): ?array
    {
        if (!in_array($column, ['mac_address', 'imei', 'inventory_number'], true)) {
            throw new \InvalidArgumentException('Unbekanntes Feld ' . $column);
        }

        return $this->fetchOne(
            "SELECT id, inventory_number, name FROM assets WHERE {$column} = :v" . ($excludeId ? ' AND id <> :x' : '') . ' LIMIT 1',
            $excludeId ? ['v' => $value, 'x' => $excludeId] : ['v' => $value]
        );
    }

    /**
     * @param array<string,mixed> $filters q, asset_type_id, asset_category_id, status_id, status (code|active|final),
     *                                     manufacturer_id, article_id, supplier_id, location_id (inkl. Unterstandorte via location_ids),
     *                                     cost_center_id, employee_id, purchase_order_id, warranty (expired|expiring|valid), legacy
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0, string $sort = 'inventory_number', string $dir = 'asc'): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $order = self::SORTABLE[$sort] ?? self::SORTABLE['inventory_number'];
        $direction = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        $order = implode(', ', array_map(static fn (string $col): string => trim($col) . ' ' . $direction, explode(',', $order)));

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY {$order}, a.id {$direction} LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue(
            "SELECT COUNT(*) FROM assets a
             JOIN asset_types t ON t.id = a.asset_type_id
             JOIN asset_statuses s ON s.id = a.status_id
             LEFT JOIN manufacturers m ON m.id = a.manufacturer_id
             LEFT JOIN articles ar ON ar.id = a.article_id
             LEFT JOIN locations l ON l.id = a.location_id
             LEFT JOIN cost_centers cc ON cc.id = a.cost_center_id
             LEFT JOIN employees e ON e.id = a.employee_id
             {$where}",
            $params
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forEmployee(int $employeeId, int $limit = 100): array
    {
        return $this->search(['employee_id' => $employeeId], $limit, 0, 'type');
    }

    /** @return array<int,array<string,mixed>> */
    public function forLocation(int $locationId, int $limit = 100): array
    {
        return $this->search(['location_id' => $locationId], $limit, 0, 'type');
    }

    /** @return array<int,array<string,mixed>> Kinder eines Assets (z. B. Zubehör am Arbeitsplatz-PC) */
    public function children(int $parentId): array
    {
        return $this->search(['parent_asset_id' => $parentId], 100, 0, 'type');
    }

    /**
     * Kompakter Bestand für den Offline-Cache der mobilen Erfassung (nur nicht ausgeschiedene Assets).
     * @return array<int,array<string,mixed>>
     */
    public function forOffline(int $limit = 20000): array
    {
        return $this->fetchAll(
            'SELECT a.id, a.inventory_number, a.serial_number, a.name, a.version, a.employee_id, a.location_id, a.cost_center_id,
                    t.name AS type, t.code AS type_code, m.name AS manufacturer,
                    s.code AS status_code, s.name AS status_name, s.color AS status_color, s.is_final AS status_final,
                    e.display_name AS employee_name, l.full_path AS location_path
             FROM assets a
             JOIN asset_types t ON t.id = a.asset_type_id
             JOIN asset_statuses s ON s.id = a.status_id
             LEFT JOIN manufacturers m ON m.id = a.manufacturer_id
             LEFT JOIN employees e ON e.id = a.employee_id
             LEFT JOIN locations l ON l.id = a.location_id
             WHERE s.is_final = 0
             ORDER BY a.inventory_number
             LIMIT ' . max(1, $limit)
        );
    }

    /** Schnellsuche für Tipp-Vorschläge / globale Suche. @return array<int,array<string,mixed>> */
    public function quickSearch(string $term, int $limit = 10): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term) ?? '');

        return $this->fetchAll(
            self::SELECT . ' WHERE a.inventory_number LIKE :like OR a.serial_number_normalized LIKE :nlike OR a.name LIKE :like
                OR a.mac_address LIKE :like OR a.imei LIKE :like OR m.name LIKE :like OR ar.name LIKE :like OR e.display_name LIKE :like
             ORDER BY (a.inventory_number = :exact) DESC, (a.serial_number_normalized = :nexact) DESC, a.inventory_number
             LIMIT ' . $limit,
            ['like' => $this->like($term), 'nlike' => $this->like($normalized), 'exact' => strtoupper($term), 'nexact' => $normalized]
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('assets', $data);
    }

    /**
     * Aktualisiert mit optimistischer Sperre: Nur wenn die Version noch stimmt.
     * @param array<string,mixed> $data
     * @return bool false, wenn der Datensatz zwischenzeitlich geändert wurde
     */
    public function updateVersioned(int $id, int $expectedVersion, array $data): bool
    {
        if ($data === []) {
            return true;
        }
        $data['version'] = $expectedVersion + 1;
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($data)));

        return $this->execute("UPDATE assets SET {$set} WHERE id = ? AND version = ?", [...array_values($data), $id, $expectedVersion]) === 1;
    }

    /** @param array<string,mixed> $data Aktualisierung ohne Versionsprüfung (Systemprozesse). */
    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($data)));
        $this->execute("UPDATE assets SET {$set}, version = version + 1 WHERE id = ?", [...array_values($data), $id]);
    }

    /** @return array<string,int> Status-Code → Anzahl (für Filterleiste/Dashboard) */
    public function countsByStatus(): array
    {
        $out = [];
        foreach ($this->fetchAll('SELECT s.code, COUNT(a.id) AS n FROM asset_statuses s LEFT JOIN assets a ON a.status_id = s.id GROUP BY s.id, s.code') as $row) {
            $out[(string) $row['code']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $term = (string) $filters['q'];
            $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $term) ?? '');
            $clauses[] = '(a.inventory_number LIKE :q OR a.name LIKE :q OR a.serial_number_normalized LIKE :qn OR a.mac_address LIKE :q OR a.imei LIKE :q
                           OR m.name LIKE :q OR ar.name LIKE :q OR e.display_name LIKE :q OR l.full_path LIKE :q OR cc.number LIKE :q OR a.note LIKE :q)';
            $params['q'] = $this->like($term);
            $params['qn'] = $this->like($normalized !== '' ? $normalized : $term);
        }
        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['ids']), static fn (int $i): bool => $i > 0));
            $clauses[] = $ids === [] ? '1 = 0' : 'a.id IN (' . implode(',', $ids) . ')';
        }
        foreach (['asset_type_id', 'asset_category_id', 'status_id', 'manufacturer_id', 'article_id', 'supplier_id', 'cost_center_id', 'employee_id', 'purchase_order_id', 'parent_asset_id'] as $col) {
            if (!empty($filters[$col])) {
                $clauses[] = "a.{$col} = :{$col}";
                $params[$col] = (int) $filters[$col];
            }
        }
        if (!empty($filters['location_ids']) && is_array($filters['location_ids'])) {
            $ids = array_values(array_map('intval', $filters['location_ids']));
            $clauses[] = 'a.location_id IN (' . implode(',', $ids) . ')';
        } elseif (!empty($filters['location_id'])) {
            $clauses[] = 'a.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            switch ($filters['status']) {
                case 'active':
                    $clauses[] = 's.is_final = 0';
                    break;
                case 'final':
                    $clauses[] = 's.is_final = 1';
                    break;
                case 'unassigned':
                    $clauses[] = 'a.employee_id IS NULL AND s.is_final = 0';
                    break;
                default:
                    $clauses[] = 's.code = :status_code';
                    $params['status_code'] = (string) $filters['status'];
            }
        }
        if (!empty($filters['warranty'])) {
            switch ($filters['warranty']) {
                case 'expired':
                    $clauses[] = 'a.warranty_until IS NOT NULL AND a.warranty_until < CURDATE()';
                    break;
                case 'expiring':
                    $clauses[] = 'a.warranty_until IS NOT NULL AND a.warranty_until >= CURDATE() AND a.warranty_until < DATE_ADD(CURDATE(), INTERVAL 90 DAY)';
                    break;
                case 'valid':
                    $clauses[] = 'a.warranty_until IS NOT NULL AND a.warranty_until >= CURDATE()';
                    break;
                case 'none':
                    $clauses[] = 'a.warranty_until IS NULL';
                    break;
            }
        }
        if (!empty($filters['missing'])) {
            $missingColumns = ['location' => 'a.location_id', 'cost_center' => 'a.cost_center_id', 'serial' => 'a.serial_number', 'purchase_date' => 'a.purchase_date', 'purchase_price' => 'a.purchase_price'];
            if (isset($missingColumns[$filters['missing']])) {
                $clauses[] = $missingColumns[$filters['missing']] . ' IS NULL';
            }
        }
        if (isset($filters['legacy']) && $filters['legacy'] !== '') {
            $clauses[] = 'a.is_legacy = :legacy';
            $params['legacy'] = (int) $filters['legacy'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
