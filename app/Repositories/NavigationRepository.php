<?php

declare(strict_types=1);

namespace App\Repositories;

final class NavigationRepository extends Repository
{
    private const COLUMNS = 'id, title, url, type, icon, short_description, description, sort_order, active, created_at, updated_at';

    /**
     * @return list<array<string,mixed>>
     */
    public function allActive(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM navigation_items WHERE active = 1 ORDER BY sort_order ASC, id ASC'
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
            'SELECT ' . self::COLUMNS . ' FROM navigation_items ORDER BY sort_order ASC, id ASC'
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
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM navigation_items WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function exists(int $id, bool $onlyActive = true): bool
    {
        $sql = 'SELECT 1 FROM navigation_items WHERE id = :id';
        if ($onlyActive) {
            $sql .= ' AND active = 1';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO navigation_items (title, url, type, icon, short_description, description, sort_order, active)
             VALUES (:title, :url, :type, :icon, :short_description, :description, :sort_order, :active)'
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
            'UPDATE navigation_items
                SET title = :title,
                    url = :url,
                    type = :type,
                    icon = :icon,
                    short_description = :short_description,
                    description = :description,
                    sort_order = :sort_order,
                    active = :active
              WHERE id = :id'
        );
        $statement->execute($this->bindings($data) + ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM navigation_items WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE navigation_items SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function nextSortOrder(): int
    {
        $value = $this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM navigation_items')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * Verschiebt ein Element um eine Position nach oben oder unten.
     */
    public function move(int $id, string $direction): bool
    {
        $items = $this->all();
        $index = null;
        foreach ($items as $position => $item) {
            if ((int) $item['id'] === $id) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return false;
        }

        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($items)) {
            return false;
        }

        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE navigation_items SET sort_order = :sort_order WHERE id = :id');
            foreach ($items as $position => $item) {
                $statement->execute(['sort_order' => $position + 1, 'id' => (int) $item['id']]);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }

        return true;
    }

    public function count(): int
    {
        $value = $this->pdo->query('SELECT COUNT(*) FROM navigation_items')?->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data): array
    {
        return [
            'title' => (string) $data['title'],
            'url' => (string) $data['url'],
            'type' => (string) $data['type'],
            'icon' => $data['icon'] === null || $data['icon'] === '' ? null : (string) $data['icon'],
            'short_description' => (string) ($data['short_description'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'sort_order' => (int) ($data['sort_order'] ?? 1),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
