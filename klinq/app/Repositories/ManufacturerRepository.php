<?php

declare(strict_types=1);

namespace App\Repositories;

final class ManufacturerRepository extends BaseRepository
{
    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT m.*, (SELECT COUNT(*) FROM articles a WHERE a.manufacturer_id = m.id) AS article_count,
                    (SELECT COUNT(*) FROM assets s WHERE s.manufacturer_id = m.id) AS asset_count
             FROM manufacturers m WHERE m.id = :id',
            ['id' => $id]
        );
    }

    /**
     * @param array<string,mixed> $filters q, active
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(
            "SELECT m.*, (SELECT COUNT(*) FROM articles a WHERE a.manufacturer_id = m.id) AS article_count,
                    (SELECT COUNT(*) FROM assets s WHERE s.manufacturer_id = m.id) AS asset_count
             FROM manufacturers m {$where} ORDER BY m.name LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM manufacturers m {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForSelect(): array
    {
        return $this->fetchAll('SELECT id, name, short_name FROM manufacturers WHERE is_active = 1 ORDER BY name');
    }

    /**
     * Kandidaten für Dublettenprüfung: gleicher phonetischer Schlüssel (ganz oder wortweise),
     * gleicher Normalname oder Teilstring.
     * @return array<int,array<string,mixed>>
     */
    public function findCandidates(string $phoneticKey, string $normalizedName, ?int $excludeId = null): array
    {
        $params = ['pk' => $phoneticKey, 'nn' => $normalizedName, 'like' => $this->like($normalizedName)];
        $sql = 'SELECT id, name, short_name, is_active, phonetic_key, normalized_name FROM manufacturers
                WHERE (phonetic_key = :pk OR normalized_name = :nn OR normalized_name LIKE :like';
        $words = array_filter(explode(' ', $phoneticKey), static fn (string $w): bool => strlen($w) >= 2);
        foreach (array_values($words) as $i => $word) {
            $sql .= " OR phonetic_key LIKE :w{$i}";
            $params["w{$i}"] = '%' . $word . '%';
        }
        $sql .= ')';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchAll($sql . ' ORDER BY name LIMIT 20', $params);
    }

    /** @return array<string,mixed>|null */
    public function findByNormalizedName(string $normalizedName, ?int $excludeId = null): ?array
    {
        $params = ['nn' => $normalizedName];
        $sql = 'SELECT * FROM manufacturers WHERE normalized_name = :nn';
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }

        return $this->fetchOne($sql . ' LIMIT 1', $params);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('manufacturers', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('manufacturers', $id, $data);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(m.name LIKE :q OR m.short_name LIKE :q)';
            $params['q'] = $this->like((string) $filters['q']);
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $clauses[] = 'm.is_active = :active';
            $params['active'] = (int) $filters['active'];
        }

        return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
    }
}
