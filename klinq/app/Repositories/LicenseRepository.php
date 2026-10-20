<?php

declare(strict_types=1);

namespace App\Repositories;

/** Lizenzen und ihre Zuordnungen zu Assets. */
final class LicenseRepository extends BaseRepository
{
    /** Tage bis zum Ablauf, ab denen eine Lizenz als „läuft ab“ gilt. */
    public const EXPIRING_DAYS = 60;

    private const SELECT = 'SELECT l.*, m.name AS manufacturer_name, s.name AS supplier_name,
            cc.number AS cost_center_number, cc.description AS cost_center_name,
            o.order_number,
            (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.released_at IS NULL) AS used_count,
            (l.quantity - (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.released_at IS NULL)) AS available_count,
            (SELECT COUNT(*) FROM documents d WHERE d.entity_type = \'license\' AND d.entity_id = l.id) AS document_count,
            CASE
                WHEN l.expires_at IS NULL THEN \'perpetual\'
                WHEN l.expires_at < CURDATE() THEN \'expired\'
                WHEN l.expires_at <= DATE_ADD(CURDATE(), INTERVAL ' . self::EXPIRING_DAYS . ' DAY) THEN \'expiring\'
                ELSE \'valid\'
            END AS expiry_status,
            DATEDIFF(l.expires_at, CURDATE()) AS days_left
        FROM licenses l
        LEFT JOIN manufacturers m ON m.id = l.manufacturer_id
        LEFT JOIN suppliers s ON s.id = l.supplier_id
        LEFT JOIN cost_centers cc ON cc.id = l.cost_center_id
        LEFT JOIN purchase_orders o ON o.id = l.purchase_order_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE l.id = :id', ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $filters q, manufacturer_id, supplier_id, status (all|available|used|expiring|expired|inactive), expiring (Tage)
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->whereFor($filters);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY (l.expires_at IS NULL), l.expires_at, m.name, l.product LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->whereFor($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM (" . self::SELECT . " {$where}) x", $params);
    }

    /** Zähler für die Statusreiter. @return array<string,int> */
    public function statusCounts(): array
    {
        $row = $this->fetchOne(
            'SELECT
                SUM(x.is_active = 1) AS all_active,
                SUM(x.is_active = 1 AND x.available_count > 0 AND x.expiry_status <> \'expired\') AS available,
                SUM(x.is_active = 1 AND x.used_count > 0) AS used,
                SUM(x.is_active = 1 AND x.expiry_status = \'expiring\') AS expiring,
                SUM(x.expiry_status = \'expired\') AS expired,
                SUM(x.is_active = 0) AS inactive
             FROM (' . self::SELECT . ') x'
        ) ?? [];

        return array_map('intval', array_map(static fn ($v) => $v ?? 0, $row));
    }

    /** Summen für die Kopfkarten. @return array{licenses:int, seats:int, used:int} */
    public function totals(): array
    {
        $row = $this->fetchOne('SELECT COUNT(*) AS licenses, COALESCE(SUM(x.quantity), 0) AS seats, COALESCE(SUM(x.used_count), 0) AS used FROM (' . self::SELECT . ') x WHERE x.is_active = 1') ?? [];

        return ['licenses' => (int) ($row['licenses'] ?? 0), 'seats' => (int) ($row['seats'] ?? 0), 'used' => (int) ($row['used'] ?? 0)];
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('licenses', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('licenses', $id, $data);
    }

    // ------------------------------------------------------------------ Zuordnungen

    /** Alle Zuordnungen einer Lizenz (aktive zuerst). @return array<int,array<string,mixed>> */
    public function assignments(int $licenseId, bool $activeOnly = false): array
    {
        $sql = 'SELECT la.*, a.inventory_number, a.name AS asset_name, a.serial_number, ar.name AS article_name,
                       t.name AS asset_type_name, t.icon AS asset_type_icon, st.name AS status_name, st.color AS status_color,
                       e.display_name AS employee_name, loc.full_path AS location_path
                FROM license_assignments la
                JOIN assets a ON a.id = la.asset_id
                JOIN asset_types t ON t.id = a.asset_type_id
                JOIN asset_statuses st ON st.id = a.status_id
                LEFT JOIN articles ar ON ar.id = a.article_id
                LEFT JOIN employees e ON e.id = a.employee_id
                LEFT JOIN locations loc ON loc.id = a.location_id
                WHERE la.license_id = :lid' . ($activeOnly ? ' AND la.released_at IS NULL' : '') . '
                ORDER BY (la.released_at IS NULL) DESC, la.assigned_at DESC, la.id DESC';

        return $this->fetchAll($sql, ['lid' => $licenseId]);
    }

    /** @return array<string,mixed>|null */
    public function findAssignment(int $id): ?array
    {
        return $this->fetchOne('SELECT la.*, a.inventory_number FROM license_assignments la JOIN assets a ON a.id = la.asset_id WHERE la.id = :id', ['id' => $id]);
    }

    /** Aktive Zuordnung einer Lizenz zu einem Asset. @return array<string,mixed>|null */
    public function activeAssignment(int $licenseId, int $assetId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM license_assignments WHERE license_id = :lid AND asset_id = :aid AND released_at IS NULL LIMIT 1',
            ['lid' => $licenseId, 'aid' => $assetId]
        );
    }

    /** Aktiv zugeordnete Lizenzen eines Assets. @return array<int,array<string,mixed>> */
    public function forAsset(int $assetId): array
    {
        return $this->fetchAll(
            'SELECT la.id AS assignment_id, la.assigned_at, la.assigned_by, la.note AS assignment_note, x.*
             FROM license_assignments la
             JOIN (' . self::SELECT . ') x ON x.id = la.license_id
             WHERE la.asset_id = :aid AND la.released_at IS NULL
             ORDER BY x.manufacturer_name, x.product',
            ['aid' => $assetId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createAssignment(array $data): int
    {
        return $this->insertRow('license_assignments', $data);
    }

    public function releaseAssignment(int $id, string $releasedBy, string $releasedAtUtc): void
    {
        $this->execute(
            'UPDATE license_assignments SET released_at = :at, released_by = :by WHERE id = :id AND released_at IS NULL',
            ['at' => $releasedAtUtc, 'by' => $releasedBy, 'id' => $id]
        );
    }

    /** Lizenzen, die innerhalb von $days Tagen ablaufen (Dashboard). @return array<int,array<string,mixed>> */
    public function expiringWithin(int $days, int $limit = 10): array
    {
        return $this->fetchAll(
            self::SELECT . ' WHERE l.is_active = 1 AND l.expires_at IS NOT NULL AND l.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
             ORDER BY l.expires_at LIMIT ' . $limit,
            ['days' => $days]
        );
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function whereFor(array $filters): array
    {
        $conds = [];
        $params = [];
        $status = (string) ($filters['status'] ?? 'all');
        switch ($status) {
            case 'available':
                $conds[] = 'l.is_active = 1 AND (l.quantity - (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.released_at IS NULL)) > 0 AND (l.expires_at IS NULL OR l.expires_at >= CURDATE())';
                break;
            case 'used':
                $conds[] = 'l.is_active = 1 AND (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.released_at IS NULL) > 0';
                break;
            case 'expiring':
                $conds[] = 'l.is_active = 1 AND l.expires_at IS NOT NULL AND l.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . self::EXPIRING_DAYS . ' DAY)';
                break;
            case 'expired':
                $conds[] = 'l.expires_at IS NOT NULL AND l.expires_at < CURDATE()';
                break;
            case 'inactive':
                $conds[] = 'l.is_active = 0';
                break;
            case 'all':
                break;
            default:
                $conds[] = 'l.is_active = 1';
        }
        if (!empty($filters['expiring'])) {
            $conds[] = 'l.is_active = 1 AND l.expires_at IS NOT NULL AND l.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :exp_days DAY)';
            $params['exp_days'] = max(1, (int) $filters['expiring']);
        }
        if (!empty($filters['q'])) {
            $conds[] = '(l.product LIKE :q OR l.license_type LIKE :q OR l.license_number LIKE :q OR l.license_key LIKE :q OR m.name LIKE :q OR l.note LIKE :q)';
            $params['q'] = $this->like(trim((string) $filters['q']));
        }
        foreach (['manufacturer_id' => 'l.manufacturer_id', 'supplier_id' => 'l.supplier_id', 'cost_center_id' => 'l.cost_center_id', 'purchase_order_id' => 'l.purchase_order_id'] as $key => $col) {
            if (!empty($filters[$key])) {
                $conds[] = "{$col} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        if (!empty($filters['asset_id'])) {
            $conds[] = 'EXISTS (SELECT 1 FROM license_assignments la WHERE la.license_id = l.id AND la.asset_id = :asset_id AND la.released_at IS NULL)';
            $params['asset_id'] = (int) $filters['asset_id'];
        }

        return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $params];
    }
}
