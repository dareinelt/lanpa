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

    /**
     * Aktive Kategorien plus die gerade gewählte (auch wenn deaktiviert), damit das
     * Bearbeiten eines Datensatzes eine deaktivierte Kategorie nicht stillschweigend entfernt.
     *
     * @return array<int,array<string,mixed>>
     */
    public function selectableCategories(?int $currentId = null): array
    {
        $sql = 'SELECT c.*, t.name AS asset_type_name FROM asset_categories c JOIN asset_types t ON t.id = c.asset_type_id
                WHERE c.is_active = 1' . ($currentId !== null ? ' OR c.id = :current' : '');

        return $this->fetchAll($sql . ' ORDER BY t.sort_order, c.sort_order, c.name', $currentId !== null ? ['current' => $currentId] : []);
    }

    /**
     * Aktive Assettypen plus der gerade gewählte (auch wenn deaktiviert).
     *
     * @return array<int,array<string,mixed>>
     */
    public function selectable(?int $currentId = null): array
    {
        if ($currentId === null) {
            return $this->all(true);
        }

        return $this->fetchAll('SELECT * FROM asset_types WHERE is_active = 1 OR id = :current ORDER BY sort_order, name', ['current' => $currentId]);
    }

    /**
     * Assettypen mit Verwendungszählern für den Katalog.
     *
     * @return array<int,array<string,mixed>>
     */
    public function allWithUsage(): array
    {
        return $this->fetchAll('SELECT t.*,
                (SELECT COUNT(*) FROM assets a WHERE a.asset_type_id = t.id) AS asset_count,
                (SELECT COUNT(*) FROM articles ar WHERE ar.asset_type_id = t.id) AS article_count,
                (SELECT COUNT(*) FROM asset_categories c WHERE c.asset_type_id = t.id) AS category_count
            FROM asset_types t ORDER BY t.sort_order, t.name');
    }

    /**
     * Kategorien mit Verwendungszählern für den Katalog, optional auf einen Assettyp gefiltert.
     *
     * @return array<int,array<string,mixed>>
     */
    public function categoriesWithUsage(?int $assetTypeId = null): array
    {
        $sql = 'SELECT c.*, t.name AS asset_type_name, t.is_active AS asset_type_active,
                (SELECT COUNT(*) FROM assets a WHERE a.asset_category_id = c.id) AS asset_count,
                (SELECT COUNT(*) FROM articles ar WHERE ar.asset_category_id = c.id) AS article_count
            FROM asset_categories c JOIN asset_types t ON t.id = c.asset_type_id';
        $params = [];
        if ($assetTypeId !== null) {
            $sql .= ' WHERE c.asset_type_id = :type';
            $params['type'] = $assetTypeId;
        }

        return $this->fetchAll($sql . ' ORDER BY t.sort_order, t.name, c.sort_order, c.name', $params);
    }

    /** Anzahl Assets eines Assettyps (Inventarnummern hängen am Präfix). */
    public function assetCount(int $assetTypeId): int
    {
        return (int) ($this->fetchOne('SELECT COUNT(*) AS n FROM assets WHERE asset_type_id = :id', ['id' => $assetTypeId])['n'] ?? 0);
    }

    /** Anzahl Assets und Artikel, die eine Kategorie verwenden. */
    public function categoryUsage(int $categoryId): int
    {
        $row = $this->fetchOne('SELECT
                (SELECT COUNT(*) FROM assets WHERE asset_category_id = :id) +
                (SELECT COUNT(*) FROM articles WHERE asset_category_id = :id) AS n', ['id' => $categoryId]);

        return (int) ($row['n'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    public function findCategoryByName(int $assetTypeId, string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM asset_categories WHERE asset_type_id = :type AND name = :name', ['type' => $assetTypeId, 'name' => $name]);
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
