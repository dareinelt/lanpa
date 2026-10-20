<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketSlaRepository;

/**
 * Zeitgesteuerte Verarbeitung (bin/helpdesk.php process):
 *  - SLA-Zustände bewerten (ok → warning → breached) und Warn-/Verletzungsmails senden
 *  - Eskalation an Eskalationsgruppe bzw. Gruppenleitung bei Schwellenüberschreitung
 *  - Gelöste Tickets nach Frist automatisch schließen
 *  - Tagesweise 1st-/2nd-Level-Zuständigkeit automatisch beenden (täglich 19:00 Uhr, APP_TIMEZONE)
 *  - 2nd-Level-Tagesabschluss (23:59 Uhr): „kein Kommentar erfolgt“-Kommentare für offene Tickets
 * Läuft ohne angemeldeten Benutzer; alle Aktionen werden als „System“ protokolliert.
 */
final class HelpdeskSchedulerService
{
    private const SYSTEM_USER = 'System';

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketSlaRepository $slas,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketSlaService $sla,
        private readonly TicketNotificationService $notifications,
        private readonly TicketRuleService $rules,
        private readonly SupportShiftService $supportShifts,
        private readonly SecondLevelReminderService $reminders,
        private readonly Config $config,
        private readonly Logger $logger
    ) {}

    /** Führt alle Schritte aus und liefert eine Zusammenfassung. @return array<string,int> */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $summary = ['checked' => 0, 'warnings' => 0, 'breaches' => 0, 'escalated' => 0, 'auto_closed' => 0, 'shifts_ended' => 0, 'reminders_created' => 0];
        $summary = $this->evaluateSla($now, $summary);
        $summary['auto_closed'] = $this->autoClose($now);
        $summary['shifts_ended'] = $this->supportShifts->endExpiredShifts($now);
        $summary['reminders_created'] = $this->reminders->createMissedComments($now);
        $this->logger->info('Help-Desk-Scheduler ausgeführt', $summary);

        return $summary;
    }

    /** @param array<string,int> $summary @return array<string,int> */
    private function evaluateSla(\DateTimeImmutable $now, array $summary): array
    {
        $escalationEnabled = (bool) $this->config->get('helpdesk.escalation_enabled', true);
        $slaCache = [];
        foreach ($this->tickets->openWithSla() as $ticket) {
            $summary['checked']++;
            $slaId = (int) $ticket['sla_id'];
            $slaCache[$slaId] ??= $this->slas->find($slaId);
            $rule = $slaCache[$slaId];
            if ($rule === null) {
                continue;
            }
            $warning = $this->sla->warningPercent($rule);
            $update = [];
            $events = [];

            // Reaktionszeit (nur bis zur ersten Reaktion relevant)
            if ($ticket['first_response_at'] === null && $ticket['response_due_at'] !== null) {
                $state = $this->sla->evaluate($ticket['response_due_at'], null, $now, $ticket['created_at'], $warning);
                if ($state !== $ticket['sla_response_state']) {
                    $update['sla_response_state'] = $state;
                    $events[] = ['response', $state];
                }
            }
            // Lösungszeit
            if ($ticket['resolution_due_at'] !== null) {
                $state = $this->sla->evaluate($ticket['resolution_due_at'], null, $now, $ticket['created_at'], $warning);
                if ($state !== $ticket['sla_resolution_state']) {
                    $update['sla_resolution_state'] = $state;
                    $events[] = ['resolution', $state];
                }
            }

            if ($update !== []) {
                $this->tickets->update((int) $ticket['id'], $update);
                $fresh = $this->tickets->find((int) $ticket['id']) ?? $ticket;
                foreach ($events as [$target, $state]) {
                    $this->tickets->addEvent((int) $ticket['id'], 'sla_' . $state, null, self::SYSTEM_USER, 'sla_' . $target, (string) $ticket['sla_' . $target . '_state'], $state);
                    if ($state === TicketSlaService::STATE_WARNING) {
                        $summary['warnings']++;
                        $this->notifications->slaWarning($fresh, $target);
                        $this->rules->apply('sla_warning', $fresh, null, self::SYSTEM_USER);
                    } elseif ($state === TicketSlaService::STATE_BREACHED) {
                        $summary['breaches']++;
                        $this->notifications->slaBreached($fresh, $target);
                        $this->rules->apply('sla_breached', $fresh, null, self::SYSTEM_USER);
                    }
                }
                $ticket = $fresh;
            }

            if ($escalationEnabled && $this->escalate($ticket, $rule, $now)) {
                $summary['escalated']++;
            }
        }

        return $summary;
    }

    /**
     * Eskalationsstufen: 1 = Eskalationsschwelle der Lösungszeit erreicht, 2 = Lösungszeit verletzt.
     * @param array<string,mixed> $ticket @param array<string,mixed> $rule
     */
    private function escalate(array $ticket, array $rule, \DateTimeImmutable $now): bool
    {
        if ($ticket['resolution_due_at'] === null) {
            return false;
        }
        $percent = $this->sla->consumedPercent($ticket['created_at'], $ticket['resolution_due_at'], $now);
        $level = 0;
        if ($percent !== null && $percent >= 100) {
            $level = 2;
        } elseif ($percent !== null && $percent >= $this->sla->escalationPercent($rule)) {
            $level = 1;
        }
        if ($level <= (int) $ticket['escalation_level']) {
            return false;
        }

        $recipients = [];
        $groupId = !empty($rule['escalation_group_id']) ? (int) $rule['escalation_group_id'] : (!empty($ticket['group_id']) ? (int) $ticket['group_id'] : null);
        if ($groupId !== null) {
            $members = $this->masterData->groupMembers($groupId);
            $leads = array_filter($members, static fn (array $m): bool => !empty($m['is_lead']));
            foreach ($leads !== [] && $level === 1 ? $leads : $members as $member) {
                $recipients[] = (string) ($member['email'] ?? '');
            }
        }
        if (!empty($ticket['assignee_email'])) {
            $recipients[] = (string) $ticket['assignee_email'];
        }

        $this->tickets->update((int) $ticket['id'], ['escalation_level' => $level, 'escalated_at' => $now->format('Y-m-d H:i:s')]);
        $this->tickets->addEvent((int) $ticket['id'], 'escalated', null, self::SYSTEM_USER, 'escalation_level', (string) $ticket['escalation_level'], (string) $level, ['percent' => $percent]);
        $fresh = $this->tickets->find((int) $ticket['id']) ?? $ticket;
        $this->notifications->escalated($fresh, $level, $recipients);

        return true;
    }

    /** Gelöste Tickets nach auto_close_days automatisch schließen. */
    private function autoClose(\DateTimeImmutable $now): int
    {
        $days = (int) $this->config->get('helpdesk.auto_close_days', 7);
        if ($days <= 0) {
            return 0;
        }
        $closed = $this->masterData->statusByCode(TicketWorkflowService::CLOSED);
        if ($closed === null) {
            return 0;
        }
        $count = 0;
        foreach ($this->tickets->resolvedOlderThan($days, $now->format('Y-m-d H:i:s')) as $ticket) {
            $this->tickets->update((int) $ticket['id'], [
                'status_id' => (int) $closed['id'],
                'closed_at' => $now->format('Y-m-d H:i:s'),
                'close_reason' => $ticket['close_reason'] ?? "Automatisch geschlossen nach {$days} Tagen ohne Rückmeldung",
            ]);
            $this->tickets->addEvent((int) $ticket['id'], 'closed', null, self::SYSTEM_USER, 'status', (string) $ticket['status_name'], (string) $closed['name'], ['auto' => true]);
            $fresh = $this->tickets->find((int) $ticket['id']) ?? $ticket;
            $this->notifications->statusChanged($fresh, TicketWorkflowService::RESOLVED, TicketWorkflowService::CLOSED);
            $count++;
        }

        return $count;
    }
}
