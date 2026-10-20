<?php

declare(strict_types=1);

namespace App\Repositories;

final class AssetHistoryRepository extends BaseRepository
{
    /**
     * @param array<string,mixed> $entry event_type, field, old_value, new_value, old_id, new_id, movement_id, user_id, actor_name, note
     */
    public function add(int $assetId, array $entry): int
    {
        $row = array_merge(['asset_id' => $assetId, 'actor_name' => 'system'], $entry);
        foreach (['old_value', 'new_value', 'note'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && mb_strlen($row[$key]) > 500) {
                $row[$key] = mb_substr($row[$key], 0, 497) . '…';
            }
        }

        return $this->insertRow('asset_history', $row);
    }

    /** @return array<int,array<string,mixed>> Neueste zuerst */
    public function forAsset(int $assetId, int $limit = 500): array
    {
        return $this->fetchAll(
            "SELECT h.*, u.display_name AS user_display_name
             FROM asset_history h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.asset_id = :id
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT {$limit}",
            ['id' => $assetId]
        );
    }

    /** Einträge, die durch eine Bewegung entstanden sind (für Storno). @return array<int,array<string,mixed>> */
    public function forMovement(int $movementId): array
    {
        return $this->fetchAll('SELECT * FROM asset_history WHERE movement_id = :id ORDER BY id ASC', ['id' => $movementId]);
    }

    /** @return array<int,array<string,mixed>> Letzte Änderungen über alle Assets (Dashboard). */
    public function recent(int $limit = 20): array
    {
        return $this->fetchAll(
            "SELECT h.*, a.inventory_number, a.name AS asset_name
             FROM asset_history h
             JOIN assets a ON a.id = h.asset_id
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT {$limit}"
        );
    }
}
