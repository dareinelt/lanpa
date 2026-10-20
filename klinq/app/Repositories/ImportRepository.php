<?php

declare(strict_types=1);

namespace App\Repositories;

final class ImportRepository extends BaseRepository
{
    /** @param array<string,mixed> $data */
    public function createRun(array $data): int
    {
        return $this->insertRow('import_runs', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateRun(int $id, array $data): void
    {
        $this->updateRow('import_runs', $id, $data);
    }

    /** @return array<string,mixed>|null */
    public function findRun(int $id): ?array
    {
        return $this->fetchOne('SELECT r.*, u.display_name AS created_by_name FROM import_runs r LEFT JOIN users u ON u.id = r.created_by WHERE r.id = :id', ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function runs(int $limit = 50, int $offset = 0): array
    {
        return $this->fetchAll("SELECT r.*, u.display_name AS created_by_name FROM import_runs r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit} OFFSET {$offset}");
    }

    public function countRuns(): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM import_runs');
    }

    /** Alle Zeilen eines Laufs ersetzen (Vorschau → Ergebnis). @param list<array<string,mixed>> $rows */
    public function replaceRows(int $runId, array $rows): void
    {
        $this->execute('DELETE FROM import_rows WHERE import_run_id = :id', ['id' => $runId]);
        if ($rows === []) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT INTO import_rows (import_run_id, line_no, status, inventory_number, asset_id, summary, messages, data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $stmt->execute([
                $runId, (int) $row['row_number'], (string) $row['status'], $row['inventory_number'] ?? null, $row['asset_id'] ?? null,
                isset($row['summary']) ? mb_substr((string) $row['summary'], 0, 255) : null,
                json_encode($row['messages'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($row['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(int $runId, ?string $status = null, int $limit = 100000, int $offset = 0): array
    {
        $sql = 'SELECT *, line_no AS `row_number` FROM import_rows WHERE import_run_id = :id';
        $params = ['id' => $runId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }
        $rows = $this->fetchAll($sql . " ORDER BY line_no LIMIT {$limit} OFFSET {$offset}", $params);
        foreach ($rows as &$row) {
            $row['messages'] = json_decode((string) ($row['messages'] ?? '[]'), true) ?: [];
            $row['data'] = json_decode((string) ($row['data'] ?? '[]'), true) ?: [];
        }

        return $rows;
    }

    public function countRows(int $runId, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM import_rows WHERE import_run_id = :id';
        $params = ['id' => $runId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $params['status'] = $status;
        }

        return (int) $this->fetchValue($sql, $params);
    }

    /** Vorschau-Läufe, die älter als N Stunden sind (Aufräumen). @return array<int,array<string,mixed>> */
    public function stalePreviews(int $hours): array
    {
        return $this->fetchAll("SELECT * FROM import_runs WHERE status = 'preview' AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$hours} HOUR)");
    }
}
