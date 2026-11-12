<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Erwähnungen von Agenten („@Name“) in internen Ticketnotizen und Aufgabenkommentaren. Ungelesene
 * Einträge erscheinen als Benachrichtigung auf dem Help-Desk-Dashboard des erwähnten Agenten.
 */
final class HelpdeskMentionRepository extends BaseRepository
{
    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->insertRow('helpdesk_mentions', $data);
    }

    /**
     * Ungelesene Erwähnungen eines Benutzers, neueste zuerst, samt Nummer und Betreff des Bezugs.
     * @return list<array<string,mixed>>
     */
    public function unreadForUser(int $userId, int $limit = 50): array
    {
        return $this->fetchAll(
            'SELECT m.*, t.number AS ticket_number, t.subject AS ticket_subject,
                    a.number AS task_number, a.subject AS task_subject
             FROM helpdesk_mentions m
             LEFT JOIN tickets t ON t.id = m.ticket_id
             LEFT JOIN tasks a ON a.id = m.task_id
             WHERE m.user_id = :u AND m.read_at IS NULL
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT ' . max(1, $limit),
            ['u' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM helpdesk_mentions WHERE id = :id', ['id' => $id]);
    }

    /** Markiert eine Erwähnung des Benutzers als gelesen. */
    public function markRead(int $id, int $userId): int
    {
        return $this->execute(
            'UPDATE helpdesk_mentions SET read_at = :at WHERE id = :id AND user_id = :u AND read_at IS NULL',
            ['at' => gmdate('Y-m-d H:i:s'), 'id' => $id, 'u' => $userId]
        );
    }

    /** Markiert alle Erwähnungen des Benutzers als gelesen. */
    public function markAllRead(int $userId): int
    {
        return $this->execute(
            'UPDATE helpdesk_mentions SET read_at = :at WHERE user_id = :u AND read_at IS NULL',
            ['at' => gmdate('Y-m-d H:i:s'), 'u' => $userId]
        );
    }

    /** Markiert alle Erwähnungen des Benutzers an einem Ticket als gelesen (beim Öffnen). */
    public function markTicketRead(int $ticketId, int $userId): int
    {
        return $this->execute(
            'UPDATE helpdesk_mentions SET read_at = :at WHERE ticket_id = :t AND user_id = :u AND read_at IS NULL',
            ['at' => gmdate('Y-m-d H:i:s'), 't' => $ticketId, 'u' => $userId]
        );
    }

    /** Markiert alle Erwähnungen des Benutzers an einer Aufgabe als gelesen (beim Öffnen). */
    public function markTaskRead(int $taskId, int $userId): int
    {
        return $this->execute(
            'UPDATE helpdesk_mentions SET read_at = :at WHERE task_id = :t AND user_id = :u AND read_at IS NULL',
            ['at' => gmdate('Y-m-d H:i:s'), 't' => $taskId, 'u' => $userId]
        );
    }
}
