<?php

declare(strict_types=1);

namespace App\Repositories;

final class NavigationRepository extends Repository
{
    private const COLUMNS = 'n.id, n.title, n.url, n.type, n.parent_id, n.icon, n.background_color, n.background_opacity, n.override_background, n.short_description, n.description, n.content, n.alarm_text, n.alarm_group_id, n.protected_access, n.sort_order, n.active, n.created_at, n.updated_at, g.group_number AS alarm_group_number, g.description AS alarm_group_description, g.type AS alarm_group_type';

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
     * Aktive oberste Ebene, gefiltert nach SSO-Berechtigungen.
     *
     * Kacheln ohne Berechtigungseintrag gelten als oeffentlich; Kacheln mit
     * Eintrag sind nur sichtbar, wenn $userId oder eine der $groups zugeordnet
     * ist. Ist $userId null (anonym), werden nur oeffentliche Kacheln geliefert.
     * Ein angemeldeter Benutzer ohne jegliche zugewiesene Berechtigung verhaelt
     * sich dabei wie ein anonymer Benutzer (er sieht ausschliesslich oeffentliche
     * Kacheln).
     *
     * @param list<string> $groups
     *
     * @return list<array<string,mixed>>
     */
    public function activeTopLevelForUser(?int $userId, array $groups = []): array
    {
        return $this->activeChildrenWhereForUser('n.parent_id IS NULL', [], $userId, $groups);
    }

    /**
     * Aktive Unterseiten, gefiltert nach SSO-Berechtigungen (siehe oben).
     *
     * @param list<string> $groups
     *
     * @return list<array<string,mixed>>
     */
    public function activeChildrenForUser(int $parentId, ?int $userId, array $groups = []): array
    {
        return $this->activeChildrenWhereForUser('n.parent_id = :parent_id', ['parent_id' => $parentId], $userId, $groups);
    }

    /**
     * Prueft, ob ein Element fuer den gegebenen Benutzer sichtbar ist
     * (serverseitige Zugriffssperre fuer Unterseiten/Textseiten).
     *
     * @param list<string> $groups
     */
    public function isAccessible(int $id, ?int $userId, array $groups = []): bool
    {
        if (!$this->exists($id, false)) {
            return false;
        }

        if (!$this->hasPermissions($id)) {
            return true;
        }

        if ($userId === null) {
            return false;
        }

        $bindings = ['nav' => $id, 'perm_user' => $userId];
        $groupClause = '';
        if ($groups !== []) {
            $placeholders = [];
            foreach (array_values($groups) as $index => $group) {
                $placeholders[] = ':perm_group_' . $index;
                $bindings['perm_group_' . $index] = $group;
            }
            $groupClause = ' OR (p.identity_type = \'group\' AND LOWER(p.group_name) IN (' . implode(', ', array_map(static fn (string $p): string => 'LOWER(' . $p . ')', $placeholders)) . '))';
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM navigation_item_permissions p
              WHERE p.navigation_id = :nav
                AND ((p.identity_type = \'user\' AND p.user_id = :perm_user)' . $groupClause . ')
              LIMIT 1'
        );
        $statement->execute($bindings);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param list<string> $groups
     *
     * @return list<array<string,mixed>>
     */
    private function activeChildrenWhereForUser(string $where, array $bindings, ?int $userId, array $groups): array
    {
        $sql = 'SELECT ' . self::COLUMNS . self::FROM
            . ' WHERE n.active = 1 AND ' . $where . ' AND ' . $this->visibilityClause($userId, $groups)
            . ' ORDER BY n.sort_order ASC, n.id ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings + $this->visibilityBindings($userId, $groups));

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * @param list<string> $groups
     */
    private function visibilityClause(?int $userId, array $groups): string
    {
        if ($userId === null) {
            return 'n.id NOT IN (SELECT navigation_id FROM navigation_item_permissions)';
        }

        $allowed = "(p.identity_type = 'user' AND p.user_id = :perm_user)";
        if ($groups !== []) {
            $placeholders = [];
            foreach (array_keys($groups) as $index) {
                $placeholders[] = ':perm_group_' . $index;
            }
            $allowed .= " OR (p.identity_type = 'group' AND LOWER(p.group_name) IN (" . implode(', ', array_map(static fn (string $p): string => 'LOWER(' . $p . ')', $placeholders)) . '))';
        }

        return '(n.id NOT IN (SELECT navigation_id FROM navigation_item_permissions)'
            . ' OR n.id IN (SELECT p.navigation_id FROM navigation_item_permissions p WHERE ' . $allowed . '))';
    }

    /**
     * @param list<string> $groups
     *
     * @return array<string,mixed>
     */
    private function visibilityBindings(?int $userId, array $groups): array
    {
        if ($userId === null) {
            return [];
        }

        $bindings = ['perm_user' => $userId];
        foreach (array_values($groups) as $index => $group) {
            $bindings['perm_group_' . $index] = $group;
        }

        return $bindings;
    }

    /**
     * Liefert true, wenn fuer das Element mindestens ein Berechtigungseintrag
     * existiert (also kein oeffentliches Element ist).
     */
    public function hasPermissions(int $navigationId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM navigation_item_permissions WHERE navigation_id = :nav LIMIT 1');
        $statement->execute(['nav' => $navigationId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Liefert die einem Element zugeordneten Benutzer-IDs und Gruppennamen.
     *
     * @return array{user_ids:list<int>,group_names:list<string>}
     */
    public function permissionsForNavigation(int $navigationId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT identity_type, user_id, group_name FROM navigation_item_permissions WHERE navigation_id = :nav ORDER BY id ASC'
        );
        $statement->execute(['nav' => $navigationId]);
        $rows = $statement->fetchAll();

        $userIds = [];
        $groupNames = [];
        foreach ($rows as $row) {
            if ($row['identity_type'] === 'group') {
                $groupNames[] = (string) $row['group_name'];
            } else {
                $userIds[] = (int) $row['user_id'];
            }
        }

        return [
            'user_ids' => array_values(array_unique($userIds)),
            'group_names' => array_values(array_unique($groupNames)),
        ];
    }

    /**
     * Ersetzt alle Berechtigungseintraege eines Elements.
     *
     * @param list<int>    $userIds
     * @param list<string> $groupNames
     */
    public function replacePermissions(int $navigationId, array $userIds, array $groupNames): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM navigation_item_permissions WHERE navigation_id = :nav');
            $delete->execute(['nav' => $navigationId]);

            $insertUser = $this->pdo->prepare(
                "INSERT INTO navigation_item_permissions (navigation_id, identity_type, user_id) VALUES (:nav, 'user', :user_id)"
            );
            foreach (array_unique($userIds) as $userId) {
                $insertUser->execute(['nav' => $navigationId, 'user_id' => (int) $userId]);
            }

            $insertGroup = $this->pdo->prepare(
                "INSERT INTO navigation_item_permissions (navigation_id, identity_type, group_name) VALUES (:nav, 'group', :group_name)"
            );
            foreach (array_unique($groupNames) as $groupName) {
                $groupName = trim((string) $groupName);
                if ($groupName === '') {
                    continue;
                }
                $insertGroup->execute(['nav' => $navigationId, 'group_name' => $groupName]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
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

    /**
     * Findet ein aktives internes Navigationselement anhand seines Pfads
     * (z. B. /telefonliste). Dient der serverseitigen Zugriffssperre.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveInternalByUrl(string $url): ?array
    {
        // Der angeforderte Pfad ist normalisiert (ohne abschliessenden Slash).
        // Gepflegte interne URLs koennen jedoch mit oder ohne Slash hinterlegt
        // sein – deshalb werden beide Schreibweisen abgeglichen.
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . self::FROM . " WHERE n.type = 'internal' AND n.active = 1 AND (n.url = :url OR n.url = :url_slash) LIMIT 1"
        );
        $statement->execute(['url' => $url, 'url_slash' => rtrim($url, '/') . '/']);
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
            'INSERT INTO navigation_items (title, url, type, parent_id, icon, background_color, background_opacity, override_background, short_description, description, content, alarm_text, alarm_group_id, protected_access, sort_order, active)
             VALUES (:title, :url, :type, :parent_id, :icon, :background_color, :background_opacity, :override_background, :short_description, :description, :content, :alarm_text, :alarm_group_id, :protected_access, :sort_order, :active)'
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
                    override_background = :override_background,
                    short_description = :short_description,
                    description = :description,
                    content = :content,
                    alarm_text = :alarm_text,
                    alarm_group_id = :alarm_group_id,
                    protected_access = :protected_access,
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
            'override_background' => !empty($data['override_background']) ? 1 : 0,
            'short_description' => (string) ($data['short_description'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'content' => isset($data['content']) && $data['content'] !== null && $data['content'] !== '' ? (string) $data['content'] : null,
            'alarm_text' => $alarmText === null ? null : (string) $alarmText,
            'alarm_group_id' => $alarmGroupId === null ? null : (int) $alarmGroupId,
            'protected_access' => !empty($data['protected_access']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 1),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
    }
}
