<?php

declare(strict_types=1);

namespace App\Repositories;

/** Regeln der Regelengine und Protokoll versendeter Benachrichtigungen. */
final class TicketRuleRepository extends BaseRepository
{
    public const TRIGGERS = ['created', 'updated', 'status_changed', 'comment_added', 'sla_warning', 'sla_breached'];

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM ticket_rules ORDER BY trigger_event, sort_order, name');
    }

    /** Aktive Regeln eines Auslösers in Ausführungsreihenfolge. @return array<int,array<string,mixed>> */
    public function activeFor(string $trigger): array
    {
        return $this->fetchAll('SELECT * FROM ticket_rules WHERE is_active = 1 AND trigger_event = :t ORDER BY sort_order, id', ['t' => $trigger]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_rules WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return $this->fetchOne('SELECT * FROM ticket_rules WHERE name = :n', ['n' => $name]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('ticket_rules', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->updateRow('ticket_rules', $id, $data);
    }

    public function delete(int $id): int
    {
        return $this->execute('DELETE FROM ticket_rules WHERE id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ Benachrichtigungsprotokoll

    /** Prüft, ob ein Ereignis an einen Empfänger bereits (erfolgreich oder bewusst übersprungen) verarbeitet wurde. */
    public function notificationExists(int $ticketId, string $eventKey, string $recipient): bool
    {
        return $this->fetchValue('SELECT 1 FROM ticket_notifications WHERE ticket_id = :t AND event_key = :e AND recipient = :r', ['t' => $ticketId, 'e' => $eventKey, 'r' => $recipient]) !== null;
    }

    public function logNotification(int $ticketId, string $eventKey, string $recipient, string $subject, string $status, ?string $error = null, ?string $messageId = null): void
    {
        $this->execute(
            'INSERT INTO ticket_notifications (ticket_id, event_key, recipient, subject, status, error, message_id) VALUES (:t, :e, :r, :s, :st, :err, :mid)
             ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error), subject = VALUES(subject), message_id = COALESCE(VALUES(message_id), message_id)',
            ['t' => $ticketId, 'e' => mb_substr($eventKey, 0, 120), 'r' => mb_substr($recipient, 0, 255), 's' => mb_substr($subject, 0, 255), 'st' => $status, 'err' => $error !== null ? mb_substr($error, 0, 500) : null, 'mid' => $messageId !== null ? mb_substr($messageId, 0, 255) : null]
        );
    }

    /** Ticket zu einer ausgehenden Message-ID (für das Threading eingehender Antworten). */
    public function ticketIdByMessageId(string $messageId): ?int
    {
        $id = $this->fetchValue('SELECT ticket_id FROM ticket_notifications WHERE message_id = :m LIMIT 1', ['m' => mb_substr($messageId, 0, 255)]);

        return $id !== null ? (int) $id : null;
    }

    // ------------------------------------------------------------------ E-Mail-Eingang

    public function inboundMailExists(string $messageId): bool
    {
        return $this->fetchValue('SELECT 1 FROM ticket_inbound_mails WHERE message_id = :m', ['m' => mb_substr($messageId, 0, 255)]) !== null;
    }

    /** @param array<string,mixed> $data */
    public function logInboundMail(array $data): int
    {
        $data['message_id'] = mb_substr((string) $data['message_id'], 0, 255);
        $data['from_address'] = mb_substr((string) ($data['from_address'] ?? ''), 0, 255);
        $data['subject'] = mb_substr((string) ($data['subject'] ?? ''), 0, 255);
        $data['detail'] = isset($data['detail']) ? mb_substr((string) $data['detail'], 0, 500) : null;

        return $this->insertRow('ticket_inbound_mails', $data);
    }

    /** @return array<int,array<string,mixed>> */
    public function recentInboundMails(int $limit = 50): array
    {
        return $this->fetchAll('SELECT m.*, t.number AS ticket_number FROM ticket_inbound_mails m LEFT JOIN tickets t ON t.id = m.ticket_id ORDER BY m.id DESC LIMIT ' . max(1, $limit));
    }

    /** @return array<int,array<string,mixed>> */
    public function notificationsForTicket(int $ticketId): array
    {
        return $this->fetchAll('SELECT * FROM ticket_notifications WHERE ticket_id = :t ORDER BY created_at DESC, id DESC', ['t' => $ticketId]);
    }
}
