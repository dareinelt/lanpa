<?php

declare(strict_types=1);

namespace App\Repositories;

/** Stammdaten des Help Desks: Tickettypen, Status, Prioritäten, Gruppen und Gruppenmitglieder. */
final class TicketMasterDataRepository extends BaseRepository
{
    /** Erlaubte Stammdatentabellen für die generische Pflege (Whitelist). */
    public const TABLES = ['ticket_types', 'ticket_statuses', 'ticket_priorities', 'ticket_groups'];

    // ------------------------------------------------------------------ Typen

    /** @return array<int,array<string,mixed>> */
    public function types(bool $activeOnly = true): array
    {
        return $this->fetchAll('SELECT * FROM ticket_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** @return array<string,mixed>|null */
    public function findType(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_types WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function typeByCode(string $code): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_types WHERE code = :c', ['c' => $code]);
    }

    // ------------------------------------------------------------------ Status

    /** @return array<int,array<string,mixed>> */
    public function statuses(bool $activeOnly = true): array
    {
        return $this->fetchAll('SELECT * FROM ticket_statuses' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** @return array<string,mixed>|null */
    public function findStatus(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_statuses WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function statusByCode(string $code): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_statuses WHERE code = :c', ['c' => $code]);
    }

    // ------------------------------------------------------------------ Prioritäten

    /** @return array<int,array<string,mixed>> */
    public function priorities(bool $activeOnly = true): array
    {
        return $this->fetchAll('SELECT * FROM ticket_priorities' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY level');
    }

    /** @return array<string,mixed>|null */
    public function findPriority(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_priorities WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function priorityByCode(string $code): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_priorities WHERE code = :c', ['c' => $code]);
    }

    /** @return array<string,mixed>|null */
    public function priorityByLevel(int $level): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_priorities WHERE level = :l AND is_active = 1', ['l' => $level]);
    }

    /** @return array<string,mixed>|null */
    public function defaultPriority(): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_priorities WHERE is_active = 1 ORDER BY is_default DESC, level LIMIT 1');
    }

    // ------------------------------------------------------------------ Gruppen

    /** @return array<int,array<string,mixed>> */
    public function groups(bool $activeOnly = true): array
    {
        return $this->fetchAll(
            'SELECT g.*, (SELECT COUNT(*) FROM ticket_group_members m WHERE m.group_id = g.id) AS member_count,
                    (SELECT COUNT(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.group_id = g.id AND s.category IN (\'new\',\'open\',\'pending\')) AS open_tickets
             FROM ticket_groups g' . ($activeOnly ? ' WHERE g.is_active = 1' : '') . ' ORDER BY g.sort_order, g.name'
        );
    }

    /** @return array<string,mixed>|null */
    public function findGroup(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_groups WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function groupByName(string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_groups WHERE name = :n', ['n' => $name]);
    }

    /** @return array<int,array<string,mixed>> */
    public function groupMembers(int $groupId): array
    {
        return $this->fetchAll('SELECT m.*, u.display_name, u.username, u.email, u.is_active FROM ticket_group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = :g ORDER BY m.is_lead DESC, u.display_name', ['g' => $groupId]);
    }

    /** Gruppen-IDs eines Benutzers. @return array<int,int> */
    public function groupIdsForUser(int $userId): array
    {
        return array_map('intval', array_column($this->fetchAll('SELECT group_id FROM ticket_group_members WHERE user_id = :u', ['u' => $userId]), 'group_id'));
    }

    public function setGroupMembers(int $groupId, array $userIds, array $leadIds = []): void
    {
        $this->execute('DELETE FROM ticket_group_members WHERE group_id = :g', ['g' => $groupId]);
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0) {
                $this->execute('INSERT IGNORE INTO ticket_group_members (group_id, user_id, is_lead) VALUES (:g, :u, :l)', ['g' => $groupId, 'u' => $userId, 'l' => in_array($userId, array_map('intval', $leadIds), true) ? 1 : 0]);
            }
        }
    }

    /** Aktive Benutzer mit Help-Desk-Rolle (für Zuweisung). @return array<int,array<string,mixed>> */
    public function agents(array $roleNames): array
    {
        if ($roleNames === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values($roleNames) as $i => $name) {
            $placeholders[] = ':r' . $i;
            $params['r' . $i] = $name;
        }

        return $this->fetchAll('SELECT u.id, u.username, u.display_name, u.email, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.is_active = 1 AND r.name IN (' . implode(',', $placeholders) . ') ORDER BY u.display_name', $params);
    }

    // ------------------------------------------------------------------ Generische Pflege

    /** @return array<string,mixed>|null */
    public function findIn(string $table, int $id): ?array
    {
        $this->assertTable($table);

        return $this->fetchOne("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
    }

    /** @return array<int,array<string,mixed>> */
    public function allIn(string $table): array
    {
        $this->assertTable($table);
        if ($table === 'ticket_groups') {
            return $this->groups(false);
        }

        return $this->fetchAll("SELECT * FROM {$table} ORDER BY " . ($table === 'ticket_priorities' ? 'level' : 'sort_order, name'));
    }

    /** @param array<string,mixed> $data */
    public function createIn(string $table, array $data): int
    {
        $this->assertTable($table);

        return $this->insertRow($table, $data);
    }

    /** @param array<string,mixed> $data */
    public function updateIn(string $table, int $id, array $data): void
    {
        $this->assertTable($table);
        $this->updateRow($table, $id, $data);
    }

    /** Anzahl Tickets, die auf einen Stammdatensatz verweisen (Löschschutz). */
    public function usageCount(string $table, int $id): int
    {
        $column = match ($table) {
            'ticket_types' => 'ticket_type_id',
            'ticket_statuses' => 'status_id',
            'ticket_priorities' => 'priority_id',
            'ticket_groups' => 'group_id',
            default => throw new \InvalidArgumentException('Unbekannte Stammdatentabelle.'),
        };

        return (int) $this->fetchValue("SELECT COUNT(*) FROM tickets WHERE {$column} = :id", ['id' => $id]);
    }

    private function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new \InvalidArgumentException('Unbekannte Stammdatentabelle.');
        }
    }
}
