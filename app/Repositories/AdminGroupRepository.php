<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * AD-Gruppen, deren Mitglieder Intranet- bzw. Nextcloud-Administratoren sind.
 */
final class AdminGroupRepository extends Repository
{
    /**
     * @return list<array{id:int,target:string,group_name:string,created_by:string,created_at:string}>
     */
    public function rules(?string $target = null): array
    {
        $sql = 'SELECT id, target, group_name, created_by, created_at FROM admin_group_rules';
        $params = [];
        if ($target !== null) {
            $sql .= ' WHERE target = :target';
            $params['target'] = $target;
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY group_name ASC, id ASC');
        $statement->execute($params);

        $rules = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rules[] = [
                'id' => (int) $row['id'],
                'target' => (string) $row['target'],
                'group_name' => (string) $row['group_name'],
                'created_by' => (string) $row['created_by'],
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $rules;
    }

    /**
     * @return array{id:int,target:string,group_name:string,created_by:string,created_at:string}|null
     */
    public function find(int $id): ?array
    {
        foreach ($this->rules() as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array{id:int,target:string,group_name:string,created_by:string,created_at:string}|null
     */
    public function findByName(string $target, string $groupName): ?array
    {
        foreach ($this->rules($target) as $rule) {
            if (mb_strtolower($rule['group_name']) === mb_strtolower($groupName)) {
                return $rule;
            }
        }

        return null;
    }

    public function add(string $target, string $groupName, string $admin): void
    {
        $this->pdo->prepare(
            'INSERT INTO admin_group_rules (target, group_name, created_by) VALUES (:target, :name, :admin)'
        )->execute(['target' => $target, 'name' => $groupName, 'admin' => mb_substr($admin, 0, 190)]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM admin_group_rules WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Aktive Benutzer (mit SamAccountName), die Mitglied mindestens einer der
     * angegebenen Gruppen sind (Namen ohne Gross-/Kleinschreibung, alle
     * Quellen, nur aktive Gruppen).
     *
     * @param list<string> $groupNames
     *
     * @return list<array{id:int,identity_source_id:int,samaccount_name:string,display_name:string,department:string,groups:list<string>}>
     */
    public function members(array $groupNames): array
    {
        $names = array_values(array_unique(array_map(static fn (string $name): string => mb_strtolower($name), $groupNames)));
        if ($names === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->pdo->prepare(
            "SELECT p.id, p.identity_source_id, p.samaccount_name, p.display_name, p.department, LOWER(g.name) AS group_name
               FROM phonebook p
               JOIN ad_group_members m ON m.phonebook_id = p.id
               JOIN ad_groups g ON g.id = m.group_id
              WHERE p.active = 1 AND g.active = 1
                AND p.samaccount_name IS NOT NULL AND p.samaccount_name <> ''
                AND LOWER(g.name) IN ({$placeholders})
              ORDER BY p.display_name ASC, p.id ASC"
        );
        $statement->execute($names);

        $users = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int) $row['id'];
            if (!isset($users[$id])) {
                $users[$id] = [
                    'id' => $id,
                    'identity_source_id' => (int) $row['identity_source_id'],
                    'samaccount_name' => (string) $row['samaccount_name'],
                    'display_name' => (string) $row['display_name'],
                    'department' => (string) ($row['department'] ?? ''),
                    'groups' => [],
                ];
            }
            if (!in_array((string) $row['group_name'], $users[$id]['groups'], true)) {
                $users[$id]['groups'][] = (string) $row['group_name'];
            }
        }

        return array_values($users);
    }
}
