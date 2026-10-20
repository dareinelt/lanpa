<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProcurementRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function templates(): array
    {
        return $this->fetchAll('SELECT t.*, s.name AS supplier_name, cc.number AS cost_center_number,
            (SELECT COUNT(*) FROM purchase_order_template_items i WHERE i.purchase_order_template_id = t.id) AS item_count
            FROM purchase_order_templates t LEFT JOIN suppliers s ON s.id = t.supplier_id LEFT JOIN cost_centers cc ON cc.id = t.cost_center_id
            ORDER BY t.name');
    }

    /** @return array<string,mixed>|null */
    public function template(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM purchase_order_templates WHERE id = :id', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function templateItems(int $id): array
    {
        return $this->fetchAll('SELECT * FROM purchase_order_template_items WHERE purchase_order_template_id = :id ORDER BY position', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createTemplate(array $data): int { return $this->insertRow('purchase_order_templates', $data); }
    /** @param array<string,mixed> $data */
    public function createTemplateItem(array $data): int { return $this->insertRow('purchase_order_template_items', $data); }

    /** @return array<int,array<string,mixed>> */
    public function requests(?int $userId = null): array
    {
        $where = $userId === null ? '' : ' WHERE r.requested_by = :user';
        return $this->fetchAll('SELECT r.*, cc.number AS cost_center_number, po.order_number,
            (SELECT COUNT(*) FROM purchase_request_items i WHERE i.purchase_request_id = r.id) AS item_count
            FROM purchase_requests r LEFT JOIN cost_centers cc ON cc.id = r.cost_center_id LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id'
            . $where . ' ORDER BY FIELD(r.status, \'open\', \'converted\', \'cancelled\'), r.created_at DESC', $userId === null ? [] : ['user' => $userId]);
    }

    /** @return array<string,mixed>|null */
    public function request(int $id): ?array { return $this->fetchOne('SELECT * FROM purchase_requests WHERE id = :id', ['id' => $id]); }
    /** @return array<int,array<string,mixed>> */
    public function requestItems(int $id): array { return $this->fetchAll('SELECT * FROM purchase_request_items WHERE purchase_request_id = :id ORDER BY id', ['id' => $id]); }
    /** @param array<string,mixed> $data */
    public function createRequest(array $data): int { return $this->insertRow('purchase_requests', $data); }
    /** @param array<string,mixed> $data */
    public function createRequestItem(array $data): int { return $this->insertRow('purchase_request_items', $data); }
    /** @param array<string,mixed> $data */
    public function updateRequest(int $id, array $data): void { $this->updateRow('purchase_requests', $id, $data); }

    /** Atomar für die Übernahme reservieren; verhindert doppelte Bestellungen. */
    public function claimRequest(int $id): bool
    {
        return $this->execute("UPDATE purchase_requests SET status = 'converted' WHERE id = ? AND status = 'open'", [$id]) === 1;
    }
}
