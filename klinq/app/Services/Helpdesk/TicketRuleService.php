<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Logger;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\UserRepository;

/**
 * Regelengine: wendet aktive Regeln eines Auslösers auf ein Ticket an (Routing, Priorität, Tags, Benachrichtigungen).
 * Regeln laufen nach der eigentlichen Änderung; Fehler in einer Regel brechen den Ticketvorgang nicht ab.
 */
final class TicketRuleService
{
    public function __construct(
        private readonly TicketRuleRepository $rules,
        private readonly TicketRuleEvaluator $evaluator,
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly TicketTagRepository $tags,
        private readonly TicketSlaRepository $slas,
        private readonly UserRepository $users,
        private readonly TicketNotificationService $notifications,
        private readonly Logger $logger
    ) {}

    /**
     * Wendet alle aktiven Regeln des Auslösers an und liefert die Namen der ausgelösten Regeln.
     * @param array<string,mixed> $ticket vollständiger Datensatz (TicketRepository::find)
     * @return array<int,string>
     */
    public function apply(string $trigger, array $ticket, ?int $userId, string $userName): array
    {
        $fired = [];
        foreach ($this->rules->activeFor($trigger) as $rule) {
            try {
                $conditions = json_decode((string) $rule['conditions'], true);
                $actions = json_decode((string) $rule['actions'], true);
                if (!is_array($conditions) || !is_array($actions)) {
                    continue;
                }
                if (!$this->evaluator->matches($conditions, $this->context($ticket))) {
                    continue;
                }
                $changes = $this->execute($actions, $ticket, $rule, $userId, $userName);
                $fired[] = (string) $rule['name'];
                $this->tickets->addEvent((int) $ticket['id'], 'rule_applied', $userId, $userName, null, null, (string) $rule['name'], $changes);
                if ($changes !== []) {
                    $ticket = $this->tickets->find((int) $ticket['id']) ?? $ticket;
                }
                if (!empty($rule['stop_processing'])) {
                    break;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Ticketregel fehlgeschlagen', ['rule' => $rule['name'], 'ticket' => $ticket['number'] ?? '', 'error' => $e->getMessage()]);
            }
        }

        return $fired;
    }

    /** Kontextwerte für die Bedingungsauswertung. @param array<string,mixed> $ticket @return array<string,mixed> */
    public function context(array $ticket): array
    {
        return [
            'priority_code' => $ticket['priority_code'] ?? '',
            'priority_level' => $ticket['priority_level'] ?? '',
            'type_code' => $ticket['type_code'] ?? '',
            'status_code' => $ticket['status_code'] ?? '',
            'category_name' => $ticket['category_name'] ?? '',
            'subcategory_name' => $ticket['subcategory_name'] ?? '',
            'group_name' => $ticket['group_name'] ?? '',
            'subject' => $ticket['subject'] ?? '',
            'description' => $ticket['description'] ?? '',
            'source' => $ticket['source'] ?? '',
            'tag' => array_values(array_filter(explode(',', (string) ($ticket['tag_names'] ?? '')))),
            'impact' => $ticket['impact'] ?? '',
            'urgency' => $ticket['urgency'] ?? '',
            'assignee' => $ticket['assignee_name'] ?? '',
            'requester_email' => $ticket['requester_email'] ?? '',
            'location_name' => $ticket['location_name'] ?? '',
        ];
    }

    /**
     * @param array<string,mixed> $actions
     * @param array<string,mixed> $ticket
     * @param array<string,mixed> $rule
     * @return array<string,mixed> durchgeführte Änderungen
     */
    private function execute(array $actions, array $ticket, array $rule, ?int $userId, string $userName): array
    {
        $ticketId = (int) $ticket['id'];
        $update = [];
        $changes = [];

        if (!empty($actions['set_group'])) {
            $group = is_numeric($actions['set_group']) ? $this->masterData->findGroup((int) $actions['set_group']) : $this->masterData->groupByName((string) $actions['set_group']);
            if ($group !== null && (int) $group['id'] !== (int) ($ticket['group_id'] ?? 0)) {
                $update['group_id'] = (int) $group['id'];
                $changes['group'] = $group['name'];
            }
        }
        if (!empty($actions['set_priority'])) {
            $priority = is_numeric($actions['set_priority']) ? $this->masterData->findPriority((int) $actions['set_priority']) : $this->masterData->priorityByCode((string) $actions['set_priority']);
            if ($priority !== null && (int) $priority['id'] !== (int) $ticket['priority_id']) {
                $update['priority_id'] = (int) $priority['id'];
                $changes['priority'] = $priority['name'];
            }
        }
        if (!empty($actions['set_assignee'])) {
            $user = is_numeric($actions['set_assignee']) ? $this->users->find((int) $actions['set_assignee']) : $this->users->findByUsername((string) $actions['set_assignee']);
            if ($user !== null && !empty($user['is_active']) && (int) $user['id'] !== (int) ($ticket['assignee_user_id'] ?? 0)) {
                $update['assignee_user_id'] = (int) $user['id'];
                $changes['assignee'] = $user['display_name'] ?? $user['username'];
            }
        }
        if (!empty($actions['set_sla'])) {
            $sla = is_numeric($actions['set_sla']) ? $this->slas->find((int) $actions['set_sla']) : $this->slas->findByName((string) $actions['set_sla']);
            if ($sla !== null && (int) $sla['id'] !== (int) ($ticket['sla_id'] ?? 0)) {
                $update['sla_id'] = (int) $sla['id'];
                $changes['sla'] = $sla['name'];
            }
        }
        if (!empty($actions['set_status'])) {
            $status = $this->masterData->statusByCode((string) $actions['set_status']);
            if ($status !== null && (int) $status['id'] !== (int) $ticket['status_id'] && !in_array((string) $status['code'], TicketWorkflowService::FINAL, true)) {
                $update['status_id'] = (int) $status['id'];
                $changes['status'] = $status['name'];
            }
        }
        if (!empty($actions['add_tags'])) {
            $added = [];
            foreach ((array) $actions['add_tags'] as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $this->tags->attach($ticketId, $this->tags->ensure($name));
                    $added[] = $name;
                }
            }
            if ($added !== []) {
                $changes['tags'] = $added;
            }
        }
        if ($update !== []) {
            $update['updated_by'] = $userId;
            $this->tickets->update($ticketId, $update);
        }

        $recipients = [];
        if (!empty($actions['notify_group'])) {
            $groupId = (int) ($update['group_id'] ?? $ticket['group_id'] ?? 0);
            if ($groupId > 0) {
                $recipients = [...$recipients, ...$this->notifications->groupRecipients($groupId)];
            }
        }
        if (!empty($actions['notify_emails'])) {
            $recipients = [...$recipients, ...array_map('strval', (array) $actions['notify_emails'])];
        }
        if ($recipients !== []) {
            $fresh = $this->tickets->find($ticketId) ?? $ticket;
            $this->notifications->ruleNotification($fresh, (string) $rule['name'], $recipients);
            $changes['notified'] = count(array_unique($recipients));
        }

        return $changes;
    }
}
