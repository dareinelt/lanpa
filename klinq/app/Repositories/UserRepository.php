<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    // is_locked wird in SQL berechnet (DB-Sitzung läuft in UTC) – kein Zeitzonenvergleich in PHP nötig
    // groups_csv: kommagetrennte Namen der zugeordneten Berechtigungsgruppen (einem Benutzer
    // können mehrere Gruppen zugeordnet werden, z. B. "Statistik" für Ticket-Berichte)
    private const SELECT = 'SELECT u.*, r.name AS role, r.label AS role_label,
        (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked,
        (SELECT GROUP_CONCAT(pg.name ORDER BY pg.name SEPARATOR \',\')
            FROM user_permission_groups upg JOIN permission_groups pg ON pg.id = upg.group_id
            WHERE upg.user_id = u.id) AS groups_csv
        FROM users u JOIN roles r ON r.id = u.role_id';

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private static function withGroups(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $csv = $row['groups_csv'] ?? null;
        $row['groups'] = $csv === null || $csv === '' ? [] : explode(',', (string) $csv);
        unset($row['groups_csv']);

        return $row;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function withGroupsAll(array $rows): array
    {
        return array_map(static fn (array $row): array => self::withGroups($row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return self::withGroups($this->fetchOne(self::SELECT . ' WHERE u.username = ? LIMIT 1', [$username]));
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return self::withGroups($this->fetchOne(self::SELECT . ' WHERE u.id = ? LIMIT 1', [$id]));
    }

    /** Aktiver Benutzer zu einer E-Mail-Adresse (Groß-/Kleinschreibung egal). @return array<string,mixed>|null */
    public function findActiveByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return self::withGroups($this->fetchOne(self::SELECT . ' WHERE LOWER(u.email) = ? AND u.is_active = 1 ORDER BY u.id LIMIT 1', [$email]));
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return self::withGroupsAll($this->fetchAll(self::SELECT . ' ORDER BY u.username'));
    }

    /**
     * @param array{q?:string,role?:string,status?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters): array
    {
        [$where, $params] = $this->whereClause($filters);

        return self::withGroupsAll($this->fetchAll(self::SELECT . $where . ' ORDER BY u.is_active DESC, u.username', $params));
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:array<int,mixed>} */
    private function whereClause(array $filters): array
    {
        $conditions = [];
        $params = [];
        if (($filters['q'] ?? '') !== '') {
            $like = '%' . $filters['q'] . '%';
            $conditions[] = '(u.username LIKE ? OR u.display_name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        if (($filters['role'] ?? '') !== '') {
            $conditions[] = 'r.name = ?';
            $params[] = $filters['role'];
        }
        if (($filters['status'] ?? '') === 'active') {
            $conditions[] = 'u.is_active = 1';
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $conditions[] = 'u.is_active = 0';
        }

        return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
    }

    /** @return array<int,array<string,mixed>> */
    public function roles(): array
    {
        return $this->fetchAll('SELECT id, name, label FROM roles ORDER BY id');
    }

    /** Alle verfügbaren Berechtigungsgruppen. @return array<int,array<string,mixed>> */
    public function permissionGroups(): array
    {
        return $this->fetchAll('SELECT id, name, label FROM permission_groups ORDER BY label');
    }

    /** Gruppen-IDs, denen ein Benutzer aktuell zugeordnet ist. @return array<int,int> */
    public function groupIdsForUser(int $userId): array
    {
        return array_map('intval', array_column($this->fetchAll('SELECT group_id FROM user_permission_groups WHERE user_id = ?', [$userId]), 'group_id'));
    }

    /** Setzt die Gruppenzuordnung eines Benutzers (ersetzt bestehende Zuordnungen). @param array<int,int> $groupIds */
    public function syncGroups(int $userId, array $groupIds): void
    {
        $this->execute('DELETE FROM user_permission_groups WHERE user_id = ?', [$userId]);
        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            $this->execute('INSERT IGNORE INTO user_permission_groups (user_id, group_id) VALUES (?, ?)', [$userId, $groupId]);
        }
    }

    public function countActiveWithRole(string $role, ?int $excludeId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.name = ?';
        $params = [$role];
        if ($excludeId !== null) {
            $sql .= ' AND u.id <> ?';
            $params[] = $excludeId;
        }

        return (int) $this->fetchValue($sql, $params);
    }

    public function count(): int
    {
        return (int) $this->fetchValue('SELECT COUNT(*) FROM users');
    }

    public function roleId(string $role): ?int
    {
        $id = $this->fetchValue('SELECT id FROM roles WHERE name = ?', [$role]);

        return $id === null ? null : (int) $id;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('users', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('users', $id, $data);
    }

    public function recordLoginSuccess(int $id): void
    {
        $this->execute('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public function recordLoginFailure(int $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->execute(
            'UPDATE users SET failed_logins = failed_logins + 1,
                locked_until = IF(failed_logins + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), locked_until)
             WHERE id = ?',
            [$maxAttempts, $lockMinutes, $id]
        );
    }
}
