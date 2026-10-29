<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Aufgaben des Help Desks inkl. Listen-/Filterabfragen, Nummernsequenz und Verlauf.
 * Tickets, Prioritäten und Benutzer werden nur referenziert.
 */
final class TaskRepository extends BaseRepository
{
    /** Whitelist für Sortierspalten der Aufgabenliste. */
    public const SORTABLE = [
        'number' => 'a.number',
        'subject' => 'a.subject',
        'status' => 'a.status',
        'priority' => 'p.level',
        'assignee' => 'au.display_name',
        'due_at' => 'a.due_at',
        'created_at' => 'a.created_at',
        'updated_at' => 'a.updated_at',
    ];

    private const FROM = '
        FROM tasks a
        LEFT JOIN ticket_priorities p ON p.id = a.priority_id
        LEFT JOIN task_categories tc ON tc.id = a.category_id
        LEFT JOIN users au ON au.id = a.assignee_user_id
        LEFT JOIN users ab ON ab.id = a.assigned_by_user_id
        LEFT JOIN users cu ON cu.id = a.completed_by
        LEFT JOIN tickets t ON t.id = a.source_ticket_id';

    private const COLUMNS = 'a.*,
            p.name AS priority_name, p.color AS priority_color, p.level AS priority_level,
            tc.name AS category_name,
            au.display_name AS assignee_name, au.email AS assignee_email,
            ab.display_name AS assigned_by_name,
            cu.display_name AS completed_by_name,
            t.number AS source_ticket_number, t.subject AS source_ticket_subject';

    /** Zusatzspalten der Liste: Vorschau des jüngsten Kommentars und Anzahl der Kommentare. */
    private const LIST_COLUMNS = ',
            (SELECT c.body FROM task_comments c WHERE c.task_id = a.id ORDER BY c.created_at DESC, c.id DESC LIMIT 1) AS last_comment,
            (SELECT COUNT(*) FROM task_comments c WHERE c.task_id = a.id) AS comment_count';

    private const SELECT = 'SELECT ' . self::COLUMNS . self::FROM;

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByNumber(string $number): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.number = :n', ['n' => $number]);
    }

    /** @return array<string,mixed>|null */
    public function findByTicket(int $ticketId): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE a.source_ticket_id = :t', ['t' => $ticketId]);
    }

    /** Sperrt die Aufgabe für die Dauer der Transaktion. @return array<string,mixed>|null */
    public function lock(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM tasks WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('tasks', $data);
    }

    /**
     * Aktualisiert eine Aufgabe mit optimistischer Sperre (version). Liefert false, wenn die Version nicht mehr passt.
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data, ?int $expectedVersion = null): bool
    {
        if ($data === []) {
            return true;
        }
        unset($data['version']);
        $set = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($data)));
        $params = [...array_values($data), $id];
        $sql = "UPDATE tasks SET {$set}, version = version + 1 WHERE id = ?";
        if ($expectedVersion !== null) {
            $sql .= ' AND version = ?';
            $params[] = $expectedVersion;
        }

        return $this->execute($sql, $params) > 0;
    }

    // ------------------------------------------------------------------ Aufgabennummer

    /** Nächste laufende Nummer je Präfix/Jahr, transaktionssicher (SELECT ... FOR UPDATE). */
    public function nextSequence(string $prefix, string $year): int
    {
        $this->execute('INSERT IGNORE INTO ticket_sequences (prefix, year_code, last_number) VALUES (:p, :y, 0)', ['p' => $prefix, 'y' => $year]);
        $current = (int) $this->fetchValue('SELECT last_number FROM ticket_sequences WHERE prefix = :p AND year_code = :y FOR UPDATE', ['p' => $prefix, 'y' => $year]);
        $next = $current + 1;
        $this->execute('UPDATE ticket_sequences SET last_number = :n WHERE prefix = :p AND year_code = :y', ['n' => $next, 'p' => $prefix, 'y' => $year]);

        return $next;
    }

    public function numberExists(string $number): bool
    {
        return $this->fetchValue('SELECT 1 FROM tasks WHERE number = :n', ['n' => $number]) !== null;
    }

    // ------------------------------------------------------------------ Listen

    /**
     * @param array<string,mixed> $filters view (open|mine_open|unassigned|done|all), user_id, q, status, priority_id,
     *        assignee_user_id, created_from, created_to
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, string $sort, string $dir, int $limit, int $offset): array
    {
        [$where, $params] = $this->whereFor($filters);
        $column = self::SORTABLE[$sort] ?? 'a.updated_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $select = 'SELECT ' . self::COLUMNS . self::LIST_COLUMNS . self::FROM;

        return $this->fetchAll($select . " {$where} ORDER BY {$column} {$direction}, a.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
    }

    /** @param array<string,mixed> $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->whereFor($filters);

        return (int) $this->fetchValue('SELECT COUNT(*)' . self::FROM . " {$where}", $params);
    }

    /**
     * Zähler der Standardansichten für den angemeldeten Benutzer.
     * @return array<string,int>
     */
    public function viewCounts(int $userId): array
    {
        $counts = [];
        foreach (['open', 'unassigned', 'done', 'all', 'mine_open', 'mine_done', 'mine'] as $view) {
            $counts[$view] = $this->countSearch(['view' => $view, 'user_id' => $userId]);
        }

        return $counts;
    }

    /** Offene Aufgaben eines Agenten (für die Navigation). */
    public function openCountFor(int $userId): int
    {
        return $this->countSearch(['view' => 'mine_open', 'user_id' => $userId]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function whereFor(array $filters): array
    {
        $where = [];
        $params = [];
        $userId = (int) ($filters['user_id'] ?? 0);

        switch ((string) ($filters['view'] ?? 'open')) {
            case 'mine_open':
                $where[] = "a.status IN ('open','in_progress') AND a.assignee_user_id = :uid";
                $params['uid'] = $userId;
                break;
            case 'mine':
                $where[] = 'a.assignee_user_id = :uid';
                $params['uid'] = $userId;
                break;
            case 'mine_done':
                $where[] = "a.status = 'done' AND a.assignee_user_id = :uid";
                $params['uid'] = $userId;
                break;
            case 'unassigned':
                $where[] = "a.status = 'open' AND a.assignee_user_id IS NULL";
                break;
            case 'done':
                $where[] = "a.status = 'done'";
                break;
            case 'open':
                $where[] = "a.status IN ('open','in_progress')";
                break;
            case 'all':
            default:
                break;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.number LIKE :q OR a.subject LIKE :q OR a.description LIKE :q OR au.display_name LIKE :q)';
            $params['q'] = $this->like($q);
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['priority_id'])) {
            $where[] = 'a.priority_id = :priority_id';
            $params['priority_id'] = (int) $filters['priority_id'];
        }
        if (($filters['assignee_user_id'] ?? '') !== '' && $filters['assignee_user_id'] !== null) {
            if ($filters['assignee_user_id'] === 'none') {
                $where[] = 'a.assignee_user_id IS NULL';
            } else {
                $where[] = 'a.assignee_user_id = :assignee_user_id';
                $params['assignee_user_id'] = (int) $filters['assignee_user_id'];
            }
        }
        if (!empty($filters['created_from'])) {
            $where[] = 'a.created_at >= :created_from';
            $params['created_from'] = (string) $filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $where[] = 'a.created_at <= :created_to';
            $params['created_to'] = (string) $filters['created_to'] . ' 23:59:59';
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    // ------------------------------------------------------------------ Verlauf

    /** @param array<string,mixed>|null $payload */
    public function addEvent(int $taskId, string $type, ?int $userId, string $userName, ?string $field = null, ?string $old = null, ?string $new = null, ?array $payload = null): int
    {
        return $this->insertRow('task_events', [
            'task_id' => $taskId,
            'type' => $type,
            'user_id' => $userId,
            'user_name' => mb_substr($userName, 0, 200),
            'field' => $field,
            'old_value' => $old !== null ? mb_substr($old, 0, 500) : null,
            'new_value' => $new !== null ? mb_substr($new, 0, 500) : null,
            'payload' => $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function events(int $taskId): array
    {
        return $this->fetchAll('SELECT * FROM task_events WHERE task_id = :t ORDER BY created_at ASC, id ASC', ['t' => $taskId]);
    }
}
