<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Kennzahlen und Aktivitäten für das Dashboard (reine Lesezugriffe). */
final class DashboardService
{
    /** Zeitraum für „erwartete Lieferungen“ und „auslaufende Lizenzen“ in Tagen */
    public const DELIVERY_DAYS = 14;
    public const LICENSE_DAYS = 60;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Offene Aufgaben für die prominente Aufgabenliste: nur Einträge mit Handlungsbedarf (Anzahl > 0).
     * @param array<string,int> $stats
     * @param callable(string):bool $can
     * @return list<array{label:string,count:int,href:string,level:string,hint:string}>
     */
    public function openTasks(array $stats, callable $can): array
    {
        $candidates = [
            ['movements.view', 'returns_overdue', 'Überfällige Rückgaben', '/assets?overdue=1', 'danger', 'Rückgabetermin überschritten – Mitarbeiter ansprechen oder Termin anpassen.'],
            ['movements.view', 'open_checkouts', 'Offene Entnahmen', '/movements/open?type=checkout', 'warning', 'Unvollständige Entnahmen (Standort/Kostenstelle) ergänzen.'],
            ['movements.view', 'open_returns', 'Offene Retouren', '/movements/open?type=return', 'warning', 'Rückgaben prüfen und abschließen.'],
            ['assets.view', 'assets_defective', 'Defekte Assets', '/assets?status=defective', 'danger', 'Reparatur beauftragen oder ausmustern.'],
            ['assets.view', 'assets_repair', 'Assets in Reparatur', '/assets?status=repair', 'warning', 'Rückkehr aus der Reparatur nachhalten.'],
            ['orders.view', 'deliveries_overdue', 'Überfällige Lieferungen', '/orders?status=overdue', 'danger', 'Liefertermin überschritten – beim Lieferanten nachfragen.'],
            ['orders.view', 'deliveries_expected', 'Erwartete Lieferungen (' . self::DELIVERY_DAYS . ' Tage)', '/orders?status=open', 'info', 'Wareneingang vorbereiten.'],
            ['licenses.view', 'licenses_expiring', 'Lizenzen laufen ab (' . self::LICENSE_DAYS . ' Tage)', '/licenses?expiring=' . self::LICENSE_DAYS, 'warning', 'Verlängerung klären oder Zuordnungen lösen.'],
        ];
        $tasks = [];
        foreach ($candidates as [$permission, $key, $label, $href, $level, $hint]) {
            $count = (int) ($stats[$key] ?? 0);
            if ($count > 0 && $can($permission)) {
                $tasks[] = ['label' => $label, 'count' => $count, 'href' => $href, 'level' => $level, 'hint' => $hint];
            }
        }

        return $tasks;
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return [
            'assets_total' => $this->count('SELECT COUNT(*) FROM assets'),
            'assets_in_stock' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code = 'in_stock'"),
            'assets_issued' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code = 'issued'"),
            'assets_defective' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code = 'defective'"),
            'assets_repair' => $this->count("SELECT COUNT(*) FROM assets a JOIN asset_statuses s ON s.id = a.status_id WHERE s.code = 'repair'"),
            'open_checkouts' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'checkout' AND status = 'open'"),
            'open_returns' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'return' AND status = 'open'"),
            'returns_overdue' => $this->count("SELECT COUNT(*) FROM assets WHERE expected_return_at IS NOT NULL AND expected_return_at < CURDATE() AND status_id IN (SELECT id FROM asset_statuses WHERE code IN ('issued','return_expected'))"),
            'movements_today' => $this->count("SELECT COUNT(*) FROM movements WHERE movement_date = '" . date('Y-m-d') . "' AND status <> 'cancelled'"),
            'employees_active' => $this->count('SELECT COUNT(*) FROM employees WHERE is_active = 1'),
            'orders_open' => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_delivered')"),
            'deliveries_expected' => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_delivered') AND expected_delivery_date IS NOT NULL AND expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL " . self::DELIVERY_DAYS . " DAY)"),
            'deliveries_overdue' => $this->count("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_delivered') AND expected_delivery_date IS NOT NULL AND expected_delivery_date < CURDATE()"),
            'licenses_expiring' => $this->count('SELECT COUNT(*) FROM licenses WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ' . self::LICENSE_DAYS . ' DAY)'),
        ];
    }

    /** @return array<string,int> */
    public function openCounts(): array
    {
        return [
            'checkouts' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'checkout' AND status = 'open'"),
            'returns' => $this->count("SELECT COUNT(*) FROM movements WHERE type = 'return' AND status = 'open'"),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function recentMovements(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.type, m.status, m.movement_at, m.created_by_name AS user_name, a.inventory_number, a.name AS asset_name,
                    e.display_name AS employee_name
             FROM movements m
             JOIN assets a ON a.id = m.asset_id
             LEFT JOIN employees e ON e.id = m.employee_id
             ORDER BY m.movement_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentAssetHistory(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.id, h.event_type, h.field, h.old_value, h.new_value, h.note, h.actor_name AS user_name, h.created_at, a.inventory_number, a.name AS asset_name
             FROM asset_history h
             JOIN assets a ON a.id = h.asset_id
             ORDER BY h.created_at DESC LIMIT ' . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function returnsDueSoon(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.expected_return_at, e.display_name AS employee_name
             FROM assets a
             LEFT JOIN employees e ON e.id = a.employee_id
             WHERE a.expected_return_at IS NOT NULL
               AND a.status_id IN (SELECT id FROM asset_statuses WHERE code IN (\'issued\',\'return_expected\'))
             ORDER BY a.expected_return_at ASC LIMIT ' . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Offene Bestellungen mit erwartetem Liefertermin in den nächsten Tagen (inkl. überfälliger). @return array<int,array<string,mixed>> */
    public function expectedDeliveries(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.order_number, o.expected_delivery_date, o.status, s.name AS supplier_name,
                    (SELECT COALESCE(SUM(i.quantity - i.quantity_received), 0) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS open_quantity
             FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.status IN ('ordered','partially_delivered') AND o.expected_delivery_date IS NOT NULL
               AND o.expected_delivery_date <= DATE_ADD(CURDATE(), INTERVAL " . self::DELIVERY_DAYS . " DAY)
             ORDER BY o.expected_delivery_date ASC LIMIT " . (int) $limit
        );
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array{label:string,href:string,level:string}> */
    public function personalProcurementTasks(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, order_number FROM purchase_orders WHERE created_by = :user AND status = 'draft' ORDER BY updated_at DESC LIMIT 5");
        $stmt->execute(['user' => $userId]);
        $tasks = array_map(static fn (array $row): array => ['label' => 'Bestellentwurf ' . $row['order_number'] . ' vervollständigen', 'href' => '/orders/' . $row['id'], 'level' => 'warning'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare("SELECT id FROM purchase_requests WHERE requested_by = :user AND status = 'open' ORDER BY created_at DESC LIMIT 5");
        $stmt->execute(['user' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tasks[] = ['label' => 'Bedarfsmeldung #' . $row['id'] . ' ist noch offen', 'href' => '/orders/requests', 'level' => 'info'];
        }
        return $tasks;
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
