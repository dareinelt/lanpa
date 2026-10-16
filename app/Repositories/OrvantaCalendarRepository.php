<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

/**
 * Datenzugriff fuer den lokalen Orvanta-Kalender (Tabellen
 * orvanta_calendar_*). Alle Abfragen sind auf user_uid eingeschraenkt, damit
 * ein Benutzer ausschliesslich seine eigenen Termine liest und aendert.
 *
 * Die Bereichsabfrage nutzt den Index (user_uid, starts_at, ends_at) und laedt
 * Teilnehmer in Bloecken nach, statt je Termin eine eigene Abfrage zu starten
 * (keine N+1-Abfragen). Das SQL laeuft unveraendert auf MySQL und SQLite.
 */
final class OrvantaCalendarRepository extends Repository
{
    /** Hoechstzahl der Termine einer Bereichsabfrage (API begrenzt auf 100 Tage). */
    public const MAX_RANGE = 1000;

    /** Groesse der Bloecke beim Nachladen von Teilnehmern. */
    private const ATTENDEE_CHUNK = 200;

    /** Hoechstzahl der Versandauftraege, die eine Abfrage liefert. */
    private const DUE_LIMIT = 20;

    /** Spalten der Terminliste - description wird erst im Einzelabruf geladen. */
    private const LIST_COLUMNS = 'id, user_uid, event_uid, subject, location, organizer_email, organizer_name,
        is_organizer, starts_at, ends_at, all_day, timezone, free_busy, status, sequence, reminder_minutes,
        my_response, attendee_count, source, origin_message_id';

    /** Spalten des Einzelabrufs (zusaetzlich der Beschreibungstext). */
    private const DETAIL_COLUMNS = 'id, user_uid, event_uid, subject, description, location, organizer_email,
        organizer_name, is_organizer, starts_at, ends_at, all_day, timezone, free_busy, status, sequence,
        reminder_minutes, my_response, attendee_count, source, origin_message_id';

    /** Schreibbare Spalten des Termins (Weissliste fuer INSERT und UPDATE). */
    private const WRITE_COLUMNS = [
        'event_uid', 'subject', 'description', 'location', 'organizer_email', 'organizer_name', 'is_organizer',
        'starts_at', 'ends_at', 'all_day', 'timezone', 'free_busy', 'status', 'sequence', 'reminder_minutes',
        'my_response', 'attendee_count', 'source', 'origin_message_id',
    ];

    /**
     * Aktive Termine eines Benutzers im Zeitraum [from, to): abgesagte Termine
     * und abgelehnte Einladungen erscheinen nicht im Kalender.
     *
     * @return list<array<string,mixed>>
     */
    public function range(string $uid, string $from, string $to): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::LIST_COLUMNS . ' FROM orvanta_calendar_events
            WHERE user_uid = :uid AND starts_at < :to AND ends_at > :from
              AND status <> \'CANCELLED\' AND my_response <> \'DECLINED\'
            ORDER BY starts_at ASC, id ASC
            LIMIT ' . self::MAX_RANGE);
        $statement->execute(['uid' => $uid, 'from' => $from, 'to' => $to]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Anstehende Termine fuer den Erinnerungsdienst (auch abgesagte und
     * abgelehnte, damit deren Erinnerungen wieder entfernt werden).
     *
     * @return list<array<string,mixed>>
     */
    public function reminderWindow(string $uid, string $from, string $to, int $limit = 200): array
    {
        $statement = $this->pdo->prepare('SELECT id, event_uid, subject, location, starts_at, ends_at,
                reminder_minutes, status, my_response
            FROM orvanta_calendar_events
            WHERE user_uid = :uid AND starts_at >= :from AND starts_at <= :to
            ORDER BY starts_at ASC, id ASC
            LIMIT ' . max(1, min(500, $limit)));
        $statement->execute(['uid' => $uid, 'from' => $from, 'to' => $to]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Einzelner Termin inklusive Beschreibung - nur der eigene Termin.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $uid, int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::DETAIL_COLUMNS . ' FROM orvanta_calendar_events
            WHERE user_uid = :uid AND id = :id LIMIT 1');
        $statement->execute(['uid' => $uid, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Termin ueber die Serienkennung (iCalendar-UID) - nur der eigene Termin.
     *
     * @return array<string,mixed>|null
     */
    public function findByUid(string $uid, string $eventUid): ?array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::DETAIL_COLUMNS . ' FROM orvanta_calendar_events
            WHERE user_uid = :uid AND event_uid = :event_uid LIMIT 1');
        $statement->execute(['uid' => $uid, 'event_uid' => $eventUid]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Teilnehmer mehrerer Termine in einem Zug.
     *
     * @param list<int|string> $eventIds
     * @return array<int,list<array<string,mixed>>> Termin-ID => Teilnehmer
     */
    public function attendeesFor(array $eventIds): array
    {
        $ids = [];
        foreach ($eventIds as $eventId) {
            $value = (int) $eventId;
            if ($value > 0) {
                $ids[$value] = $value;
            }
        }
        $result = [];
        foreach (array_chunk(array_values($ids), self::ATTENDEE_CHUNK) as $chunk) {
            $params = [];
            $placeholders = [];
            foreach ($chunk as $index => $value) {
                $placeholders[] = ':event_' . $index;
                $params['event_' . $index] = $value;
            }
            $statement = $this->pdo->prepare('SELECT event_id, email, display_name, role, response
                FROM orvanta_calendar_attendees
                WHERE event_id IN (' . implode(', ', $placeholders) . ')
                ORDER BY CASE role WHEN \'REQ-PARTICIPANT\' THEN 0 WHEN \'OPT-PARTICIPANT\' THEN 1 ELSE 2 END ASC, email ASC');
            $statement->execute($params);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $result[(int) $row['event_id']][] = $row;
            }
        }

        return $result;
    }

    /**
     * Legt einen Termin an und liefert dessen Kennung.
     *
     * @param array<string,mixed> $event
     */
    public function create(string $uid, array $event, string $now): int
    {
        $columns = ['user_uid', 'created_at', 'updated_at'];
        $params = ['user_uid' => $uid, 'created_at' => $now, 'updated_at' => $now];
        foreach (self::WRITE_COLUMNS as $column) {
            if (array_key_exists($column, $event)) {
                $columns[] = $column;
                $params[$column] = $event[$column];
            }
        }
        $statement = $this->pdo->prepare('INSERT INTO orvanta_calendar_events (' . implode(', ', $columns) . ')
            VALUES (:' . implode(', :', $columns) . ')');
        $statement->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aendert die uebergebenen Spalten des eigenen Termins.
     *
     * @param array<string,mixed> $event
     * @return int Anzahl geaenderter Zeilen
     */
    public function update(string $uid, int $id, array $event, string $now): int
    {
        $sets = [];
        $params = ['id' => $id, 'uid' => $uid];
        foreach (self::WRITE_COLUMNS as $column) {
            if (array_key_exists($column, $event)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $event[$column];
            }
        }
        if ($sets === []) {
            return 0;
        }
        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = $now;
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_events SET ' . implode(', ', $sets) . '
            WHERE user_uid = :uid AND id = :id');
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * Loescht den eigenen Termin (Teilnehmer und Verweise werden mitgeloescht).
     */
    public function delete(string $uid, int $id): int
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_calendar_events WHERE user_uid = :uid AND id = :id');
        $statement->execute(['uid' => $uid, 'id' => $id]);

        return $statement->rowCount();
    }

    /**
     * Ersetzt die Teilnehmerliste eines Termins. Bereits abgegebene Antworten
     * bleiben erhalten, solange die Adresse unveraendert bleibt - eine
     * Aktualisierung darf eine Zusage nicht zuruecksetzen.
     *
     * @param list<array{email:string,name:string,role:string,response:string}> $attendees
     */
    public function replaceAttendees(int $eventId, array $attendees, string $now): void
    {
        $existing = [];
        $statement = $this->pdo->prepare('SELECT email, response, responded_at FROM orvanta_calendar_attendees WHERE event_id = :id');
        $statement->execute(['id' => $eventId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $existing[strtolower((string) $row['email'])] = $row;
        }
        $this->pdo->prepare('DELETE FROM orvanta_calendar_attendees WHERE event_id = :id')->execute(['id' => $eventId]);
        $insert = $this->pdo->prepare('INSERT INTO orvanta_calendar_attendees
            (event_id, email, display_name, role, response, responded_at, created_at, updated_at)
            VALUES (:event_id, :email, :name, :role, :response, :responded_at, :created_at, :updated_at)');
        foreach ($attendees as $attendee) {
            $known = $existing[strtolower($attendee['email'])] ?? null;
            $response = $known === null ? $attendee['response'] : (string) $known['response'];
            $insert->execute([
                'event_id' => $eventId,
                'email' => $attendee['email'],
                'name' => $attendee['name'],
                'role' => $attendee['role'],
                'response' => $response,
                'responded_at' => $known === null ? null : $known['responded_at'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->setAttendeeCount($eventId, count($attendees));
    }

    /**
     * Setzt die Antwort eines Teilnehmers (nur bei eigenem Termin).
     */
    public function setAttendeeResponse(string $uid, int $eventId, string $email, string $response, string $now): int
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_attendees
            SET response = :response, responded_at = :responded_at, updated_at = :updated_at
            WHERE event_id = :event_id AND email = :email
              AND EXISTS (SELECT 1 FROM orvanta_calendar_events e
                          WHERE e.id = orvanta_calendar_attendees.event_id AND e.user_uid = :uid)');
        $statement->execute([
            'response' => $response,
            'responded_at' => $now,
            'updated_at' => $now,
            'event_id' => $eventId,
            'email' => $email,
            'uid' => $uid,
        ]);

        return $statement->rowCount();
    }

    /**
     * Setzt die Antwort des lokalen Benutzers auf eine erhaltene Einladung.
     */
    public function setMyResponse(string $uid, int $id, string $response, string $now): int
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_events
            SET my_response = :response, updated_at = :now
            WHERE user_uid = :uid AND id = :id');
        $statement->execute(['response' => $response, 'now' => $now, 'uid' => $uid, 'id' => $id]);

        return $statement->rowCount();
    }

    private function setAttendeeCount(int $eventId, int $count): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_events SET attendee_count = :count WHERE id = :id');
        $statement->execute(['count' => $count, 'id' => $eventId]);
    }

    /**
     * Vermerkt ein verarbeitetes eingehendes iCalendar-Objekt. Liefert false,
     * wenn derselbe Vorgang bereits verarbeitet wurde (Idempotenz).
     *
     * @param array{event_uid:string,method:string,sequence:int,event_id:int|null} $item
     */
    public function markProcessed(string $uid, string $hash, array $item, string $now): bool
    {
        $select = $this->pdo->prepare('SELECT id FROM orvanta_calendar_processed WHERE user_uid = :uid AND item_hash = :hash LIMIT 1');
        $select->execute(['uid' => $uid, 'hash' => $hash]);
        if ($select->fetchColumn() !== false) {
            return false;
        }
        $insert = $this->pdo->prepare('INSERT INTO orvanta_calendar_processed
            (user_uid, item_hash, event_uid, method, sequence, event_id, created_at)
            VALUES (:uid, :hash, :event_uid, :method, :sequence, :event_id, :now)');
        try {
            $insert->execute([
                'uid' => $uid,
                'hash' => $hash,
                'event_uid' => $item['event_uid'],
                'method' => $item['method'],
                'sequence' => $item['sequence'],
                'event_id' => $item['event_id'],
                'now' => $now,
            ]);
        } catch (PDOException) {
            // Gleichzeitiger Aufruf hat denselben Vorgang bereits vermerkt.
            return false;
        }

        return true;
    }

    /**
     * Reiht einen Versandauftrag ein. Liefert false, wenn die Idempotenzkennung
     * bereits existiert - dann wurde dieselbe Nachricht schon vorgemerkt.
     *
     * @param list<string> $recipients
     */
    public function enqueue(
        string $uid,
        ?int $eventId,
        string $from,
        string $kind,
        string $key,
        array $recipients,
        string $payload,
        string $now
    ): bool {
        $select = $this->pdo->prepare('SELECT id FROM orvanta_calendar_outbox WHERE idempotency_key = :key LIMIT 1');
        $select->execute(['key' => $key]);
        if ($select->fetchColumn() !== false) {
            return false;
        }
        $insert = $this->pdo->prepare('INSERT INTO orvanta_calendar_outbox
            (user_uid, event_id, kind, idempotency_key, from_email, recipients, payload, status, attempts,
             next_attempt_at, created_at, updated_at)
            VALUES (:uid, :event_id, :kind, :key, :from_email, :recipients, :payload, \'pending\', 0,
                    :next_attempt_at, :created_at, :updated_at)');
        try {
            $insert->execute([
                'uid' => $uid,
                'event_id' => $eventId,
                'kind' => $kind,
                'key' => $key,
                'from_email' => $from,
                'recipients' => json_encode(array_values($recipients), JSON_UNESCAPED_UNICODE),
                'payload' => $payload,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    /**
     * Faellige Versandauftraege eines Benutzers.
     *
     * @return list<array<string,mixed>>
     */
    public function dueOutbox(string $uid, string $now, int $maxAttempts, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT id, user_uid, event_id, kind, idempotency_key, from_email,
                recipients, payload, attempts
            FROM orvanta_calendar_outbox
            WHERE user_uid = :uid AND status IN (\'pending\', \'failed\')
              AND attempts < :attempts AND next_attempt_at <= :now
            ORDER BY next_attempt_at ASC, id ASC
            LIMIT ' . max(1, min(self::DUE_LIMIT, $limit)));
        $statement->execute(['uid' => $uid, 'attempts' => $maxAttempts, 'now' => $now]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Uebernimmt einen Auftrag zur Ausfuehrung (Sperre gegen Doppelversand).
     * Der Auftrag wird nur beansprucht, wenn er noch nicht bearbeitet wurde.
     */
    public function claimOutbox(int $id, int $attempts, string $now): bool
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_outbox
            SET status = \'sending\', attempts = attempts + 1, updated_at = :now
            WHERE id = :id AND status IN (\'pending\', \'failed\') AND attempts = :attempts');
        $statement->execute(['now' => $now, 'id' => $id, 'attempts' => $attempts]);

        return $statement->rowCount() === 1;
    }

    /**
     * Schreibt das Ergebnis eines Versandversuchs fest. Ein zugestellter Auftrag
     * hat keinen naechsten Versuch; die Spalte bleibt dann auf dem Zeitpunkt
     * des Versands (sie ist NOT NULL).
     */
    public function finishOutbox(int $id, string $status, ?string $error, ?string $nextAttemptAt, ?string $sentAt, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_outbox
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
    public function recoverOutbox(string $staleBefore, string $now): int
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_calendar_outbox
            SET status = \'pending\', next_attempt_at = :next_attempt_at, updated_at = :updated_at
            WHERE status = \'sending\' AND updated_at <= :stale');
        $statement->execute(['next_attempt_at' => $now, 'updated_at' => $now, 'stale' => $staleBefore]);

        return $statement->rowCount();
    }

    /**
     * Versandstatus eines Termins fuer die Terminansicht.
     *
     * @return list<array<string,mixed>>
     */
    public function outboxState(int $eventId): array
    {
        $statement = $this->pdo->prepare('SELECT kind, status, attempts, last_error, sent_at
            FROM orvanta_calendar_outbox WHERE event_id = :id ORDER BY id DESC LIMIT 20');
        $statement->execute(['id' => $eventId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
