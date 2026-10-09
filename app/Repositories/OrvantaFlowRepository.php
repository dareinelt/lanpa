<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Datenzugriff fuer das Nachrichtenfluss-Dashboard (Migration 047):
 * Praesenz der Orvanta-Benutzer und Proben der aktiven Nutzer.
 *
 * orvanta_activity enthaelt nur Benutzerkennung, Backend und Zeitstempel,
 * orvanta_user_samples ausschliesslich Zaehler - bewusst ohne Inhalte,
 * Betreffzeilen oder Empfaenger.
 *
 * Alle Zeitpunkte werden als Zeichenkette ('Y-m-d H:i:s') uebergeben, damit
 * die Abfragen ohne MySQL-Sonderformen auch in den SQLite-Tests laufen.
 */
final class OrvantaFlowRepository extends Repository
{
    // ------------------------------------------------------------------ Aktivitaet

    /**
     * Aktivitaet eines Benutzers erfassen. Vorhandene Zeilen werden
     * fortgeschrieben (letzte Sichtung, Zaehler, Backend), sonst angelegt.
     */
    public function touchActivity(string $userUid, string $backend, string $seenAt): void
    {
        $uid = mb_substr(trim($userUid), 0, 190);
        if ($uid === '') {
            return;
        }
        $backend = $backend === 'proxy' ? 'proxy' : 'exchange';

        $updated = $this->pdo->prepare(
            'UPDATE orvanta_activity SET last_seen_at = :seen, backend = :backend, requests = requests + 1'
            . ' WHERE user_uid = :uid'
        );
        $updated->execute(['seen' => $seenAt, 'backend' => $backend, 'uid' => $uid]);
        if ($updated->rowCount() > 0) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO orvanta_activity (user_uid, backend, first_seen_at, last_seen_at, requests)'
            . ' VALUES (:uid, :backend, :seen, :seen, 1)'
        )->execute(['uid' => $uid, 'backend' => $backend, 'seen' => $seenAt]);
    }

    /**
     * Nutzer mit Aktivitaet im Zeitfenster, getrennt nach Backend.
     *
     * @return array{total:int,exchange:int,proxy:int}
     */
    public function activeUsers(string $since, string $until): array
    {
        $statement = $this->pdo->prepare(
            'SELECT backend, COUNT(*) AS users FROM orvanta_activity'
            . ' WHERE last_seen_at >= :since AND last_seen_at <= :until GROUP BY backend'
        );
        $statement->execute(['since' => $since, 'until' => $until]);

        $exchange = 0;
        $proxy = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if ((string) $row['backend'] === 'proxy') {
                $proxy += (int) $row['users'];
            } else {
                $exchange += (int) $row['users'];
            }
        }

        return ['total' => $exchange + $proxy, 'exchange' => $exchange, 'proxy' => $proxy];
    }

    /**
     * Anzahl der Aktivitaetszeilen insgesamt (Diagnose/Anzeige).
     */
    public function activityCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM orvanta_activity');
        $value = $statement === false ? false : $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    // ------------------------------------------------------------------ Proben

    /**
     * Eine Probe ablegen. Existiert fuer denselben Zeitpunkt bereits eine
     * Zeile, bleibt sie unveraendert (kein Ueberschreiben).
     *
     * @return bool true, wenn eine neue Probe geschrieben wurde
     */
    public function recordSample(string $sampledAt, int $activeUsers, int $exchangeUsers, int $proxyUsers, int $aiUsers): bool
    {
        if ($this->hasSampleAt($sampledAt)) {
            return false;
        }

        $this->pdo->prepare(
            'INSERT INTO orvanta_user_samples (sampled_at, active_users, exchange_users, proxy_users, ai_users)'
            . ' VALUES (:at, :active, :exchange, :proxy, :ai)'
        )->execute([
            'at' => $sampledAt,
            'active' => max(0, $activeUsers),
            'exchange' => max(0, $exchangeUsers),
            'proxy' => max(0, $proxyUsers),
            'ai' => max(0, $aiUsers),
        ]);

        return true;
    }

    public function hasSampleAt(string $sampledAt): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM orvanta_user_samples WHERE sampled_at = :at');
        $statement->execute(['at' => $sampledAt]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Zeitpunkt der jüngsten Probe ('' wenn noch keine existiert).
     */
    public function lastSampleAt(): string
    {
        $statement = $this->pdo->query('SELECT MAX(sampled_at) FROM orvanta_user_samples');
        $value = $statement === false ? false : $statement->fetchColumn();

        return $value === false || $value === null ? '' : (string) $value;
    }

    /**
     * Kennzahlen der letzten 24 Stunden aus den Proben. Ohne Proben sind
     * Minimum und Maximum gleich dem aktuellen Wert.
     *
     * @return array{min:int,max:int,avg:float,samples:int}
     */
    public function sampleStats(string $since, string $until): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(MIN(active_users), 0) AS min_users, COALESCE(MAX(active_users), 0) AS max_users,'
            . ' COALESCE(AVG(active_users), 0) AS avg_users, COUNT(*) AS samples'
            . ' FROM orvanta_user_samples WHERE sampled_at >= :since AND sampled_at <= :until'
        );
        $statement->execute(['since' => $since, 'until' => $until]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'min' => (int) ($row['min_users'] ?? 0),
            'max' => (int) ($row['max_users'] ?? 0),
            'avg' => round((float) ($row['avg_users'] ?? 0), 1),
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    /**
     * Tagesmaximum der aktiven Nutzer, nach Tagesschluessel ('Y-m-d').
     *
     * @return array<string,int>
     */
    public function dailyPeaks(string $from, string $until): array
    {
        $statement = $this->pdo->prepare(
            'SELECT SUBSTR(sampled_at, 1, 10) AS day, MAX(active_users) AS peak FROM orvanta_user_samples'
            . ' WHERE sampled_at >= :from AND sampled_at <= :until GROUP BY SUBSTR(sampled_at, 1, 10) ORDER BY day ASC'
        );
        $statement->execute(['from' => $from, 'until' => $until]);

        $peaks = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $peaks[(string) $row['day']] = (int) $row['peak'];
        }

        return $peaks;
    }

    public function sampleCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM orvanta_user_samples');
        $value = $statement === false ? false : $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    // ------------------------------------------------------------------ Raeumen

    /**
     * Alte Aktivitaetszeilen und Proben entfernen.
     *
     * @return array{activity:int,samples:int} Anzahl der geloeschten Zeilen
     */
    public function purge(string $activityBefore, string $samplesBefore): array
    {
        $activity = $this->pdo->prepare('DELETE FROM orvanta_activity WHERE last_seen_at < :before');
        $activity->execute(['before' => $activityBefore]);

        $samples = $this->pdo->prepare('DELETE FROM orvanta_user_samples WHERE sampled_at < :before');
        $samples->execute(['before' => $samplesBefore]);

        return ['activity' => $activity->rowCount(), 'samples' => $samples->rowCount()];
    }
}
