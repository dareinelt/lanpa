<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Speicherplatz-Kontingente: Gruppenregeln, individuelle Kontingente und
 * Verlauf. Bewusst ohne MySQL-spezifisches Upsert (auch mit SQLite testbar).
 */
final class StorageQuotaRepository extends Repository
{
    /**
     * @return list<array{id:int,group_name:string,quota_mb:int,reason:string,updated_by:string,updated_at:string}>
     */
    public function groupRules(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, group_name, quota_mb, reason, updated_by, updated_at FROM storage_quota_groups ORDER BY group_name ASC'
        );

        $rules = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rules[] = [
                'id' => (int) $row['id'],
                'group_name' => (string) $row['group_name'],
                'quota_mb' => (int) $row['quota_mb'],
                'reason' => (string) $row['reason'],
                'updated_by' => (string) $row['updated_by'],
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $rules;
    }

    /**
     * @return array{id:int,group_name:string,quota_mb:int,reason:string,updated_by:string,updated_at:string}|null
     */
    public function findGroupRule(int $id): ?array
    {
        foreach ($this->groupRules() as $rule) {
            if ($rule['id'] === $id) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array{id:int,group_name:string,quota_mb:int,reason:string,updated_by:string,updated_at:string}|null
     */
    public function findGroupRuleByName(string $name): ?array
    {
        foreach ($this->groupRules() as $rule) {
            if (mb_strtolower($rule['group_name']) === mb_strtolower($name)) {
                return $rule;
            }
        }

        return null;
    }

    public function saveGroupRule(string $name, int $quotaMb, string $reason, string $admin): void
    {
        $existing = $this->findGroupRuleByName($name);
        if ($existing !== null) {
            $this->pdo->prepare(
                'UPDATE storage_quota_groups SET group_name = :name, quota_mb = :quota, reason = :reason, updated_by = :admin, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
            )->execute(['name' => $name, 'quota' => $quotaMb, 'reason' => $reason, 'admin' => $admin, 'id' => $existing['id']]);

            return;
        }

        $this->pdo->prepare(
            'INSERT INTO storage_quota_groups (group_name, quota_mb, reason, updated_by) VALUES (:name, :quota, :reason, :admin)'
        )->execute(['name' => $name, 'quota' => $quotaMb, 'reason' => $reason, 'admin' => $admin]);
    }

    public function deleteGroupRule(int $id): void
    {
        $this->pdo->prepare('DELETE FROM storage_quota_groups WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * @return list<array{user_uid:string,display_name:string,quota_mb:int,reason:string,created_by:string,updated_by:string,created_at:string,updated_at:string}>
     */
    public function overrides(): array
    {
        $statement = $this->pdo->query(
            'SELECT user_uid, display_name, quota_mb, reason, created_by, updated_by, created_at, updated_at
               FROM storage_quota_overrides ORDER BY display_name ASC, user_uid ASC'
        );

        $rows = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = self::override($row);
        }

        return $rows;
    }

    /**
     * @return array{user_uid:string,display_name:string,quota_mb:int,reason:string,created_by:string,updated_by:string,created_at:string,updated_at:string}|null
     */
    public function findOverride(string $uid): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT user_uid, display_name, quota_mb, reason, created_by, updated_by, created_at, updated_at
               FROM storage_quota_overrides WHERE LOWER(user_uid) = LOWER(:uid) LIMIT 1'
        );
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::override($row) : null;
    }

    public function saveOverride(string $uid, string $displayName, int $quotaMb, string $reason, string $admin): void
    {
        $existing = $this->findOverride($uid);
        if ($existing !== null) {
            $this->pdo->prepare(
                'UPDATE storage_quota_overrides SET display_name = :name, quota_mb = :quota, reason = :reason, updated_by = :admin, updated_at = CURRENT_TIMESTAMP
                  WHERE LOWER(user_uid) = LOWER(:uid)'
            )->execute(['name' => $displayName, 'quota' => $quotaMb, 'reason' => $reason, 'admin' => $admin, 'uid' => $uid]);

            return;
        }

        $this->pdo->prepare(
            'INSERT INTO storage_quota_overrides (user_uid, display_name, quota_mb, reason, created_by, updated_by)
             VALUES (:uid, :name, :quota, :reason, :admin, :admin2)'
        )->execute(['uid' => $uid, 'name' => $displayName, 'quota' => $quotaMb, 'reason' => $reason, 'admin' => $admin, 'admin2' => $admin]);
    }

    public function deleteOverride(string $uid): void
    {
        $this->pdo->prepare('DELETE FROM storage_quota_overrides WHERE LOWER(user_uid) = LOWER(:uid)')->execute(['uid' => $uid]);
    }

    public function addHistory(
        string $subjectType,
        string $subject,
        string $label,
        string $action,
        ?int $oldQuotaMb,
        ?int $newQuotaMb,
        string $reason,
        string $admin
    ): void {
        $this->pdo->prepare(
            'INSERT INTO storage_quota_history (subject_type, subject, subject_label, action, old_quota_mb, new_quota_mb, reason, admin_username)
             VALUES (:type, :subject, :label, :action, :old, :new, :reason, :admin)'
        )->execute([
            'type' => $subjectType,
            'subject' => mb_substr($subject, 0, 190),
            'label' => mb_substr($label, 0, 255),
            'action' => $action,
            'old' => $oldQuotaMb,
            'new' => $newQuotaMb,
            'reason' => mb_substr($reason, 0, 1000),
            'admin' => mb_substr($admin, 0, 190),
        ]);
    }

    /**
     * Neueste Eintraege zuerst; optional nur fuer einen Benutzer/eine Gruppe.
     *
     * @return list<array{id:int,subject_type:string,subject:string,subject_label:string,action:string,old_quota_mb:?int,new_quota_mb:?int,reason:string,admin_username:string,created_at:string}>
     */
    public function history(int $limit = 100, int $offset = 0, ?string $subjectType = null, ?string $subject = null): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        [$where, $params] = $this->historyCondition($subjectType, $subject);

        $statement = $this->pdo->prepare(
            'SELECT id, subject_type, subject, subject_label, action, old_quota_mb, new_quota_mb, reason, admin_username, created_at
               FROM storage_quota_history' . $where . '
              ORDER BY id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'subject_type' => (string) $row['subject_type'],
                'subject' => (string) $row['subject'],
                'subject_label' => (string) $row['subject_label'],
                'action' => (string) $row['action'],
                'old_quota_mb' => $row['old_quota_mb'] === null ? null : (int) $row['old_quota_mb'],
                'new_quota_mb' => $row['new_quota_mb'] === null ? null : (int) $row['new_quota_mb'],
                'reason' => (string) $row['reason'],
                'admin_username' => (string) $row['admin_username'],
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $rows;
    }

    public function countHistory(?string $subjectType = null, ?string $subject = null): int
    {
        [$where, $params] = $this->historyCondition($subjectType, $subject);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM storage_quota_history' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Aktive Benutzer mit SamAccountName (Grundlage fuer Nextcloud-Kennungen).
     *
     * @return list<array{id:int,identity_source_id:int,samaccount_name:string,display_name:string,department:string,email:string}>
     */
    public function activeUsers(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, identity_source_id, samaccount_name, display_name, department, email
               FROM phonebook
              WHERE active = 1 AND samaccount_name IS NOT NULL AND samaccount_name <> ''
              ORDER BY display_name ASC, id ASC"
        );

        $users = [];
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $users[] = [
                'id' => (int) $row['id'],
                'identity_source_id' => (int) $row['identity_source_id'],
                'samaccount_name' => (string) $row['samaccount_name'],
                'display_name' => (string) $row['display_name'],
                'department' => (string) ($row['department'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
            ];
        }

        return $users;
    }

    /**
     * Mitgliedschaften in den angegebenen Gruppen (Namen ohne
     * Gross-/Kleinschreibung, alle Quellen).
     *
     * @param list<string> $groupNames
     *
     * @return array<int,list<string>> Telefonbuch-ID => Gruppennamen (klein)
     */
    public function membershipsFor(array $groupNames): array
    {
        $names = array_values(array_unique(array_map(static fn (string $name): string => mb_strtolower($name), $groupNames)));
        if ($names === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->pdo->prepare(
            "SELECT DISTINCT m.phonebook_id, LOWER(g.name) AS group_name
               FROM ad_group_members m
               JOIN ad_groups g ON g.id = m.group_id
              WHERE g.active = 1 AND LOWER(g.name) IN ({$placeholders})"
        );
        $statement->execute($names);

        $map = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['phonebook_id']][] = (string) $row['group_name'];
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array{user_uid:string,display_name:string,quota_mb:int,reason:string,created_by:string,updated_by:string,created_at:string,updated_at:string}
     */
    private static function override(array $row): array
    {
        return [
            'user_uid' => (string) $row['user_uid'],
            'display_name' => (string) $row['display_name'],
            'quota_mb' => (int) $row['quota_mb'],
            'reason' => (string) $row['reason'],
            'created_by' => (string) $row['created_by'],
            'updated_by' => (string) $row['updated_by'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function historyCondition(?string $subjectType, ?string $subject): array
    {
        if ($subjectType === null) {
            return ['', []];
        }
        if ($subject === null) {
            return [' WHERE subject_type = :type', ['type' => $subjectType]];
        }

        return [' WHERE subject_type = :type AND LOWER(subject) = LOWER(:subject)', ['type' => $subjectType, 'subject' => $subject]];
    }
}
