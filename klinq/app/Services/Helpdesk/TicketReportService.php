<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketReportRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Support\CsvWriter;

/** Berichte und Kennzahlen des Help Desks inkl. CSV-Export. */
final class TicketReportService
{
    public function __construct(
        private readonly TicketReportRepository $reports,
        private readonly TicketRepository $tickets,
        private readonly TicketRelationRepository $relations,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * Zeitraum aus Eingaben ableiten (Standard: letzte 30 Tage), Format Y-m-d.
     * @return array{0:string,1:string}
     */
    public static function range(?string $from, ?string $to): array
    {
        $today = gmdate('Y-m-d');
        $toDate = self::validDate($to) ?? $today;
        $fromDate = self::validDate($from) ?? gmdate('Y-m-d', strtotime($toDate . ' -29 days') ?: time());
        if ($fromDate > $toDate) {
            [$fromDate, $toDate] = [$toDate, $fromDate];
        }

        return [$fromDate, $toDate];
    }

    private static function validDate(?string $value): ?string
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    /** Alle Auswertungen für die Berichtsseite. @return array<string,mixed> */
    public function overview(string $from, string $to): array
    {
        $this->currentUser->require('helpdesk.reports');

        return [
            'from' => $from,
            'to' => $to,
            'per_day' => $this->reports->perDay($from, $to),
            'by_status' => $this->reports->byStatus($from, $to),
            'by_priority' => $this->reports->byPriority($from, $to),
            'by_type' => $this->reports->byType($from, $to),
            'by_category' => $this->reports->byCategory($from, $to),
            'by_assignee' => $this->reports->byAssignee($from, $to),
            'by_group' => $this->reports->byGroup($from, $to),
            'by_requester' => $this->reports->byRequester($from, $to),
            'sla' => $this->reports->slaSummary($from, $to),
            'sla_breaches' => $this->reports->slaBreaches($from, $to, 50),
            'worklog' => $this->reports->worklogByUser($from, $to),
            'recurring_assets' => $this->reports->recurringByAsset($from, $to),
            'recurring_relations' => $this->relations->recurring(),
        ];
    }

    /** Kennzahlen für das Dashboard. @return array<string,mixed> */
    public function dashboard(): array
    {
        $userId = (int) ($this->currentUser->id() ?? 0);
        $from = gmdate('Y-m-d', strtotime('-13 days') ?: time());
        $to = gmdate('Y-m-d');

        return [
            'counts' => $this->tickets->dashboardCounts($userId),
            'per_day' => $this->reports->perDay($from, $to),
            'by_priority' => $this->reports->byPriority($from, $to),
            'by_group' => $this->reports->byGroup($from, $to),
            'sla' => $this->reports->slaSummary($from, $to),
            'mine' => $this->tickets->search(['view' => 'mine_open', 'user_id' => $userId], 'resolution_due_at', 'asc', 8, 0),
            'unassigned' => $this->tickets->search(['view' => 'unassigned'], 'created_at', 'desc', 8, 0),
            'overdue' => $this->tickets->search(['view' => 'overdue'], 'resolution_due_at', 'asc', 8, 0),
            'recent' => $this->tickets->search(['view' => 'all'], 'updated_at', 'desc', 8, 0),
        ];
    }

    /** CSV-Export aller Tickets im Zeitraum. */
    public function exportCsv(string $from, string $to): string
    {
        $this->currentUser->require('helpdesk.export');
        $headers = ['Nummer', 'Betreff', 'Typ', 'Status', 'Priorität', 'Kategorie', 'Unterkategorie', 'Gruppe', 'Bearbeiter', 'Melder', 'Erstellt (UTC)', 'Erste Reaktion (UTC)', 'Gelöst (UTC)', 'Geschlossen (UTC)', 'Reaktion fällig (UTC)', 'Lösung fällig (UTC)', 'SLA Reaktion', 'SLA Lösung', 'Eskalationsstufe', 'Wiedereröffnet', 'Arbeitszeit (min)'];
        $rows = array_map(static fn (array $r): array => [
            $r['number'], $r['subject'], $r['type_name'], $r['status_name'], $r['priority_name'], $r['category_name'], $r['subcategory_name'], $r['group_name'], $r['assignee_name'], $r['requester_name'],
            $r['created_at'], $r['first_response_at'], $r['resolved_at'], $r['closed_at'], $r['response_due_at'], $r['resolution_due_at'], $r['sla_response_state'], $r['sla_resolution_state'], $r['escalation_level'], $r['reopen_count'], $r['worklog_minutes'],
        ], $this->reports->exportRows($from, $to));

        return CsvWriter::build($headers, $rows);
    }

    /** CSV-Export einer gefilterten Ticketliste. @param array<string,mixed> $filters */
    public function exportListCsv(array $filters, string $sort, string $dir): string
    {
        $this->currentUser->require('helpdesk.export');
        $headers = ['Nummer', 'Betreff', 'Typ', 'Status', 'Priorität', 'Kategorie', 'Unterkategorie', 'Gruppe', 'Bearbeiter', 'Melder', 'Tags', 'Erstellt (UTC)', 'Aktualisiert (UTC)', 'Lösung fällig (UTC)', 'SLA Lösung'];
        $rows = array_map(static fn (array $t): array => [
            $t['number'], $t['subject'], $t['type_name'], $t['status_name'], $t['priority_name'], $t['category_name'], $t['subcategory_name'], $t['group_name'], $t['assignee_name'], $t['requester_name'] ?? $t['requester_user_name'], $t['tag_names'],
            $t['created_at'], $t['updated_at'], $t['resolution_due_at'], $t['sla_resolution_state'],
        ], $this->tickets->search($filters, $sort, $dir, 5000, 0));

        return CsvWriter::build($headers, $rows);
    }
}
