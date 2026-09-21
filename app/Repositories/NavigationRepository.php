<?php

declare(strict_types=1);

namespace App\Repositories;

final class NavigationRepository extends Repository
{
    private const COLUMNS = 'n.id, n.title, n.url, n.type, n.parent_id, n.icon, n.background_color, n.background_opacity, n.short_description, n.description, n.content, n.alarm_text, n.alarm_group_id, n.sort_order, n.active, n.created_at, n.updated_at, g.group_number AS alarm_group_number, g.description AS alarm_group_description';

    private const FROM = ' FROM navigation_items n LEFT JOIN alarm_groups g ON g.id = n.alarm_group_id';

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . self::FROM . ' ORDER BY (n.parent_id IS NULL) DESC, COALESCE(n.parent_id, 0) ASC, n.sort_order ASC, n.id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeTopLevel(): array
    {
        return $this->activeChildrenWhere('parent_id IS NULL');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeChildren(int $parentId): array
    {
        return $this->activeChildrenWhere('parent_id = :parent_id', ['parent_id' => $parentId]);
    }

    /**
     * Alle (auch inaktiven) Unterseiten, fuer die Auswahl der uebergeordneten Ebene.
     *
     * @return list<array<string,mixed>>
     */
    public function subpages(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, title, parent_id, sort_order, active FROM navigation_items WHERE type = \'subpage\' ORDER BY (parent_id IS NULL) DESC, COALESCE(parent_id, 0) ASC, sort_order ASC, id ASC'
        );

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement === false ? [] : $statement->fetchAll();

        return $rows;
    }

    /**
     * Alle Nachfahren-IDs eines Elements (zur Vermeidung zirkulaerer Verschachtelung).
     *
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $rows = $this->all();
        $children = [];
        foreach ($rows as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }

        $result = [];
        $stack = [$id];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($children[$current] ?? [] as $child) {
                if (!in_array($child, $result, true)) {
                    $result[] = $child;
                    $stack[] = $child;
                }
            }
        }

        return $result;
    }

    public function hasChildren(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM navigation_items WHERE parent_id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . self::FROM . ' WHERE n.id = :id');
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
            'INSERT INTO navigation_items (title, url, type, parent_id, icon, background_color, background_opacity, short_description, description, content, alarm_text, alarm_group_id, sort_order, active)
             VALUES (:title, :url, :type, :parent_id, :icon, :background_color, :background_opacity, :short_description, :description, :content, :alarm_text, :alarm_group_id, :sort_order, :active)'
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
                    parent_id = :parent_id,
                    icon = :icon,
                    background_color = :background_color,
                    background_opacity = :background_opacity,
                    short_description = :short_description,
                    description = :description,
                    content = :content,
                    alarm_text = :alarm_text,
                    alarm_group_id = :alarm_group_id,
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

    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM navigation_items');
    }

    public function setParentId(int $id, ?int $parentId): void
    {
        $statement = $this->pdo->prepare('UPDATE navigation_items SET parent_id = :parent_id WHERE id = :id');
        $statement->execute(['parent_id' => $parentId, 'id' => $id]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE navigation_items SET active = :active WHERE id = :id');
        $statement->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function nextSortOrder(?int $parentId = null): int
    {
        $sql = 'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM navigation_items WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent_id');
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parentId === null ? [] : ['parent_id' => $parentId]);
        $value = $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * Verschiebt ein Element um eine Position nach oben oder unten,
     * innerhalb seiner Ebene (Eltern-Scope).
     */
    public function move(int $id, string $direction): bool
    {
        $item = $this->find($id);
        if ($item === null) {
            return false;
        }

        $parentId = $item['parent_id'] === null ? null : (int) $item['parent_id'];
        $items = $this->siblings($parentId);
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
     * Alle Elemente einer Ebene (gleiche parent_id), inklusive inaktiver.
     *
     * @return list<array<string,mixed>>
     */
    public function siblings(?int $parentId): array
    {
        $sql = 'SELECT ' . self::COLUMNS . self::FROM . ' WHERE ' . ($parentId === null ? 'n.parent_id IS NULL' : 'n.parent_id = :parent_id') . ' ORDER BY n.sort_order ASC, n.id ASC';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parentId === null ? [] : ['parent_id' => $parentId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<string,mixed> $bindings
     *
     * @return list<array<string,mixed>>
     */
    private function activeChildrenWhere(string $where, array $bindings = []): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . self::FROM . ' WHERE n.active = 1 AND ' . $where . ' ORDER BY n.sort_order ASC, n.id ASC'
        );
        $statement->execute($bindings);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function bindings(array $data): array
    {
        $bgColor = $data['background_color'] ?? null;
        $bgOpacity = $data['background_opacity'] ?? null;
        $alarmText = $data['alarm_text'] ?? null;
        $alarmGroupId = $data['alarm_group_id'] ?? null;

        // Normalize empty strings to null for optional fields
        $bgColor = $bgColor === '' ? null : $bgColor;
        $bgOpacity = $bgOpacity === '' ? null : $bgOpacity;
        $alarmText = $alarmText === '' ? null : $alarmText;
        $alarmGroupId = $alarmGroupId === '' ? null : $alarmGroupId;

        return [
            'title' => (string) $data['title'],
            'url' => (string) $data['url'],
            'type' => (string) $data['type'],
            'parent_id' => isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null ? (int) $data['parent_id'] : null,
            'icon' => $data['icon'] === null || $data['icon'] === '' ? null : (string) $data['icon'],
            'background_color' => $bgColor === null ? null : (string) $bgColor,
            'background_opacity' => $bgOpacity === null ? null : (int) $bgOpacity,
            'short_description' => (string) ($data['short_description'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'content' => isset($data['content']) && $data['content'] !== null && $data['content'] !== '' ? (string) $data['content'] : null,
            'alarm_text' => $alarmText === null ? null : (string) $alarmText,
            'alarm_group_id' => $alarmGroupId === null ? null : (int) $alarmGroupId,
            'sort_order' => (int) ($data['sort_order'] ?? 1),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
