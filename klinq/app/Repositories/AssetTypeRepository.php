<?php

declare(strict_types=1);

namespace App\Repositories;

final class AssetTypeRepository extends BaseRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(bool $onlyActive = false): array
    {
        return $this->fetchAll('SELECT * FROM asset_types' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM asset_types WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->fetchOne('SELECT * FROM asset_types WHERE code = :code', ['code' => $code]);
    }

    /** @return array<string,mixed>|null */
    public function findByPrefix(string $prefix): ?array
    {
        return $this->fetchOne('SELECT * FROM asset_types WHERE inventory_prefix = :p', ['p' => $prefix]);
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(?int $assetTypeId = null, bool $onlyActive = false): array
    {
        $sql = 'SELECT c.*, t.name AS asset_type_name FROM asset_categories c JOIN asset_types t ON t.id = c.asset_type_id';
        $clauses = [];
        $params = [];
        if ($assetTypeId !== null) {
            $clauses[] = 'c.asset_type_id = :type';
            $params['type'] = $assetTypeId;
        }
        if ($onlyActive) {
            $clauses[] = 'c.is_active = 1';
        }
        if ($clauses) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        return $this->fetchAll($sql . ' ORDER BY t.sort_order, c.sort_order, c.name', $params);
    }

    /** @return array<string,mixed>|null */
    public function findCategory(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM asset_categories WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('asset_types', $id, $data);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('asset_types', $data);
    }

    /** @param array<string,mixed> $data */
    public function createCategory(array $data): int
    {
        return $this->insertRow('asset_categories', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateCategory(int $id, array $data): void
    {
        $this->updateRow('asset_categories', $id, $data);
    }
}
