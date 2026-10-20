<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Tickets inkl. Listen-/Filterabfragen, Asset-Verknüpfungen, Watcher, Verlauf und Nummernsequenz.
 * Mitarbeiter, Assets, Standorte und Benutzer werden nur referenziert.
 */
final class TicketRepository extends BaseRepository
{
    /** Whitelist für Sortierspalten der Ticketliste. */
    public const SORTABLE = [
        'number' => 't.number',
        'subject' => 't.subject',
        'status' => 'st.sort_order',
        'priority' => 'p.level',
        'requester' => 'req.display_name',
        'assignee' => 'au.display_name',
        'group' => 'g.name',
        'category' => 'c.name',
        'created_at' => 't.created_at',
        'updated_at' => 't.updated_at',
        'resolution_due_at' => 't.resolution_due_at',
    ];

    private const SELECT = 'SELECT t.*,
            ty.code AS type_code, ty.name AS type_name, ty.color AS type_color, ty.icon AS type_icon,
            st.code AS status_code, st.name AS status_name, st.color AS status_color, st.category AS status_category, st.pauses_sla,
            p.code AS priority_code, p.name AS priority_name, p.color AS priority_color, p.level AS priority_level,
            c.name AS category_name, sc.name AS subcategory_name,
            g.name AS group_name,
            req.display_name AS requester_name, COALESCE(req.email, t.requester_email) AS requester_email, req.department AS requester_department, req.phone AS requester_phone,
            aff.display_name AS affected_name, aff.email AS affected_email,
            au.display_name AS assignee_name, au.email AS assignee_email,
            du.display_name AS deputy_name,
            ru.display_name AS requester_user_name, ru.email AS requester_user_email,
            sla.name AS sla_name,
            loc.full_path AS location_path, loc.name AS location_name,
            cc.number AS cost_center_number, cc.description AS cost_center_name,
            mt.number AS merged_into_number,
            (SELECT COUNT(*) FROM ticket_comments tc WHERE tc.ticket_id = t.id) AS comment_count,
            (SELECT COUNT(*) FROM ticket_attachments ta WHERE ta.ticket_id = t.id) AS attachment_count,
            (SELECT COUNT(*) FROM ticket_assets tas WHERE tas.ticket_id = t.id) AS asset_count,
            (SELECT GROUP_CONCAT(tg.name ORDER BY tg.name SEPARATOR \',\') FROM ticket_tag_relations tr JOIN ticket_tags tg ON tg.id = tr.tag_id WHERE tr.ticket_id = t.id) AS tag_names'
        . self::FROM;

    private const FROM = '
        FROM tickets t
        JOIN ticket_types ty ON ty.id = t.ticket_type_id
        JOIN ticket_statuses st ON st.id = t.status_id
        JOIN ticket_priorities p ON p.id = t.priority_id
        LEFT JOIN ticket_categories c ON c.id = t.category_id
        LEFT JOIN ticket_categories sc ON sc.id = t.subcategory_id
        LEFT JOIN ticket_groups g ON g.id = t.group_id
        LEFT JOIN employees req ON req.id = t.requester_employee_id
        LEFT JOIN employees aff ON aff.id = t.affected_employee_id
        LEFT JOIN users au ON au.id = t.assignee_user_id
        LEFT JOIN users du ON du.id = t.deputy_user_id
        LEFT JOIN users ru ON ru.id = t.requester_user_id
        LEFT JOIN ticket_slas sla ON sla.id = t.sla_id
        LEFT JOIN locations loc ON loc.id = t.location_id
        LEFT JOIN cost_centers cc ON cc.id = t.cost_center_id
        LEFT JOIN tickets mt ON mt.id = t.merged_into_ticket_id';

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE t.id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByNumber(string $number): ?array
    {
        return $this->fetchOne(self::SELECT . ' WHERE t.number = :n', ['n' => $number]);
    }

    /** Ticket-ID zu einer E-Mail-Message-ID (Ursprungsmail des Tickets oder eines Kommentars). */
    public function idByMailMessageId(string $messageId): ?int
    {
        $messageId = mb_substr($messageId, 0, 255);
        $id = $this->fetchValue('SELECT id FROM tickets WHERE mail_message_id = :m LIMIT 1', ['m' => $messageId]);
        if ($id === null) {
            $id = $this->fetchValue('SELECT ticket_id FROM ticket_comments WHERE mail_message_id = :m LIMIT 1', ['m' => $messageId]);
        }

        return $id !== null ? (int) $id : null;
    }

    /** Sperrt die Ticketzeile für Statuswechsel/Zuweisung (innerhalb einer Transaktion). @return array<string,mixed>|null */
    public function lock(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM tickets WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('tickets', $data);
    }

    /**
     * Aktualisiert ein Ticket mit optimistischer Sperre (version). Liefert false, wenn die Version nicht mehr passt.
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
        $sql = "UPDATE tickets SET {$set}, version = version + 1 WHERE id = ?";
        if ($expectedVersion !== null) {
            $sql .= ' AND version = ?';
            $params[] = $expectedVersion;
        }

        return $this->execute($sql, $params) > 0;
    }

    // ------------------------------------------------------------------ Ticketnummer

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
        return $this->fetchValue('SELECT 1 FROM tickets WHERE number = :n', ['n' => $number]) !== null;
    }

    // ------------------------------------------------------------------ Listen

    /**
     * @param array<string,mixed> $filters q, view, status_id, status_category, priority_id, type_id, category_id, group_id,
     *        assignee_user_id, requester_employee_id, requester_user_id, tag, asset_id, location_id, sla (ok|warning|breached), created_from, created_to,
     *        visible_user_id (Portal: nur eigene Tickets), include_merged
     * @return array<int,array<string,mixed>>
     */
    public function search(array $filters, string $sort, string $dir, int $limit, int $offset): array
    {
        [$where, $params] = $this->whereFor($filters);
        $column = self::SORTABLE[$sort] ?? 't.updated_at';
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY {$column} {$direction}, t.id DESC LIMIT {$limit} OFFSET {$offset}", $params);
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
    public function viewCounts(int $userId, ?int $visibleUserId = null): array
    {
        $views = ['mine', 'mine_open', 'unassigned', 'open', 'overdue', 'waiting_user', 'recently_resolved', 'recently_closed', 'all'];
        $counts = [];
        foreach ($views as $view) {
            $counts[$view] = $this->countSearch(['view' => $view, 'user_id' => $userId, 'visible_user_id' => $visibleUserId]);
        }

        return $counts;
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

        if (empty($filters['include_merged'])) {
            $where[] = 't.merged_into_ticket_id IS NULL';
        }

        // Portal: nur eigene Tickets (Melder, betroffener Mitarbeiter, Ersteller, Watcher)
        if (!empty($filters['visible_user_id'])) {
            $where[] = '(t.requester_user_id = :vis_uid OR t.created_by = :vis_uid
                OR t.requester_employee_id IN (SELECT u.employee_id FROM users u WHERE u.id = :vis_uid AND u.employee_id IS NOT NULL)
                OR t.affected_employee_id IN (SELECT u.employee_id FROM users u WHERE u.id = :vis_uid AND u.employee_id IS NOT NULL)
                OR EXISTS (SELECT 1 FROM ticket_watchers w WHERE w.ticket_id = t.id AND w.user_id = :vis_uid))';
            $params['vis_uid'] = (int) $filters['visible_user_id'];
        }

        switch ((string) ($filters['view'] ?? '')) {
            case 'mine':
                $where[] = '(t.assignee_user_id = :uid OR t.deputy_user_id = :uid)';
                $params['uid'] = $userId;
                break;
            case 'mine_open':
                $where[] = '(t.assignee_user_id = :uid OR t.deputy_user_id = :uid)';
                $where[] = "st.category IN ('new','open','pending')";
                $params['uid'] = $userId;
                break;
            case 'unassigned':
                $where[] = 't.assignee_user_id IS NULL';
                $where[] = "st.category IN ('new','open','pending')";
                break;
            case 'open':
                $where[] = "st.category IN ('new','open','pending')";
                break;
            case 'overdue':
                $where[] = "st.category IN ('new','open','pending')";
                $where[] = "((t.resolution_due_at IS NOT NULL AND t.resolution_due_at < UTC_TIMESTAMP() AND t.sla_paused_at IS NULL) OR (t.first_response_at IS NULL AND t.response_due_at IS NOT NULL AND t.response_due_at < UTC_TIMESTAMP() AND t.sla_paused_at IS NULL) OR t.sla_resolution_state = 'breached')";
                break;
            case 'waiting_user':
                $where[] = "st.code = 'waiting_user'";
                break;
            case 'recently_resolved':
                $where[] = "st.category = 'resolved'";
                $where[] = 't.resolved_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)';
                break;
            case 'recently_closed':
                $where[] = "st.category = 'closed'";
                $where[] = 't.closed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)';
                break;
            case 'critical':
                $where[] = "p.code = 'critical'";
                $where[] = "st.category IN ('new','open','pending')";
                break;
            case 'portal_open':
                $where[] = "st.category IN ('new','open','pending')";
                break;
            case 'portal_resolved':
                $where[] = "st.category IN ('resolved','closed','cancelled')";
                break;
            default:
                break;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(t.number LIKE :q OR t.subject LIKE :q OR t.description LIKE :q OR t.external_reference LIKE :q
                OR req.display_name LIKE :q OR aff.display_name LIKE :q OR au.display_name LIKE :q
                OR EXISTS (SELECT 1 FROM ticket_assets qa JOIN assets qas ON qas.id = qa.asset_id WHERE qa.ticket_id = t.id AND (qas.inventory_number LIKE :q OR qas.serial_number LIKE :q))
                OR EXISTS (SELECT 1 FROM ticket_tag_relations qr JOIN ticket_tags qt ON qt.id = qr.tag_id WHERE qr.ticket_id = t.id AND qt.name LIKE :q)
                OR EXISTS (SELECT 1 FROM ticket_comments qc WHERE qc.ticket_id = t.id AND qc.body LIKE :q))';
            $params['q'] = $this->like($q);
        }

        foreach (['status_id' => 't.status_id', 'priority_id' => 't.priority_id', 'type_id' => 't.ticket_type_id', 'group_id' => 't.group_id',
            'assignee_user_id' => 't.assignee_user_id', 'requester_employee_id' => 't.requester_employee_id', 'requester_user_id' => 't.requester_user_id',
            'location_id' => 't.location_id', 'sla_id' => 't.sla_id', 'created_by' => 't.created_by'] as $key => $column) {
            if (!empty($filters[$key])) {
                $where[] = "{$column} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        if (!empty($filters['category_id'])) {
            $where[] = '(t.category_id = :category_id OR t.subcategory_id = :category_id)';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = '(t.requester_employee_id = :employee_id OR t.affected_employee_id = :employee_id)';
            $params['employee_id'] = (int) $filters['employee_id'];
        }
        if (!empty($filters['status_category'])) {
            $where[] = 'st.category = :status_category';
            $params['status_category'] = (string) $filters['status_category'];
        }
        if (!empty($filters['status_code'])) {
            $where[] = 'st.code = :status_code';
            $params['status_code'] = (string) $filters['status_code'];
        }
        if (isset($filters['unassigned']) && $filters['unassigned']) {
            $where[] = 't.assignee_user_id IS NULL';
        }
        if (!empty($filters['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM ticket_tag_relations fr JOIN ticket_tags ft ON ft.id = fr.tag_id WHERE fr.ticket_id = t.id AND ft.name = :tag)';
            $params['tag'] = (string) $filters['tag'];
        }
        if (!empty($filters['asset_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM ticket_assets fa WHERE fa.ticket_id = t.id AND fa.asset_id = :asset_id)';
            $params['asset_id'] = (int) $filters['asset_id'];
        }
        if (!empty($filters['sla'])) {
            switch ((string) $filters['sla']) {
                case 'breached':
                    $where[] = "(t.sla_resolution_state = 'breached' OR t.sla_response_state = 'breached')";
                    break;
                case 'warning':
                    $where[] = "(t.sla_resolution_state = 'warning' OR t.sla_response_state = 'warning')";
                    break;
                case 'ok':
                    $where[] = "t.sla_resolution_state IN ('ok','met') AND t.sla_response_state IN ('ok','met','none')";
                    break;
            }
        }
        if (!empty($filters['created_from'])) {
            $where[] = 't.created_at >= :created_from';
            $params['created_from'] = (string) $filters['created_from'] . ' 00:00:00';
        }
        if (!empty($filters['created_to'])) {
            $where[] = 't.created_at <= :created_to';
            $params['created_to'] = (string) $filters['created_to'] . ' 23:59:59';
        }
        if (!empty($filters['watcher_user_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM ticket_watchers fw WHERE fw.ticket_id = t.id AND fw.user_id = :watcher_user_id)';
            $params['watcher_user_id'] = (int) $filters['watcher_user_id'];
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /** Tickets zu einem Asset (für die Assetdetailseite). @return array<int,array<string,mixed>> */
    public function forAsset(int $assetId, int $limit = 20): array
    {
        return $this->fetchAll(self::SELECT . " WHERE EXISTS (SELECT 1 FROM ticket_assets fa WHERE fa.ticket_id = t.id AND fa.asset_id = :aid) ORDER BY (st.category IN ('new','open','pending')) DESC, t.updated_at DESC LIMIT {$limit}", ['aid' => $assetId]);
    }

    /** Offene Tickets je Asset-ID (Badge auf Assetdetailseite). */
    public function openCountForAsset(int $assetId): int
    {
        return (int) $this->fetchValue("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses st ON st.id = t.status_id WHERE st.category IN ('new','open','pending') AND EXISTS (SELECT 1 FROM ticket_assets fa WHERE fa.ticket_id = t.id AND fa.asset_id = :aid)", ['aid' => $assetId]);
    }

    /** Schnellsuche (globale Suche). @return array<int,array<string,mixed>> */
    public function quickSearch(string $term, int $limit, ?int $visibleUserId = null): array
    {
        [$where, $params] = $this->whereFor(['q' => $term, 'visible_user_id' => $visibleUserId, 'include_merged' => true]);

        return $this->fetchAll(self::SELECT . " {$where} ORDER BY (st.category IN ('new','open','pending')) DESC, t.updated_at DESC LIMIT {$limit}", $params);
    }

    /** Für Vorschlagslisten (Verknüpfen/Zusammenführen). @return array<int,array<string,mixed>> */
    public function suggest(string $term, int $limit, ?int $excludeId = null): array
    {
        $params = ['q' => $this->like($term)];
        $sql = 'SELECT t.id, t.number, t.subject, st.name AS status_name, st.color AS status_color FROM tickets t JOIN ticket_statuses st ON st.id = t.status_id WHERE (t.number LIKE :q OR t.subject LIKE :q) AND t.merged_into_ticket_id IS NULL';
        if ($excludeId !== null) {
            $sql .= ' AND t.id <> :ex';
            $params['ex'] = $excludeId;
        }

        return $this->fetchAll($sql . " ORDER BY t.updated_at DESC LIMIT {$limit}", $params);
    }

    // ------------------------------------------------------------------ Assets

    /** @return array<int,array<string,mixed>> */
    public function assets(int $ticketId): array
    {
        return $this->fetchAll(
            'SELECT ta.*, a.inventory_number, a.name AS asset_name, a.serial_number, ar.name AS article_name, m.name AS manufacturer_name,
                    ty.name AS asset_type_name, ty.icon AS asset_type_icon, st.name AS asset_status_name, st.color AS asset_status_color,
                    e.display_name AS employee_name, loc.full_path AS location_path
             FROM ticket_assets ta
             JOIN assets a ON a.id = ta.asset_id
             JOIN asset_types ty ON ty.id = a.asset_type_id
             JOIN asset_statuses st ON st.id = a.status_id
             LEFT JOIN articles ar ON ar.id = a.article_id
             LEFT JOIN manufacturers m ON m.id = a.manufacturer_id
             LEFT JOIN employees e ON e.id = a.employee_id
             LEFT JOIN locations loc ON loc.id = a.location_id
             WHERE ta.ticket_id = :tid ORDER BY ta.created_at, a.inventory_number',
            ['tid' => $ticketId]
        );
    }

    public function hasAsset(int $ticketId, int $assetId): bool
    {
        return $this->fetchValue('SELECT 1 FROM ticket_assets WHERE ticket_id = :t AND asset_id = :a', ['t' => $ticketId, 'a' => $assetId]) !== null;
    }

    public function addAsset(int $ticketId, int $assetId, ?string $note, ?int $userId): void
    {
        $this->execute('INSERT IGNORE INTO ticket_assets (ticket_id, asset_id, note, added_by) VALUES (:t, :a, :n, :u)', ['t' => $ticketId, 'a' => $assetId, 'n' => $note, 'u' => $userId]);
    }

    public function removeAsset(int $ticketId, int $assetId): int
    {
        return $this->execute('DELETE FROM ticket_assets WHERE ticket_id = :t AND asset_id = :a', ['t' => $ticketId, 'a' => $assetId]);
    }

    public function moveAssets(int $fromTicketId, int $toTicketId): void
    {
        $this->execute('INSERT IGNORE INTO ticket_assets (ticket_id, asset_id, note, added_by, created_at) SELECT :to, asset_id, note, added_by, created_at FROM ticket_assets WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
    }

    // ------------------------------------------------------------------ Watcher

    /** @return array<int,array<string,mixed>> */
    public function watchers(int $ticketId): array
    {
        return $this->fetchAll('SELECT w.*, u.display_name, u.email FROM ticket_watchers w JOIN users u ON u.id = w.user_id WHERE w.ticket_id = :t ORDER BY u.display_name', ['t' => $ticketId]);
    }

    public function isWatching(int $ticketId, int $userId): bool
    {
        return $this->fetchValue('SELECT 1 FROM ticket_watchers WHERE ticket_id = :t AND user_id = :u', ['t' => $ticketId, 'u' => $userId]) !== null;
    }

    public function addWatcher(int $ticketId, int $userId): void
    {
        $this->execute('INSERT IGNORE INTO ticket_watchers (ticket_id, user_id) VALUES (:t, :u)', ['t' => $ticketId, 'u' => $userId]);
    }

    public function removeWatcher(int $ticketId, int $userId): int
    {
        return $this->execute('DELETE FROM ticket_watchers WHERE ticket_id = :t AND user_id = :u', ['t' => $ticketId, 'u' => $userId]);
    }

    public function moveWatchers(int $fromTicketId, int $toTicketId): void
    {
        $this->execute('INSERT IGNORE INTO ticket_watchers (ticket_id, user_id, created_at) SELECT :to, user_id, created_at FROM ticket_watchers WHERE ticket_id = :from', ['to' => $toTicketId, 'from' => $fromTicketId]);
    }

    // ------------------------------------------------------------------ Verlauf

    /**
     * @param array<string,mixed>|null $payload
     */
    public function addEvent(int $ticketId, string $type, ?int $userId, string $userName, ?string $field = null, ?string $old = null, ?string $new = null, ?array $payload = null): int
    {
        return $this->insertRow('ticket_events', [
            'ticket_id' => $ticketId,
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
    public function events(int $ticketId): array
    {
        return $this->fetchAll('SELECT * FROM ticket_events WHERE ticket_id = :t ORDER BY created_at, id', ['t' => $ticketId]);
    }

    /** Ticket-IDs, die auf ein Ticket zusammengeführt wurden. @return array<int,array<string,mixed>> */
    public function mergedInto(int $ticketId): array
    {
        return $this->fetchAll('SELECT t.id, t.number, t.subject, t.created_at FROM tickets t WHERE t.merged_into_ticket_id = :t ORDER BY t.number', ['t' => $ticketId]);
    }

    // ------------------------------------------------------------------ Scheduler

    /**
     * Offene Tickets mit SLA für die Eskalationsprüfung (nicht pausiert, nicht zusammengeführt).
     * @return array<int,array<string,mixed>>
     */
    public function openWithSla(int $limit = 500): array
    {
        return $this->fetchAll(self::SELECT . " WHERE t.sla_id IS NOT NULL AND t.merged_into_ticket_id IS NULL AND st.category IN ('new','open','pending') AND t.sla_paused_at IS NULL ORDER BY t.resolution_due_at LIMIT {$limit}");
    }

    /**
     * Offene Tickets, die nicht innerhalb von [dayStartUtc, dayEndUtc) erstellt wurden (2nd-Level-Übernahme:
     * alle offenen Tickets, die nicht vom aktuellen Tag stammen, werden dem neuen 2nd Level zugewiesen).
     * @return array<int,array<string,mixed>>
     */
    public function openNotCreatedBetween(string $dayStartUtc, string $dayEndUtc, int $limit = 2000): array
    {
        return $this->fetchAll(self::SELECT . " WHERE t.merged_into_ticket_id IS NULL AND st.category IN ('new','open','pending') AND (t.created_at < :start OR t.created_at >= :end) ORDER BY t.created_at LIMIT {$limit}", ['start' => $dayStartUtc, 'end' => $dayEndUtc]);
    }

    /**
     * Offene Tickets, die vor $dayStartUtc erstellt wurden (2nd-Level-Erinnerung: alle offenen Tickets,
     * die nicht vom betreffenden Tag stammen). @return array<int,array<string,mixed>>
     */
    public function openCreatedBefore(string $dayStartUtc, int $limit = 2000): array
    {
        return $this->fetchAll(self::SELECT . " WHERE t.merged_into_ticket_id IS NULL AND st.category IN ('new','open','pending') AND t.created_at < :start ORDER BY t.created_at LIMIT {$limit}", ['start' => $dayStartUtc]);
    }

    /** Gelöste Tickets, deren Lösung länger als n Tage zurückliegt (automatisches Schließen). @return array<int,array<string,mixed>> */
    public function resolvedOlderThan(int $days, ?string $now = null, int $limit = 200): array
    {
        $reference = $now ?? gmdate('Y-m-d H:i:s');

        return $this->fetchAll(self::SELECT . " WHERE st.category = 'resolved' AND t.merged_into_ticket_id IS NULL AND t.resolved_at IS NOT NULL AND t.resolved_at < DATE_SUB(:now, INTERVAL {$days} DAY) ORDER BY t.resolved_at LIMIT {$limit}", ['now' => $reference]);
    }

    // ------------------------------------------------------------------ Dashboard

    /** Kennzahlen für das Help-Desk-Dashboard. @return array<string,int> */
    public function dashboardCounts(int $userId): array
    {
        $row = $this->fetchOne(
            "SELECT
                SUM(st.category = 'new') AS new_count,
                SUM(st.category IN ('new','open','pending')) AS open_count,
                SUM(st.code = 'in_progress') AS in_progress,
                SUM(st.code = 'waiting_user') AS waiting_user,
                SUM(p.code = 'critical' AND st.category IN ('new','open','pending')) AS critical,
                SUM(st.category IN ('new','open','pending') AND t.sla_paused_at IS NULL AND ((t.resolution_due_at IS NOT NULL AND t.resolution_due_at < UTC_TIMESTAMP()) OR t.sla_resolution_state = 'breached')) AS overdue,
                SUM(t.assignee_user_id = :uid AND st.category IN ('new','open','pending')) AS mine,
                SUM(t.assignee_user_id IS NULL AND st.category IN ('new','open','pending')) AS unassigned,
                SUM(DATE(t.created_at) = UTC_DATE()) AS created_today,
                SUM(DATE(t.resolved_at) = UTC_DATE()) AS resolved_today,
                SUM(t.sla_resolution_state = 'breached' OR t.sla_response_state = 'breached') AS sla_breached
             FROM tickets t
             JOIN ticket_statuses st ON st.id = t.status_id
             JOIN ticket_priorities p ON p.id = t.priority_id
             WHERE t.merged_into_ticket_id IS NULL",
            ['uid' => $userId]
        ) ?? [];

        return array_map('intval', array_map(static fn ($v) => $v ?? 0, $row));
    }

    /** Anzahl offener Tickets (Navigations-Badge). */
    public function openCount(): int
    {
        return (int) $this->fetchValue("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses st ON st.id = t.status_id WHERE t.merged_into_ticket_id IS NULL AND st.category IN ('new','open','pending')");
    }

    /** Offene Tickets, die dem Benutzer zugewiesen sind oder ihn als Melder haben (Portal-Badge). */
    public function openCountForUser(int $userId, bool $asAgent): int
    {
        if ($asAgent) {
            return (int) $this->fetchValue("SELECT COUNT(*) FROM tickets t JOIN ticket_statuses st ON st.id = t.status_id WHERE t.merged_into_ticket_id IS NULL AND st.category IN ('new','open','pending') AND t.assignee_user_id = :u", ['u' => $userId]);
        }

        return $this->countSearch(['view' => 'portal_open', 'visible_user_id' => $userId]);
    }
}
