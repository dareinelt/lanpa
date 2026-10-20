<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\UserRepository;
use App\Services\MailClient;

/**
 * E-Mail-Benachrichtigungen des Ticketsystems (nur Versand über den Mail-Container).
 * Jede Kombination Ticket × Ereignis × Empfänger wird nur einmal versendet (ticket_notifications);
 * Fehler werden protokolliert und unterbrechen den Workflow nie.
 */
final class TicketNotificationService
{
    public function __construct(
        private readonly MailClient $mail,
        private readonly TicketRuleRepository $rules,
        private readonly TicketRepository $tickets,
        private readonly TicketMasterDataRepository $masterData,
        private readonly UserRepository $users,
        private readonly Config $config,
        private readonly Logger $logger
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('helpdesk.notifications_enabled', true) && $this->mail->enabled();
    }

    /** Ticket erstellt: Melder (Bestätigung), Gruppe und ggf. Bearbeiter. @param array<string,mixed> $ticket */
    public function ticketCreated(array $ticket): void
    {
        $this->sendTo($ticket, 'created', $this->requesterRecipients($ticket), 'Ihr Ticket wurde angelegt', $this->intro($ticket, 'Ihr Ticket wurde erfolgreich angelegt und wird vom IT Help Desk bearbeitet.'));
        $this->sendTo($ticket, 'created_team', $this->teamRecipients($ticket, true), 'Neues Ticket', $this->intro($ticket, 'Ein neues Ticket wurde eingereicht.'));
    }

    /** @param array<string,mixed> $ticket */
    public function assigned(array $ticket, ?string $previousAssigneeEmail = null): void
    {
        $email = trim((string) ($ticket['assignee_email'] ?? ''));
        if ($email === '' || $email === $previousAssigneeEmail) {
            return;
        }
        $key = 'assigned_' . (int) ($ticket['assignee_user_id'] ?? 0) . '_' . (int) ($ticket['version'] ?? 0);
        $this->sendTo($ticket, $key, [$email], 'Ticket zugewiesen', $this->intro($ticket, 'Ihnen wurde ein Ticket zugewiesen.'));
    }

    /** @param array<string,mixed> $ticket */
    public function statusChanged(array $ticket, string $fromCode, string $toCode): void
    {
        $key = 'status_' . $toCode . '_' . (int) ($ticket['version'] ?? 0);
        $message = match ($toCode) {
            TicketWorkflowService::RESOLVED => 'Ihr Ticket wurde gelöst. Sollte das Problem weiterhin bestehen, können Sie das Ticket innerhalb der Frist wieder öffnen.',
            TicketWorkflowService::CLOSED => 'Ihr Ticket wurde geschlossen.',
            TicketWorkflowService::CANCELLED => 'Ihr Ticket wurde storniert.',
            TicketWorkflowService::WAITING_USER => 'Zur weiteren Bearbeitung Ihres Tickets wird eine Rückmeldung von Ihnen benötigt.',
            default => 'Der Status Ihres Tickets hat sich geändert.',
        };
        $extra = '';
        if ($toCode === TicketWorkflowService::RESOLVED && !empty($ticket['resolution'])) {
            $extra = '<p><strong>Lösung:</strong><br>' . nl2br(self::e((string) $ticket['resolution'])) . '</p>';
        }
        $this->sendTo($ticket, $key, $this->requesterRecipients($ticket), 'Ticket ' . self::statusLabel($toCode), $this->intro($ticket, $message) . $extra);
        if (in_array($fromCode, [TicketWorkflowService::RESOLVED, TicketWorkflowService::CLOSED], true) && $toCode === TicketWorkflowService::IN_PROGRESS) {
            $this->sendTo($ticket, 'reopened_team_' . (int) ($ticket['reopen_count'] ?? 0), $this->teamRecipients($ticket, false), 'Ticket wieder geöffnet', $this->intro($ticket, 'Das Ticket wurde wieder geöffnet.'));
        }
    }

    /** Öffentlicher Kommentar: Melder (wenn vom Team) bzw. Team (wenn vom Melder). @param array<string,mixed> $ticket @param array<string,mixed> $comment */
    public function commentAdded(array $ticket, array $comment): void
    {
        if (($comment['type'] ?? 'public') !== 'public') {
            return;
        }
        $body = $this->intro($ticket, 'Es gibt eine neue Antwort zu Ihrem Ticket.') . '<blockquote>' . nl2br(self::e((string) $comment['body'])) . '</blockquote>';
        $key = 'comment_' . (int) $comment['id'];
        if (!empty($comment['is_requester'])) {
            $this->sendTo($ticket, $key, $this->teamRecipients($ticket, false), 'Neue Rückmeldung des Melders', $body);
        } else {
            $this->sendTo($ticket, $key, $this->requesterRecipients($ticket), 'Neue Antwort zu Ihrem Ticket', $body);
        }
        $this->sendTo($ticket, $key . '_watchers', $this->watcherRecipients($ticket, [(string) ($comment['author_user_id'] ?? '')]), 'Neuer Kommentar', $body);
    }

    /** @param array<string,mixed> $ticket */
    public function slaWarning(array $ticket, string $target): void
    {
        $this->sendTo($ticket, 'sla_warning_' . $target, $this->teamRecipients($ticket, false), 'SLA-Warnung', $this->intro($ticket, 'Die ' . ($target === 'response' ? 'Reaktionszeit' : 'Lösungszeit') . ' dieses Tickets ist bald überschritten.'));
    }

    /** @param array<string,mixed> $ticket */
    public function slaBreached(array $ticket, string $target): void
    {
        $this->sendTo($ticket, 'sla_breached_' . $target, $this->teamRecipients($ticket, true), 'SLA verletzt', $this->intro($ticket, 'Die ' . ($target === 'response' ? 'Reaktionszeit' : 'Lösungszeit') . ' dieses Tickets wurde überschritten.'));
    }

    /** @param array<string,mixed> $ticket @param array<int,string> $recipients */
    public function escalated(array $ticket, int $level, array $recipients): void
    {
        $this->sendTo($ticket, 'escalated_' . $level, $recipients, 'Ticket eskaliert (Stufe ' . $level . ')', $this->intro($ticket, 'Das Ticket wurde auf Eskalationsstufe ' . $level . ' gesetzt.'));
    }

    /** Aus Regelaktionen (notify_group / notify_emails). @param array<string,mixed> $ticket @param array<int,string> $recipients */
    public function ruleNotification(array $ticket, string $ruleName, array $recipients): void
    {
        $this->sendTo($ticket, 'rule_' . md5($ruleName), $recipients, 'Regel „' . $ruleName . '“ ausgelöst', $this->intro($ticket, 'Eine Automatisierungsregel hat dieses Ticket markiert.'));
    }

    // ------------------------------------------------------------------ Empfänger

    /** @param array<string,mixed> $ticket @return array<int,string> */
    public function requesterRecipients(array $ticket): array
    {
        return $this->unique([(string) ($ticket['requester_email'] ?? ''), (string) ($ticket['requester_user_email'] ?? '')]);
    }

    /** Bearbeiter, sonst Gruppe; optional immer die Gruppe. @param array<string,mixed> $ticket @return array<int,string> */
    public function teamRecipients(array $ticket, bool $includeGroup): array
    {
        $emails = [(string) ($ticket['assignee_email'] ?? '')];
        if (($includeGroup || empty($ticket['assignee_user_id'])) && !empty($ticket['group_id'])) {
            foreach ($this->masterData->groupMembers((int) $ticket['group_id']) as $member) {
                $emails[] = (string) ($member['email'] ?? '');
            }
        }
        if ($this->unique($emails) === []) {
            $emails[] = (string) $this->config->get('helpdesk.notification_inbox', '');
        }

        return $this->unique($emails);
    }

    /** @param array<string,mixed> $ticket @param array<int,string> $excludeUserIds @return array<int,string> */
    public function watcherRecipients(array $ticket, array $excludeUserIds = []): array
    {
        $emails = [];
        foreach ($this->tickets->watchers((int) $ticket['id']) as $watcher) {
            if (in_array((string) $watcher['user_id'], $excludeUserIds, true)) {
                continue;
            }
            $emails[] = (string) ($watcher['email'] ?? '');
        }

        return array_values(array_diff($this->unique($emails), $this->requesterRecipients($ticket), [(string) ($ticket['assignee_email'] ?? '')]));
    }

    /** @return array<int,string> */
    public function groupRecipients(int $groupId): array
    {
        return $this->unique(array_map(static fn (array $m): string => (string) ($m['email'] ?? ''), $this->masterData->groupMembers($groupId)));
    }

    // ------------------------------------------------------------------ Versand

    /**
     * Versendet idempotent an alle Empfänger; protokolliert Ergebnis je Empfänger.
     * @param array<string,mixed> $ticket
     * @param array<int,string> $recipients
     */
    public function sendTo(array $ticket, string $eventKey, array $recipients, string $subject, string $html): int
    {
        $ticketId = (int) $ticket['id'];
        $fullSubject = '[' . $ticket['number'] . '] ' . $subject . ': ' . $ticket['subject'];
        $sent = 0;
        foreach ($this->unique($recipients) as $email) {
            if ($this->rules->notificationExists($ticketId, $eventKey, $email)) {
                continue;
            }
            if (!$this->enabled()) {
                $this->rules->logNotification($ticketId, $eventKey, $email, $fullSubject, 'skipped', 'Mailversand deaktiviert');
                continue;
            }
            $messageId = $this->messageId($ticketId, $eventKey);
            try {
                $ok = $this->mail->send($email, $fullSubject, $this->wrap($ticket, $html), [], $this->threadHeaders($ticket, $messageId));
                $this->rules->logNotification($ticketId, $eventKey, $email, $fullSubject, $ok ? 'sent' : 'failed', $ok ? null : 'Mail-Dienst meldete Fehler', $messageId);
                $sent += $ok ? 1 : 0;
            } catch (\Throwable $e) {
                $this->logger->warning('Ticket-Benachrichtigung fehlgeschlagen', ['ticket' => $ticket['number'], 'event' => $eventKey, 'error' => $e->getMessage()]);
                $this->rules->logNotification($ticketId, $eventKey, $email, $fullSubject, 'failed', $e->getMessage(), $messageId);
            }
        }

        return $sent;
    }

    // ------------------------------------------------------------------ Threading

    /**
     * Eindeutige Message-ID einer ausgehenden Ticket-Mail. Das Präfix „ticket-{id}.“ erlaubt es dem
     * E-Mail-Eingang, Antworten auch ohne Datenbanktreffer dem Ticket zuzuordnen.
     */
    public function messageId(int $ticketId, string $eventKey): string
    {
        $key = preg_replace('/[^a-z0-9]+/i', '-', $eventKey) ?? 'event';

        return sprintf('<ticket-%d.%s.%s@%s>', $ticketId, trim($key, '-'), bin2hex(random_bytes(6)), $this->mailDomain());
    }

    /** Stabile Wurzel-ID je Ticket, unter der Mailclients alle Nachrichten eines Tickets als Unterhaltung gruppieren. */
    public function threadRootId(int $ticketId): string
    {
        return sprintf('<ticket-%d@%s>', $ticketId, $this->mailDomain());
    }

    /** Ticket-ID aus einer Message-ID im eigenen Format ermitteln (sonst null). */
    public static function ticketIdFromMessageId(string $messageId): ?int
    {
        return preg_match('/<ticket-(\d+)(?:[.@])/i', trim($messageId), $m) === 1 ? (int) $m[1] : null;
    }

    /** @param array<string,mixed> $ticket @return array<string,string> */
    private function threadHeaders(array $ticket, string $messageId): array
    {
        $root = $this->threadRootId((int) $ticket['id']);

        return [
            'Message-ID' => $messageId,
            'In-Reply-To' => $root,
            'References' => $root,
            'X-Ticket-Number' => (string) $ticket['number'],
            'Auto-Submitted' => 'auto-generated',
        ];
    }

    public function mailDomain(): string
    {
        $domain = (string) $this->config->get('helpdesk.mail_domain', '');
        if ($domain === '') {
            $from = (string) $this->config->get('mail.from_address', '');
            $domain = str_contains($from, '@') ? substr($from, strrpos($from, '@') + 1) : '';
        }
        if ($domain === '') {
            $host = parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST);
            $domain = is_string($host) && $host !== '' ? $host : 'helpdesk.local';
        }

        return preg_replace('/[^a-z0-9.\-]/i', '', $domain) ?: 'helpdesk.local';
    }

    /** @param array<string,mixed> $ticket */
    private function intro(array $ticket, string $message): string
    {
        return '<p>' . self::e($message) . '</p>'
            . '<table cellpadding="4" style="border-collapse:collapse">'
            . '<tr><td><strong>Ticket</strong></td><td>' . self::e((string) $ticket['number']) . '</td></tr>'
            . '<tr><td><strong>Betreff</strong></td><td>' . self::e((string) $ticket['subject']) . '</td></tr>'
            . '<tr><td><strong>Status</strong></td><td>' . self::e((string) ($ticket['status_name'] ?? '')) . '</td></tr>'
            . '<tr><td><strong>Priorität</strong></td><td>' . self::e((string) ($ticket['priority_name'] ?? '')) . '</td></tr>'
            . (!empty($ticket['assignee_name']) ? '<tr><td><strong>Bearbeiter</strong></td><td>' . self::e((string) $ticket['assignee_name']) . '</td></tr>' : '')
            . '</table>';
    }

    /** @param array<string,mixed> $ticket */
    private function wrap(array $ticket, string $html): string
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $link = $base . '/helpdesk/tickets/' . (int) $ticket['id'];
        $portal = $base . '/portal/tickets/' . (int) $ticket['id'];

        return '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#222">' . $html
            . '<p><a href="' . self::e($link) . '">Ticket im Help Desk öffnen</a> · <a href="' . self::e($portal) . '">Im Self-Service-Portal ansehen</a></p>'
            . '<p style="color:#777;font-size:12px">Diese E-Mail wurde automatisch vom IT Help Desk (' . self::e((string) $this->config->get('app.company_name', '')) . ') erzeugt. Antworten auf diese E-Mail werden nicht verarbeitet.</p></div>';
    }

    /** @param array<int,string> $emails @return array<int,string> */
    private function unique(array $emails): array
    {
        $out = [];
        foreach ($emails as $email) {
            $email = mb_strtolower(trim($email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $out[$email] = $email;
            }
        }

        return array_values($out);
    }

    private static function statusLabel(string $code): string
    {
        return match ($code) {
            TicketWorkflowService::RESOLVED => 'gelöst',
            TicketWorkflowService::CLOSED => 'geschlossen',
            TicketWorkflowService::CANCELLED => 'storniert',
            TicketWorkflowService::WAITING_USER => 'wartet auf Ihre Rückmeldung',
            TicketWorkflowService::IN_PROGRESS => 'in Bearbeitung',
            default => 'aktualisiert',
        };
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
