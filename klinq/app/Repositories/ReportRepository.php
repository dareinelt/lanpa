<?php

declare(strict_types=1);

namespace App\Repositories;

/** Aggregierte Auswertungen für Berichte und Dashboard (reine Lesezugriffe). */
final class ReportRepository extends BaseRepository
{
    /** Bestand je Status (alle Status, auch mit 0). @return array<int,array<string,mixed>> */
    public function stockByStatus(): array
    {
        return $this->fetchAll(
            'SELECT s.code, s.name, s.color, s.is_final, COUNT(a.id) AS total
             FROM asset_statuses s
             LEFT JOIN assets a ON a.status_id = s.id
             WHERE s.is_active = 1
             GROUP BY s.id ORDER BY s.sort_order'
        );
    }

    /** Bestand je Assettyp mit Aufteilung nach Statusgruppen. @return array<int,array<string,mixed>> */
    public function stockByType(): array
    {
        return $this->fetchAll(
            "SELECT t.id, t.code, t.name, t.icon,
                    COUNT(a.id) AS total,
                    SUM(s.code = 'in_stock') AS in_stock,
                    SUM(s.code IN ('issued','return_expected')) AS issued,
                    SUM(s.code IN ('defective','repair')) AS defective,
                    SUM(s.is_final = 1) AS final_count,
                    COALESCE(SUM(CASE WHEN s.is_final = 0 THEN a.purchase_price END), 0) AS purchase_value
             FROM asset_types t
             LEFT JOIN assets a ON a.asset_type_id = t.id
             LEFT JOIN asset_statuses s ON s.id = a.status_id
             WHERE t.is_active = 1
             GROUP BY t.id ORDER BY t.sort_order, t.name"
        );
    }

    /**
     * Bestand je Standort (direkt zugeordnete, nicht endgültige Assets).
     * Standorte ohne Assets werden ausgelassen.
     * @return array<int,array<string,mixed>>
     */
    public function stockByLocation(): array
    {
        return $this->fetchAll(
            "SELECT l.id, l.name, l.full_path, l.depth,
                    COUNT(a.id) AS total,
                    SUM(s.code = 'in_stock') AS in_stock,
                    SUM(s.code IN ('issued','return_expected')) AS issued,
                    SUM(s.code IN ('defective','repair')) AS defective
             FROM locations l
             JOIN assets a ON a.location_id = l.id
             JOIN asset_statuses s ON s.id = a.status_id AND s.is_final = 0
             GROUP BY l.id ORDER BY l.full_path"
        );
    }

    /** Bestand je Kostenstelle (nicht endgültige Assets). @return array<int,array<string,mixed>> */
    public function stockByCostCenter(): array
    {
        return $this->fetchAll(
            "SELECT cc.id, cc.number, cc.description,
                    COUNT(a.id) AS total,
                    SUM(s.code IN ('issued','return_expected')) AS issued,
                    COALESCE(SUM(a.purchase_price), 0) AS purchase_value
             FROM cost_centers cc
             JOIN assets a ON a.cost_center_id = cc.id
             JOIN asset_statuses s ON s.id = a.status_id AND s.is_final = 0
             GROUP BY cc.id ORDER BY cc.number"
        );
    }

    /** Assets ohne Standort bzw. ohne Kostenstelle (nicht endgültig). @return array<string,int> */
    public function unassignedCounts(): array
    {
        return [
            'without_location' => (int) $this->fetchValue('SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.is_final = 0 AND a.location_id IS NULL'),
            'without_cost_center' => (int) $this->fetchValue('SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.is_final = 0 AND a.cost_center_id IS NULL'),
            'active_total' => (int) $this->fetchValue('SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.is_final = 0'),
            'purchase_value' => (int) $this->fetchValue('SELECT COALESCE(SUM(a.purchase_price), 0) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.is_final = 0'),
        ];
    }

    /** Bewegungen je Monat im Zeitraum (ohne stornierte). @return array<int,array<string,mixed>> */
    public function movementsByMonth(string $from, string $to): array
    {
        return $this->fetchAll(
            "SELECT DATE_FORMAT(m.movement_date, '%Y-%m') AS month,
                    SUM(m.type = 'checkout') AS checkouts,
                    SUM(m.type = 'return') AS returns_,
                    SUM(m.type = 'return' AND m.has_damage = 1) AS damaged
             FROM movements m
             WHERE m.status <> 'cancelled' AND m.movement_date BETWEEN :from AND :to
             GROUP BY month ORDER BY month",
            ['from' => $from, 'to' => $to]
        );
    }

    /** Rückgaben je Zustand im Zeitraum. @return array<string,int> */
    public function returnConditions(string $from, string $to): array
    {
        $out = [];
        $rows = $this->fetchAll(
            "SELECT COALESCE(m.condition_code, 'none') AS code, COUNT(*) AS c
             FROM movements m
             WHERE m.type = 'return' AND m.status <> 'cancelled' AND m.movement_date BETWEEN :from AND :to
             GROUP BY code",
            ['from' => $from, 'to' => $to]
        );
        foreach ($rows as $r) {
            $out[(string) $r['code']] = (int) $r['c'];
        }

        return $out;
    }

    /** Entnahmen je Mitarbeiter (Top-N) im Zeitraum. @return array<int,array<string,mixed>> */
    public function checkoutsByEmployee(string $from, string $to, int $limit = 10): array
    {
        return $this->fetchAll(
            "SELECT e.id, e.display_name, e.department, COUNT(*) AS total
             FROM movements m JOIN employees e ON e.id = m.employee_id
             WHERE m.type = 'checkout' AND m.status <> 'cancelled' AND m.movement_date BETWEEN :from AND :to
             GROUP BY e.id ORDER BY total DESC, e.display_name LIMIT " . (int) $limit,
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Offene Bestellungen mit erwartetem Liefertermin bis in N Tagen (inkl. überfälliger).
     * @return array<int,array<string,mixed>>
     */
    public function expectedDeliveries(int $days = 14, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT o.id, o.order_number, o.expected_delivery_date, o.status, s.name AS supplier_name,
                    (SELECT COALESCE(SUM(i.quantity - i.quantity_received), 0) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS open_quantity,
                    (o.expected_delivery_date < CURDATE()) AS is_overdue
             FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.status IN ('ordered','partially_delivered')
               AND o.expected_delivery_date IS NOT NULL
               AND o.expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
             ORDER BY o.expected_delivery_date LIMIT " . (int) $limit,
            ['days' => $days]
        );
    }

    /** Anzahl erwarteter Lieferungen (bis in N Tagen inkl. überfälliger) und davon überfällige. @return array{expected:int,overdue:int} */
    public function expectedDeliveryCounts(int $days = 14): array
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) AS expected, COALESCE(SUM(o.expected_delivery_date < CURDATE()), 0) AS overdue
             FROM purchase_orders o
             WHERE o.status IN ('ordered','partially_delivered')
               AND o.expected_delivery_date IS NOT NULL
               AND o.expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)",
            ['days' => $days]
        ) ?? [];

        return ['expected' => (int) ($row['expected'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0)];
    }
}
