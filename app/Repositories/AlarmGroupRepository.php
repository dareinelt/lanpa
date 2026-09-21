<?php

declare(strict_types=1);

namespace App\Repositories;

final class AlarmGroupRepository extends Repository
{
    private const COLUMNS = 'id, group_number, description, type, sort_order, active, created_at, updated_at';

    /**
     * @return list<array<string,mixed>>
     */
    public function allActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM alarm_groups WHERE active = 1 ORDER BY sort_order ASC, id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM alarm_groups ORDER BY sort_order ASC, id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM alarm_groups WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO alarm_groups (group_number, description, type, sort_order, active)
             VALUES (:group_number, :description, :type, :sort_order, :active)'
        );
        $statement->execute($this->bindings($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE alarm_groups
                SET group_number = :group_number,
                    description = :description,
                    type = :type,
                    sort_order = :sort_order,
                    active = :active
              WHERE id = :id'
        );
        $statement->execute($this->bindings($data) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM alarm_groups WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM alarm_groups');
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE alarm_groups SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function nextSortOrder(): int
    {
        $value = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM alarm_groups')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data): array
    {
        return [
            'group_number' => (string) $data['group_number'],
            'description' => (string) ($data['description'] ?? ''),
            'type' => (string) ($data['type'] ?? 'group'),
            'sort_order' => (int) ($data['sort_order'] ?? 1),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
