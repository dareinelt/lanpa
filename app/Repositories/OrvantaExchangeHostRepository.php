<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Orvanta: Hosts einer Exchange-DAG (Tabelle orvanta_exchange_hosts) und die
 * Zuordnung laufender Orvanta-Sitzungen zu einem Host (Tabelle
 * orvanta_exchange_sessions).
 *
 * Die Hostliste fuehrt die Lastkennzahlen (mittlere Antwortzeit, Zeitpunkt der
 * letzten zugeteilten Sitzung) fuer die Lastverteilung; die Sitzungstabelle
 * bildet die Sitzungsaffinitaet und Umleitungen (Failover) ab. Das SQL laeuft
 * sowohl auf MySQL als auch auf SQLite (Tests).
 */
final class OrvantaExchangeHostRepository extends Repository
{
    /**
     * Alle Hosts: der primaere Host zuerst, danach in der Reihenfolge der
     * Eintragung.
     *
     * @return list<array<string,mixed>>
     */
    public function hosts(): array
    {
        $rows = $this->pdo->query('SELECT * FROM orvanta_exchange_hosts ORDER BY is_primary DESC, sort_order ASC, host ASC')?->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalize'], $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_exchange_hosts WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalize($row);
    }

    public function hostCount(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM orvanta_exchange_hosts')?->fetchColumn() ?: 0);
    }

    public function nextSortOrder(): int
    {
        return (int) ($this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM orvanta_exchange_hosts')?->fetchColumn() ?: 1);
    }

    public function insert(string $host, string $ewsUrl, bool $primary, int $sortOrder): void
    {
        $statement = $this->pdo->prepare('INSERT INTO orvanta_exchange_hosts (host, ews_url, is_primary, active, sort_order) VALUES (:host, :url, :primary, 1, :sort)');
        $statement->execute(['host' => $host, 'url' => $ewsUrl, 'primary' => $primary ? 1 : 0, 'sort' => $sortOrder]);
    }

    public function setActive(int $id, bool $active): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_exchange_hosts SET active = :active WHERE id = :id');
        $statement->execute(['id' => $id, 'active' => $active ? 1 : 0]);
    }

    /**
     * Entfernt einen Host samt seiner Sitzungszeilen.
     */
    public function deleteHost(int $id, string $host): void
    {
        $this->pdo->prepare('DELETE FROM orvanta_exchange_hosts WHERE id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM orvanta_exchange_sessions WHERE host = :host')->execute(['host' => $host]);
    }

    /**
     * Haelt die Hostliste mit dem in den Orvanta-Einstellungen eingetragenen
     * Server ab: Er ist immer der primaere Host. Ein umbenannter Server
     * ersetzt die bisherige Primaerzeile, damit er nicht als zusaetzlicher
     * Host stehen bleibt.
     */
    public function syncPrimary(string $host, string $ewsUrl): void
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            $this->pdo->exec('DELETE FROM orvanta_exchange_hosts WHERE is_primary = 1');

            return;
        }
        $statement = $this->pdo->prepare('SELECT id FROM orvanta_exchange_hosts WHERE host = :host');
        $statement->execute(['host' => $host]);
        $id = $statement->fetchColumn();
        $this->pdo->prepare('DELETE FROM orvanta_exchange_hosts WHERE is_primary = 1 AND host <> :host')->execute(['host' => $host]);
        if ($id === false) {
            $this->insert($host, $ewsUrl, true, 0);

            return;
        }
        $update = $this->pdo->prepare('UPDATE orvanta_exchange_hosts SET is_primary = 1, ews_url = :url WHERE id = :id');
        $update->execute(['id' => (int) $id, 'url' => $ewsUrl]);
    }

    // ------------------------------------------------------------------ Lastkennzahlen

    /**
     * Uebernimmt eine erfolgreiche Messung (gleitendes Mittel der Antwortzeit).
     */
    public function recordLatency(int $id, int $average, int $samples, int $last, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_exchange_hosts SET latency_ms = :average, latency_samples = :samples, last_latency_ms = :last, last_ok = 1, last_error = \'\', last_check_at = :now, failures = 0 WHERE id = :id');
        $statement->execute(['id' => $id, 'average' => max(0, $average), 'samples' => max(0, $samples), 'last' => max(0, $last), 'now' => $now]);
    }

    public function recordSuccess(int $id, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_exchange_hosts SET last_ok = 1, last_error = \'\', last_check_at = :now, failures = 0 WHERE id = :id');
        $statement->execute(['id' => $id, 'now' => $now]);
    }

    public function recordFailure(int $id, string $error, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_exchange_hosts SET last_ok = 0, last_error = :error, last_check_at = :now, failures = failures + 1 WHERE id = :id');
        $statement->execute(['id' => $id, 'error' => mb_substr($error, 0, 500), 'now' => $now]);
    }

    /** Zeitpunkt der letzten einem Host zugeteilten Sitzung (Fair-use). */
    public function touchHostSession(int $id, string $now): void
    {
        $this->pdo->prepare('UPDATE orvanta_exchange_hosts SET last_session_at = :now WHERE id = :id')->execute(['id' => $id, 'now' => $now]);
    }

    // ------------------------------------------------------------------ Sitzungen

    /**
     * @return array<string,mixed>|null
     */
    public function findSession(string $hash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_exchange_sessions WHERE session_hash = :hash');
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Legt die Zuordnung einer Sitzung an bzw. aktualisiert sie, ohne einen
     * Wechsel (Failover) zu zaehlen.
     */
    public function startSession(string $hash, string $uid, string $host, string $now): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM orvanta_exchange_sessions WHERE session_hash = :hash');
        $statement->execute(['hash' => $hash]);
        if ($statement->fetchColumn() !== false) {
            $update = $this->pdo->prepare('UPDATE orvanta_exchange_sessions SET host = :host, user_uid = :uid, requests = requests + 1, last_seen_at = :now WHERE session_hash = :hash');
            $update->execute(['hash' => $hash, 'host' => $host, 'uid' => mb_substr($uid, 0, 190), 'now' => $now]);

            return;
        }
        // Jeder Platzhalter nur einmal: MySQL ohne emulierte Prepared Statements
        // lehnt doppelte benannte Parameter ab (SQLSTATE HY093).
        $insert = $this->pdo->prepare('INSERT INTO orvanta_exchange_sessions (session_hash, user_uid, host, requests, started_at, last_seen_at) VALUES (:hash, :uid, :host, 1, :started, :seen)');
        $insert->execute(['hash' => $hash, 'uid' => mb_substr($uid, 0, 190), 'host' => $host, 'started' => $now, 'seen' => $now]);
    }

    /**
     * Vermerkt IP-Adresse und Hostname des Clients an der Sitzungszeile
     * (Migration 044). Beide Werte beschreiben den Beginn der Sitzung und
     * bleiben bei einer Umleitung unveraendert.
     */
    public function storeSessionClient(string $hash, string $clientIp, string $clientHost): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_exchange_sessions SET client_ip = :ip, client_host = :client_host WHERE session_hash = :hash');
        $statement->execute([
            'hash' => $hash,
            'ip' => mb_substr(trim($clientIp), 0, 45),
            'client_host' => mb_substr(trim($clientHost), 0, 190),
        ]);
    }

    /**
     * Leitet eine Sitzung auf einen anderen Host um (Failover).
     */
    public function moveSession(string $hash, string $host, string $now): void
    {
        $statement = $this->pdo->prepare('UPDATE orvanta_exchange_sessions SET host = :host, failovers = failovers + 1, requests = requests + 1, last_seen_at = :now WHERE session_hash = :hash');
        $statement->execute(['hash' => $hash, 'host' => $host, 'now' => $now]);
    }

    /**
     * Haelt eine bestehende Zuordnung am Leben (Sitzungsaffinitaet).
     * $countRequest = false aktualisiert nur die letzte Aktivitaet (z. B.
     * Keep-alive ohne Exchange-Aufruf).
     */
    public function touchSession(string $hash, string $now, bool $countRequest = true): void
    {
        $sql = $countRequest
            ? 'UPDATE orvanta_exchange_sessions SET requests = requests + 1, last_seen_at = :now WHERE session_hash = :hash'
            : 'UPDATE orvanta_exchange_sessions SET last_seen_at = :now WHERE session_hash = :hash';
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['hash' => $hash, 'now' => $now]);
    }

    /**
     * Verbundene Sitzungen je Host (Sitzungen ohne Aktivitaet seit $since
     * gelten als beendet).
     *
     * @return array<string,int>
     */
    public function sessionCounts(string $since): array
    {
        $statement = $this->pdo->prepare('SELECT host, COUNT(*) AS sessions FROM orvanta_exchange_sessions WHERE last_seen_at >= :since GROUP BY host');
        $statement->execute(['since' => $since]);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $counts[(string) $row['host']] = (int) $row['sessions'];
        }

        return $counts;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeSessions(string $since, int $limit = 100): array
    {
        // SELECT *: die Liste bleibt auch ohne die Clientspalten der
        // Migration 044 nutzbar (nur ohne IP und Hostname des Clients).
        $statement = $this->pdo->prepare('SELECT * FROM orvanta_exchange_sessions WHERE last_seen_at >= :since ORDER BY last_seen_at DESC LIMIT ' . max(1, $limit));
        $statement->execute(['since' => $since]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function purgeSessions(string $before): int
    {
        $statement = $this->pdo->prepare('DELETE FROM orvanta_exchange_sessions WHERE last_seen_at < :before');
        $statement->execute(['before' => $before]);

        return $statement->rowCount();
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        foreach (['id', 'is_primary', 'active', 'sort_order', 'latency_ms', 'latency_samples', 'last_latency_ms', 'last_ok', 'failures'] as $key) {
            $row[$key] = (int) ($row[$key] ?? 0);
        }
        foreach (['host', 'ews_url', 'last_error'] as $key) {
            $row[$key] = (string) ($row[$key] ?? '');
        }
        foreach (['last_session_at', 'last_check_at'] as $key) {
            $value = $row[$key] ?? null;
            $row[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $row;
    }
}
