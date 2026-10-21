<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Datenzugriff fuer die Warteschlange nicht zustellbarer Orvanta-Mails
 * (Tabelle orvanta_mail_outbox). Ein Auftrag enthaelt den vollstaendigen
 * Versandauftrag, damit ein spaeterer Versuch ohne Benutzersitzung auskommt.
 *
 * Die Uebernahme eines Auftrags (claim) ist optimistisch: Sie gelingt nur,
 * wenn seit dem Lesen kein anderer Lauf denselben Auftrag begonnen hat. Das
 * SQL laeuft unveraendert auf MySQL und SQLite.
 */
final class OrvantaMailOutboxRepository extends Repository
{
    /** Hoechstzahl der Auftraege, die eine Abfrage liefert. */
    private const DUE_LIMIT = 20;

    /**
     * Reiht einen Versandauftrag ein.
     *
     * @param list<string> $recipients Empfaenger fuer die Diagnose (An, Cc, Bcc)
     *
     * @return int Kennung des Auftrags
     */
    public function enqueue(
        string $uid,
        string $backend,
        string $mailbox,
        string $fromEmail,
        string $kind,
        string $subject,
        array $recipients,
        string $payload,
        string $draftId,
        string $changeKey,
        string $error,
        string $now
    ): int {
        $statement = $this->pdo->prepare('INSERT INTO orvanta_mail_outbox
            (user_uid, backend, mailbox, from_email, kind, subject, recipients, payload, draft_id,
             change_key, status, attempts, last_error, next_attempt_at, created_at, updated_at)
            VALUES (:uid, :backend, :mailbox, :from_email, :kind, :subject, :recipients, :payload,
                    :draft_id, :change_key, \'pending\', 0, :error, :next_attempt_at, :created_at, :updated_at)');
        $statement->execute([
            'uid' => $uid,
            'backend' => $backend,
            'mailbox' => $mailbox,
            'from_email' => $fromEmail,
            'kind' => $kind,
            'subject' => mb_substr($subject, 0, 255),
            'recipients' => json_encode(array_values($recipients), JSON_UNESCAPED_UNICODE),
            'payload' => $payload,
            'draft_id' => $draftId,
            'change_key' => $changeKey,
            'error' => mb_substr($error, 0, 500),
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Faellige Auftraege aller Benutzer. Endgueltig gescheiterte Auftraege
     * (status = failed) werden nicht mehr versucht.
     *
     * @return list<array<string,mixed>>
     */
    public function due(string $now, int $maxAttempts, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT id, user_uid, backend, mailbox, from_email, kind, subject,
                payload, draft_id, change_key, attempts
            FROM orvanta_mail_outbox
            WHERE status = \'pending\' AND attempts < :attempts AND next_attempt_at <= :now
            ORDER BY next_attempt_at ASC, id ASC
            LIMIT ' . max(1, min(self::DUE_LIMIT, $limit)));
        $statement->execute(['attempts' => $maxAttempts, 'now' => $now]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Uebernimmt einen Auftrag zur Ausfuehrung (Sperre gegen Doppelversand).
     */
    public function claim(int $id, int $attempts, string $now): bool
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_mail_outbox
            SET status = \'sending\', attempts = attempts + 1, updated_at = :now
            WHERE id = :id AND status = \'pending\' AND attempts = :attempts');
        $statement->execute(['now' => $now, 'id' => $id, 'attempts' => $attempts]);

        return $statement->rowCount() === 1;
    }

    /**
     * Schreibt das Ergebnis eines Versandversuchs fest. Ein zugestellter
     * Auftrag hat keinen naechsten Versuch; die Spalte bleibt dann auf dem
     * Zeitpunkt des Versands (sie ist NOT NULL).
     */
    public function finish(int $id, string $status, ?string $error, ?string $nextAttemptAt, ?string $sentAt, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_mail_outbox
            SET status = :status, last_error = :error, next_attempt_at = :next_attempt_at,
                sent_at = :sent_at, updated_at = :now
            WHERE id = :id');
        $statement->execute([
            'status' => $status,
            'error' => mb_substr((string) $error, 0, 500),
            'next_attempt_at' => $nextAttemptAt ?? $now,
            'sent_at' => $sentAt,
            'now' => $now,
            'id' => $id,
        ]);
    }

    /**
     * Gibt Auftraege frei, deren Ausfuehrung abgebrochen wurde (z. B. weil der
     * Prozess beendet wurde), damit sie erneut versucht werden.
     */
    public function recover(string $staleBefore, string $now): int
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_mail_outbox
            SET status = \'pending\', next_attempt_at = :next_attempt_at, updated_at = :updated_at
            WHERE status = \'sending\' AND updated_at <= :stale');
        $statement->execute(['next_attempt_at' => $now, 'updated_at' => $now, 'stale' => $staleBefore]);

        return $statement->rowCount();
    }

    /**
     * Entfernt abgeschlossene Auftraege nach Ablauf der Aufbewahrung. Der
     * Auftrag enthaelt den vollstaendigen Nachrichtentext, deshalb bleibt er
     * nur so lange liegen, wie er fuer die Fehlersuche gebraucht wird.
     */
    public function prune(string $sentBefore, string $failedBefore): int
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_mail_outbox
            WHERE (status = \'sent\' AND sent_at <= :sent) OR (status = \'failed\' AND updated_at <= :failed)');
        $statement->execute(['sent' => $sentBefore, 'failed' => $failedBefore]);

        return $statement->rowCount();
    }
}
