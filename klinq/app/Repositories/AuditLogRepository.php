<?php

declare(strict_types=1);

namespace App\Repositories;

final class AuditLogRepository extends BaseRepository
{
    /** @param array<string,mixed> $data */
    public function insert(array $data): int
    {
        return $this->insertRow('audit_logs', $data);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->fetchAll(
            "SELECT * FROM audit_logs {$where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        return (int) $this->fetchValue("SELECT COUNT(*) FROM audit_logs {$where}", $params);
    }

    /** @return array<int,array<string,mixed>> */
    public function forObject(string $type, int $id, int $limit = 50): array
    {
        return $this->fetchAll(
            "SELECT * FROM audit_logs WHERE object_type = ? AND object_id = ? ORDER BY id DESC LIMIT {$limit}",
            [$type, $id]
        );
    }

    /** @return list<string> */
    public function distinctValues(string $column): array
    {
        if (!in_array($column, ['action', 'object_type', 'username'], true)) {
            throw new \InvalidArgumentException('Ungültige Spalte.');
        }

        return array_map('strval', array_column($this->fetchAll("SELECT DISTINCT {$column} AS v FROM audit_logs ORDER BY {$column}"), 'v'));
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['q'])) {
            $clauses[] = '(username LIKE ? OR action LIKE ? OR object_type LIKE ? OR object_label LIKE ?)';
            $like = $this->like((string) $filters['q']);
            array_push($params, $like, $like, $like, $like);
        }
        if (!empty($filters['object_type'])) {
            $clauses[] = 'object_type = ?';
            $params[] = $filters['object_type'];
        }
        if (!empty($filters['object_id'])) {
            $clauses[] = 'object_id = ?';
            $params[] = (int) $filters['object_id'];
        }
        if (!empty($filters['action'])) {
            $clauses[] = 'action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['username'])) {
            $clauses[] = 'username = ?';
            $params[] = $filters['username'];
        }
        // Datumsgrenzen (lokale Zeitzone) → UTC, da created_at in UTC gespeichert ist
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= ?';
            $params[] = self::toUtc($filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= ?';
            $params[] = self::toUtc($filters['to'] . ' 23:59:59');
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    private static function toUtc(string $local): string
    {
        $dt = date_create($local) ?: date_create('1970-01-01');
        $dt->setTimezone(new \DateTimeZone('UTC'));

        return $dt->format('Y-m-d H:i:s');
    }
}
