<?php

declare(strict_types=1);

namespace App\Repositories;

/** Aggregierte Auswertungen für Help-Desk-Berichte und Dashboard. Alle Zeiträume in UTC. */
final class TicketReportRepository extends BaseRepository
{
    private const BASE = 'FROM tickets t
        JOIN ticket_statuses st ON st.id = t.status_id
        JOIN ticket_priorities p ON p.id = t.priority_id
        JOIN ticket_types ty ON ty.id = t.ticket_type_id
        LEFT JOIN ticket_categories c ON c.id = t.category_id
        LEFT JOIN ticket_groups g ON g.id = t.group_id
        LEFT JOIN users au ON au.id = t.assignee_user_id
        LEFT JOIN employees req ON req.id = t.requester_employee_id
        WHERE t.merged_into_ticket_id IS NULL AND t.created_at >= :from AND t.created_at < :to';

    /** @return array<string,mixed> */
    private function range(string $from, string $to): array
    {
        return ['from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59'];
    }

    /** Tickets pro Tag (erstellt/gelöst). @return array<int,array<string,mixed>> */
    public function perDay(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT d.day, COALESCE(cr.cnt, 0) AS created_count, COALESCE(rs.cnt, 0) AS resolved_count FROM (
                SELECT DATE(created_at) AS day FROM tickets WHERE created_at >= :from AND created_at < :to
                UNION SELECT DATE(resolved_at) FROM tickets WHERE resolved_at >= :from AND resolved_at < :to
             ) d
             LEFT JOIN (SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM tickets WHERE merged_into_ticket_id IS NULL AND created_at >= :from AND created_at < :to GROUP BY DATE(created_at)) cr ON cr.day = d.day
             LEFT JOIN (SELECT DATE(resolved_at) AS day, COUNT(*) AS cnt FROM tickets WHERE merged_into_ticket_id IS NULL AND resolved_at >= :from AND resolved_at < :to GROUP BY DATE(resolved_at)) rs ON rs.day = d.day
             ORDER BY d.day',
            $this->range($from, $to)
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function byStatus(string $from, string $to): array
    {
        return $this->fetchAll('SELECT st.name AS label, st.color, COUNT(*) AS cnt ' . self::BASE . ' GROUP BY st.id, st.name, st.color, st.sort_order ORDER BY st.sort_order', $this->range($from, $to));
    }

    /** @return array<int,array<string,mixed>> */
    public function byPriority(string $from, string $to): array
    {
        return $this->fetchAll('SELECT p.name AS label, p.color, COUNT(*) AS cnt ' . self::BASE . ' GROUP BY p.id, p.name, p.color, p.level ORDER BY p.level DESC', $this->range($from, $to));
    }

    /** @return array<int,array<string,mixed>> */
    public function byType(string $from, string $to): array
    {
        return $this->fetchAll('SELECT ty.name AS label, ty.color, COUNT(*) AS cnt ' . self::BASE . ' GROUP BY ty.id, ty.name, ty.color, ty.sort_order ORDER BY ty.sort_order', $this->range($from, $to));
    }

    /** @return array<int,array<string,mixed>> */
    public function byCategory(string $from, string $to): array
    {
        return $this->fetchAll('SELECT COALESCE(c.name, \'– ohne Kategorie –\') AS label, COUNT(*) AS cnt ' . self::BASE . ' GROUP BY c.id, c.name ORDER BY cnt DESC, label', $this->range($from, $to));
    }

    /** @return array<int,array<string,mixed>> */
    public function byAssignee(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT COALESCE(au.display_name, \'– nicht zugewiesen –\') AS label, COUNT(*) AS cnt,
                    SUM(st.category IN (\'resolved\',\'closed\')) AS resolved_count,
                    ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END)) AS avg_resolution_minutes
             ' . self::BASE . ' GROUP BY au.id, au.display_name ORDER BY cnt DESC, label',
            $this->range($from, $to)
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function byGroup(string $from, string $to): array
    {
        return $this->fetchAll('SELECT COALESCE(g.name, \'– ohne Gruppe –\') AS label, COUNT(*) AS cnt, SUM(st.category IN (\'resolved\',\'closed\')) AS resolved_count ' . self::BASE . ' GROUP BY g.id, g.name ORDER BY cnt DESC, label', $this->range($from, $to));
    }

    /** Tickets pro Melder (Mitarbeiter). @return array<int,array<string,mixed>> */
    public function byRequester(string $from, string $to, int $limit = 25): array
    {
        return $this->fetchAll('SELECT COALESCE(req.display_name, t.created_by_name) AS label, req.department, COUNT(*) AS cnt ' . self::BASE . " GROUP BY req.id, req.display_name, req.department, t.created_by_name ORDER BY cnt DESC, label LIMIT {$limit}", $this->range($from, $to));
    }

    /**
     * Durchschnittliche Reaktions-/Lösungszeit in Minuten sowie SLA-Erfüllung.
     * @return array<string,int|float|null>
     */
    public function slaSummary(string $from, string $to): array
    {
        $row = $this->fetchOne(
            'SELECT COUNT(*) AS total,
                    ROUND(AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at) END)) AS avg_response_minutes,
                    ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END)) AS avg_resolution_minutes,
                    SUM(t.sla_id IS NOT NULL) AS with_sla,
                    SUM(t.sla_response_state = \'met\') AS response_met,
                    SUM(t.sla_response_state = \'breached\') AS response_breached,
                    SUM(t.sla_resolution_state = \'met\') AS resolution_met,
                    SUM(t.sla_resolution_state = \'breached\') AS resolution_breached,
                    SUM(t.escalation_level > 0) AS escalated,
                    SUM(t.reopen_count > 0) AS reopened
             ' . self::BASE,
            $this->range($from, $to)
        ) ?? [];

        return array_map(static fn ($v) => $v === null ? null : (int) $v, $row);
    }

    /** SLA-Verletzungen im Zeitraum (Detailliste). @return array<int,array<string,mixed>> */
    public function slaBreaches(string $from, string $to, int $limit = 200): array
    {
        return $this->fetchAll(
            'SELECT t.id, t.number, t.subject, t.created_at, t.response_due_at, t.resolution_due_at, t.first_response_at, t.resolved_at,
                    t.sla_response_state, t.sla_resolution_state, t.escalation_level, p.name AS priority_name, p.color AS priority_color,
                    st.name AS status_name, st.color AS status_color, au.display_name AS assignee_name, g.name AS group_name
             ' . self::BASE . " AND (t.sla_response_state = 'breached' OR t.sla_resolution_state = 'breached') ORDER BY t.created_at DESC LIMIT {$limit}",
            $this->range($from, $to)
        );
    }

    /** Arbeitszeit je Bearbeiter im Zeitraum. @return array<int,array<string,mixed>> */
    public function worklogByUser(string $from, string $to): array
    {
        return $this->fetchAll(
            'SELECT w.user_name AS label, COUNT(*) AS entries, SUM(w.minutes) AS minutes, COUNT(DISTINCT w.ticket_id) AS tickets
             FROM ticket_worklogs w WHERE COALESCE(w.started_at, w.created_at) >= :from AND COALESCE(w.started_at, w.created_at) < :to
             GROUP BY w.user_id, w.user_name ORDER BY minutes DESC',
            $this->range($from, $to)
        );
    }

    /** Wiederkehrende Störungen: gleiche Unterkategorie + gleiches Asset mehrfach. @return array<int,array<string,mixed>> */
    public function recurringByAsset(string $from, string $to, int $limit = 20): array
    {
        return $this->fetchAll(
            'SELECT a.id AS asset_id, a.inventory_number, a.name AS asset_name, COUNT(DISTINCT t.id) AS cnt
             FROM ticket_assets ta JOIN tickets t ON t.id = ta.ticket_id JOIN assets a ON a.id = ta.asset_id
             WHERE t.merged_into_ticket_id IS NULL AND t.created_at >= :from AND t.created_at < :to
             GROUP BY a.id, a.inventory_number, a.name HAVING cnt > 1 ORDER BY cnt DESC, a.inventory_number LIMIT ' . $limit,
            $this->range($from, $to)
        );
    }

    /** Detailzeilen für CSV-Export. @return array<int,array<string,mixed>> */
    public function exportRows(string $from, string $to, int $limit = 5000): array
    {
        return $this->fetchAll(
            'SELECT t.number, t.subject, ty.name AS type_name, st.name AS status_name, p.name AS priority_name, c.name AS category_name,
                    sc.name AS subcategory_name, g.name AS group_name, au.display_name AS assignee_name, req.display_name AS requester_name,
                    t.created_at, t.first_response_at, t.resolved_at, t.closed_at, t.response_due_at, t.resolution_due_at,
                    t.sla_response_state, t.sla_resolution_state, t.escalation_level, t.reopen_count,
                    (SELECT COALESCE(SUM(w.minutes), 0) FROM ticket_worklogs w WHERE w.ticket_id = t.id) AS worklog_minutes
             FROM tickets t
             JOIN ticket_statuses st ON st.id = t.status_id
             JOIN ticket_priorities p ON p.id = t.priority_id
             JOIN ticket_types ty ON ty.id = t.ticket_type_id
             LEFT JOIN ticket_categories c ON c.id = t.category_id
             LEFT JOIN ticket_categories sc ON sc.id = t.subcategory_id
             LEFT JOIN ticket_groups g ON g.id = t.group_id
             LEFT JOIN users au ON au.id = t.assignee_user_id
             LEFT JOIN employees req ON req.id = t.requester_employee_id
             WHERE t.merged_into_ticket_id IS NULL AND t.created_at >= :from AND t.created_at < :to
             ORDER BY t.created_at LIMIT ' . $limit,
            $this->range($from, $to)
        );
    }
}
