<?php

declare(strict_types=1);

namespace App\Repositories;

final class AlarmLogRepository extends Repository
{
    private const COLUMNS = 'id, navigation_id, title, alarm_text, group_number, group_description, mode, status, message, triggered_at';

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM alarm_log ORDER BY triggered_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO alarm_log (navigation_id, title, alarm_text, group_number, group_description, mode, status, message, triggered_at)
             VALUES (:navigation_id, :title, :alarm_text, :group_number, :group_description, :mode, :status, :message, :triggered_at)'
        );
        $statement->execute([
            'navigation_id' => $data['navigation_id'] ?? null,
            'title' => (string) ($data['title'] ?? ''),
            'alarm_text' => (string) ($data['alarm_text'] ?? ''),
            'group_number' => (string) ($data['group_number'] ?? ''),
            'group_description' => (string) ($data['group_description'] ?? ''),
            'mode' => (string) ($data['mode'] ?? 'group'),
            'status' => (string) ($data['status'] ?? 'error'),
            'message' => $data['message'] ?? null,
            'triggered_at' => (string) ($data['triggered_at'] ?? gmdate('Y-m-d H:i:s')),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
