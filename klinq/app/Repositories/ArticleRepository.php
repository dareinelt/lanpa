<?php

declare(strict_types=1);

namespace App\Repositories;

final class ArticleRepository extends BaseRepository
{
    private const SELECT = 'SELECT a.*, m.name AS manufacturer_name, t.name AS asset_type_name, t.code AS asset_type_code,
                                   c.name AS category_name,
                                   (SELECT COUNT(*) FROM assets s WHERE s.article_id = a.id) AS asset_count
                            FROM articles a
                            JOIN manufacturers m ON m.id = a.manufacturer_id
                            JOIN asset_types t ON t.id = a.asset_type_id
                            LEFT JOIN asset_categories c ON c.id = a.asset_category_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.id = :id', ['id' => $id]);
    }

    /**
     * @param array<string,mixed> $filters q, manufacturer_id, asset_type_id, active
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY m.name, a.name LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM articles a JOIN manufacturers m ON m.id = a.manufacturer_id {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForSelect(?int $assetTypeId = null): array
    {
        $sql = 'SELECT a.id, a.name, a.article_number, a.asset_type_id, a.asset_category_id, a.manufacturer_id, a.is_consumable, m.name AS manufacturer_name
                FROM articles a JOIN manufacturers m ON m.id = a.manufacturer_id WHERE a.is_active = 1';
        $params = [];
        if ($assetTypeId !== null) {
            $sql .= ' AND a.asset_type_id = :type';
            $params['type'] = $assetTypeId;
        }

        return $this->fetchAll($sql . ' ORDER BY m.name, a.name', $params);
    }

    /**
     * Autovervollständigung für das Asset-Formular: nur aktive Artikel, optional auf einen Assettyp beschränkt.
     * @return array<int,array<string,mixed>>
     */
    public function searchForPicker(string $term, ?int $assetTypeId = null, int $limit = 15): array
    {
        $sql = 'SELECT a.id, a.name, a.article_number, a.asset_type_id, a.asset_category_id, a.manufacturer_id, a.is_handover_relevant,
                       m.name AS manufacturer_name, t.name AS asset_type_name, c.name AS category_name
                FROM articles a
                JOIN manufacturers m ON m.id = a.manufacturer_id
                JOIN asset_types t ON t.id = a.asset_type_id
                LEFT JOIN asset_categories c ON c.id = a.asset_category_id
                WHERE a.is_active = 1';
        $params = [];
        if ($term !== '') {
            $sql .= ' AND (a.name LIKE :q OR a.article_number LIKE :q OR m.name LIKE :q OR CONCAT(m.name, \' \', a.name) LIKE :q)';
            $params['q'] = $this->like($term);
        }
        if ($assetTypeId !== null) {
            $sql .= ' AND a.asset_type_id = :type';
            $params['type'] = $assetTypeId;
        }
        $limit = max(1, min($limit, 50));

        return $this->fetchAll($sql . " ORDER BY m.name, a.name LIMIT {$limit}", $params);
    }

    /** @return array<string,mixed>|null */
    public function findDuplicate(int $manufacturerId, string $name, ?int $excludeId = null): ?array
    {
        $params = ['m' => $manufacturerId, 'n' => $name];
        $sql = 'SELECT id, name FROM articles WHERE manufacturer_id = :m AND LOWER(name) = LOWER(:n)';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    /** Exakter Treffer über den normalisierten Namen (Schreibweise egal) innerhalb eines Herstellers. */
    public function findByNormalizedName(int $manufacturerId, string $normalizedName, ?int $excludeId = null): ?array
    {
        $params = ['m' => $manufacturerId, 'nn' => $normalizedName];
        $sql = 'SELECT id, name, article_number, is_active FROM articles WHERE manufacturer_id = :m AND normalized_name = :nn';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    /** Artikel über die Artikelnummer innerhalb eines Herstellers. @return array<string,mixed>|null */
    public function findByArticleNumber(int $manufacturerId, string $articleNumber): ?array
    {
        if (trim($articleNumber) === '') {
            return null;
        }

        return $this->fetchOne('SELECT id, name, article_number, is_active FROM articles WHERE manufacturer_id = :m AND LOWER(article_number) = LOWER(:an) LIMIT 1', ['m' => $manufacturerId, 'an' => trim($articleNumber)]);
    }

    /**
     * Kandidaten für die phonetische Dublettenprüfung (herstellerübergreifend, damit auch
     * „gleicher Artikel bei falschem Hersteller“ auffällt): gleicher phonetischer Schlüssel,
     * gleicher Normalname, Teilstring oder gleiche Artikelnummer.
     * @return array<int,array<string,mixed>>
     */
    public function findCandidates(string $phoneticKey, string $normalizedName, ?string $articleNumber = null, ?int $excludeId = null): array
    {
        $params = ['pk' => $phoneticKey, 'nn' => $normalizedName, 'like' => $this->like($normalizedName)];
        $sql = 'SELECT a.id, a.name, a.article_number, a.is_active, a.phonetic_key, a.normalized_name, a.manufacturer_id, m.name AS manufacturer_name
                FROM articles a JOIN manufacturers m ON m.id = a.manufacturer_id
                WHERE (a.phonetic_key = :pk OR a.normalized_name = :nn OR a.normalized_name LIKE :like';
        $words = array_values(array_filter(explode(' ', $phoneticKey), static fn (string $w): bool => strlen($w) >= 2));
        foreach ($words as $i => $word) {
            $sql .= " OR a.phonetic_key LIKE :w{$i}";
            $params["w{$i}"] = '%' . $word . '%';
        }
        if ($articleNumber !== null && $articleNumber !== '') {
            $sql .= ' OR LOWER(a.article_number) = LOWER(:an)';
            $params['an'] = $articleNumber;
        }
        $sql .= ')';
        if ($excludeId !== null) {
            $sql .= ' AND a.id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchAll($sql . ' ORDER BY a.name LIMIT 30', $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('articles', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('articles', $id, $data);
    }

    /** @return array<int,array<string,mixed>> Nachbestellvorschläge für aktive Verbrauchsartikel. */
    public function replenishmentSuggestions(): array
    {
        return $this->fetchAll(
            'SELECT a.id, a.name, a.article_number, a.stock_quantity, a.minimum_stock, m.name AS manufacturer_name
             FROM articles a JOIN manufacturers m ON m.id = a.manufacturer_id
             WHERE a.is_active = 1 AND a.is_consumable = 1 AND a.minimum_stock IS NOT NULL AND a.stock_quantity <= a.minimum_stock
             ORDER BY m.name, a.name'
        );
    }

    public function addStock(int $id, int $quantity): void
    {
        $this->execute('UPDATE articles SET stock_quantity = stock_quantity + ? WHERE id = ?', [$quantity, $id]);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(a.name LIKE :q OR a.article_number LIKE :q OR m.name LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (!empty($filters['manufacturer_id'])) {
            $clauses[] = 'a.manufacturer_id = :manufacturer_id';
            $params['manufacturer_id'] = (int) $filters['manufacturer_id'];
        }
        if (!empty($filters['asset_type_id'])) {
            $clauses[] = 'a.asset_type_id = :asset_type_id';
            $params['asset_type_id'] = (int) $filters['asset_type_id'];
        }
        if (isset($filters['handover']) && $filters['handover'] !== '' && $filters['handover'] !== null) {
            $clauses[] = 'a.is_handover_relevant = :handover';
            $params['handover'] = (int) $filters['handover'];
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $clauses[] = 'a.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
