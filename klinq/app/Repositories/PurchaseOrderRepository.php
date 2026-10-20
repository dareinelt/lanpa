<?php

declare(strict_types=1);

namespace App\Repositories;

/** Bestellungen, Positionen und Wareneingänge. */
final class PurchaseOrderRepository extends BaseRepository
{
    private const SELECT = 'SELECT o.*, s.name AS supplier_name, s.customer_number AS supplier_customer_number,
            cc.number AS cost_center_number, cc.description AS cost_center_name,
            COALESCE(u.display_name, o.ordered_by_name) AS ordered_by_display,
            cu.display_name AS created_by_name,
            (SELECT COUNT(*) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS item_count,
            (SELECT COALESCE(SUM(i.quantity), 0) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS quantity_total,
            (SELECT COALESCE(SUM(i.quantity_received), 0) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS quantity_received,
            (SELECT COALESCE(SUM(i.quantity * COALESCE(i.unit_price, 0)), 0) FROM purchase_order_items i WHERE i.purchase_order_id = o.id) AS total_net,
            (SELECT COUNT(*) FROM goods_receipts g WHERE g.purchase_order_id = o.id) AS receipt_count,
            (SELECT COUNT(*) FROM documents d WHERE d.entity_type = \'purchase_order\' AND d.entity_id = o.id) AS document_count
        FROM purchase_orders o
        JOIN suppliers s ON s.id = o.supplier_id
        LEFT JOIN cost_centers cc ON cc.id = o.cost_center_id
        LEFT JOIN users u ON u.id = o.ordered_by_user_id
        LEFT JOIN users cu ON cu.id = o.created_by';

    private const ITEM_SELECT = 'SELECT i.*, a.name AS article_name, a.article_number, a.manufacturer_id, m.name AS manufacturer_name,
            COALESCE(i.asset_type_id, a.asset_type_id) AS effective_asset_type_id,
            t.name AS asset_type_name, t.icon AS asset_type_icon, t.inventory_prefix, t.has_serial_number, a.is_consumable,
            (i.quantity - i.quantity_received) AS quantity_open,
            (SELECT COUNT(*) FROM assets x WHERE x.purchase_order_item_id = i.id) AS asset_count
        FROM purchase_order_items i
        LEFT JOIN articles a ON a.id = i.article_id
        LEFT JOIN manufacturers m ON m.id = a.manufacturer_id
        LEFT JOIN asset_types t ON t.id = COALESCE(i.asset_type_id, a.asset_type_id)';

    // ------------------------------------------------------------------ Bestellungen

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE o.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByNumber(string $number, ?int $excludeId = null): ?array
    {
        $params = ['n' => $number];
        $sql = 'SELECT id, order_number FROM purchase_orders WHERE order_number = :n';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql, $params);
    }

    /**
     * @param array<string,mixed> $filters q, supplier_id, status (Code | open | all), cost_center_id, date_from, date_to, overdue
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY o.order_date DESC, o.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id {$where}", $params);
    }

    /** @return array<string,int> Anzahl je Status + überfällige offene Bestellungen */
    public function statusCounts(): array
    {
        $counts = [];
        foreach ($this->fetchAll('SELECT status, COUNT(*) AS c FROM purchase_orders GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        $counts['overdue'] = (int) $this->fetchValue(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_delivered') AND expected_delivery_date IS NOT NULL AND expected_delivery_date < :today",
            ['today' => date('Y-m-d')]
        );

        return $counts;
    }

    /** Bestellungen für Auswahlfelder (neueste zuerst). @return array<int,array<string,mixed>> */
    public function forSelect(int $limit = 200): array
    {
        return $this->fetchAll(
            'SELECT o.id, o.order_number, o.order_date, s.name AS supplier_name FROM purchase_orders o JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.status <> \'cancelled\' ORDER BY o.order_date DESC, o.id DESC LIMIT ' . $limit
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function forSupplier(int $supplierId, int $limit = 20): array
    {
        return $this->fetchAll(self::SELECT . " WHERE o.supplier_id = :sid ORDER BY o.order_date DESC, o.id DESC LIMIT {$limit}", ['sid' => $supplierId]);
    }

    /** Nächste freie Bestellnummer im Format B-JJJJ-NNNN. */
    public function nextOrderNumber(): string
    {
        $prefix = 'B-' . date('Y') . '-';
        $last = (string) $this->fetchValue(
            'SELECT order_number FROM purchase_orders WHERE order_number LIKE :p ORDER BY order_number DESC LIMIT 1',
            ['p' => $prefix . '%']
        );
        $n = $last !== '' && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;

        return $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('purchase_orders', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('purchase_orders', $id, $data);
    }

    // ------------------------------------------------------------------ Positionen

    /** @return array<int,array<string,mixed>> */
    public function items(int $orderId): array
    {
        return $this->fetchAll(self::ITEM_SELECT . ' WHERE i.purchase_order_id = :oid ORDER BY i.position, i.id', ['oid' => $orderId]);
    }

    /** @return array<string,mixed>|null */
    public function findItem(int $id): ?array
    {
        return $this->fetchOne(self::ITEM_SELECT . ' WHERE i.id = :id', ['id' => $id]);
    }

    public function nextPosition(int $orderId): int
    {
        return (int) $this->fetchValue('SELECT COALESCE(MAX(position), 0) + 1 FROM purchase_order_items WHERE purchase_order_id = :oid', ['oid' => $orderId]);
    }

    /** @param array<string,mixed> $data */
    public function createItem(array $data): int
    {
        return $this->insertRow('purchase_order_items', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateItem(int $id, array $data): void
    {
        $this->updateRow('purchase_order_items', $id, $data);
    }

    public function deleteItem(int $id): void
    {
        $this->execute('DELETE FROM purchase_order_items WHERE id = ?', [$id]);
    }

    public function addReceived(int $itemId, int $quantity): void
    {
        $this->execute('UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?', [$quantity, $itemId]);
    }

    // ------------------------------------------------------------------ Wareneingänge

    /** @return array<int,array<string,mixed>> */
    public function receipts(int $orderId): array
    {
        return $this->fetchAll(
            'SELECT g.*, u.display_name AS received_by_name, l.full_path AS location_path,
                    (SELECT COALESCE(SUM(gi.quantity), 0) FROM goods_receipt_items gi WHERE gi.goods_receipt_id = g.id) AS quantity_total,
                    (SELECT COUNT(*) FROM assets x WHERE x.goods_receipt_id = g.id) AS asset_count
             FROM goods_receipts g
             LEFT JOIN users u ON u.id = g.received_by
             LEFT JOIN locations l ON l.id = g.location_id
             WHERE g.purchase_order_id = :oid ORDER BY g.received_at DESC, g.id DESC',
            ['oid' => $orderId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findReceipt(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT g.*, u.display_name AS received_by_name, l.full_path AS location_path, o.order_number, o.supplier_id, s.name AS supplier_name
             FROM goods_receipts g
             JOIN purchase_orders o ON o.id = g.purchase_order_id
             JOIN suppliers s ON s.id = o.supplier_id
             LEFT JOIN users u ON u.id = g.received_by
             LEFT JOIN locations l ON l.id = g.location_id
             WHERE g.id = :id',
            ['id' => $id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function receiptItems(int $receiptId): array
    {
        return $this->fetchAll(
            'SELECT gi.*, i.position, i.description, i.creates_assets, a.name AS article_name
             FROM goods_receipt_items gi
             JOIN purchase_order_items i ON i.id = gi.purchase_order_item_id
             LEFT JOIN articles a ON a.id = i.article_id
             WHERE gi.goods_receipt_id = :rid ORDER BY i.position',
            ['rid' => $receiptId]
        );
    }

    /** @param array<string,mixed> $data */
    public function createReceipt(array $data): int
    {
        return $this->insertRow('goods_receipts', $data);
    }

    /** @param array<string,mixed> $data */
    public function createReceiptItem(array $data): int
    {
        return $this->insertRow('goods_receipt_items', $data);
    }

    /** Assets, die aus einer Bestellung bzw. einem Wareneingang entstanden sind. @return array<int,array<string,mixed>> */
    public function assetsFor(int $orderId, ?int $receiptId = null): array
    {
        $sql = 'SELECT a.id, a.inventory_number, a.name, a.serial_number, a.purchase_order_item_id, a.goods_receipt_id,
                       t.name AS asset_type_name, t.icon AS asset_type_icon, st.name AS status_name, st.color AS status_color,
                       ar.name AS article_name, l.full_path AS location_path
                FROM assets a
                JOIN asset_types t ON t.id = a.asset_type_id
                JOIN asset_statuses st ON st.id = a.status_id
                LEFT JOIN articles ar ON ar.id = a.article_id
                LEFT JOIN locations l ON l.id = a.location_id
                WHERE a.purchase_order_id = :oid';
        $params = ['oid' => $orderId];
        if ($receiptId !== null) {
            $sql .= ' AND a.goods_receipt_id = :rid';
            $params['rid'] = $receiptId;
        }

        return $this->fetchAll($sql . ' ORDER BY a.inventory_number', $params);
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed> $filters @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(o.order_number LIKE :q OR s.name LIKE :q OR o.note LIKE :q OR o.ordered_by_name LIKE :q
                OR EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = o.id AND i.description LIKE :q))';
            $params['q'] = $this->like($q);
        }
        foreach (['supplier_id', 'cost_center_id'] as $col) {
            if (!empty($filters[$col])) {
                $where[] = "o.{$col} = :{$col}";
                $params[$col] = (int) $filters[$col];
            }
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'open') {
            $where[] = "o.status IN ('draft','ordered','partially_delivered')";
        } elseif ($status === 'overdue') {
            $where[] = "o.status IN ('ordered','partially_delivered') AND o.expected_delivery_date IS NOT NULL AND o.expected_delivery_date < :today";
            $params['today'] = date('Y-m-d');
        } elseif ($status !== '' && $status !== 'all') {
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'o.order_date >= :from';
            $params['from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'o.order_date <= :to';
            $params['to'] = $filters['date_to'];
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }
}
