<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Katalog von storage-sync in MySQL (eigener Container storage-sync-catalog):
 * alle Dateien mit Version, Zustand (Hot-/Cold-Tier), Stand je Speicherziel,
 * ausstehende Loesch-/Umbenennungsauftraege, Zugriffe, Vorfallerkennung und
 * Dateiversionen (Snapshots).
 *
 * Mehrere Prozesse (monitor, sync, recall, recall-one) nutzen ihn parallel:
 * - InnoDB mit READ COMMITTED (Zeilensperren, keine Lueckensperren).
 * - Pfade als VARBINARY (bytegenau wie im Dateisystem), Suche ueber einen
 *   festen 32-Byte-Schluessel (SHA-256 des Pfads) statt langer Textindizes.
 * - Mehrzeilen-Transaktionen laufen unter einer Redis-Sperre
 *   (CatalogCoordinator), Einzelanweisungen werden bei Deadlock/Sperr-
 *   Zeitueberschreitung wiederholt.
 * - I/O-Zaehler liegen im Koordinator (Redis), nicht in MySQL.
 *
 * SQLite wird nur noch im Arbeitsspeicher (Tests) und zum einmaligen Import
 * eines alten catalog.sqlite gelesen - nie als Datei geschrieben.
 */
final class Catalog
{
    public const STATE_LOCAL = 'local';
    public const STATE_EVICTED = 'evicted';

    /** Zaehler-ID fuer den Hot-Tier (lokales Storage). */
    public const LOCAL = 0;

    /** Zaehler-ID fuer den Snapshot-Speicher (Dateiversionen). */
    public const SNAPSHOT = -1;

    public const SNAPSHOT_PENDING = 'pending';
    public const SNAPSHOT_COMPLETE = 'complete';
    public const SNAPSHOT_FAILED = 'failed';
    public const SNAPSHOT_UNAVAILABLE = 'unavailable';
    public const SNAPSHOT_DELETED = 'deleted';

    /** Version des Tabellenschemas (meta schema_version). */
    public const SCHEMA_VERSION = 1;

    /** Groesse der Stapel fuer Abgleich und Import. */
    public const BATCH = 500;

    private const SNAPSHOT_FIELDS = ['file_id', 'path', 'user', 'sha256', 'status', 'error', 'attempts', 'next_attempt', 'stored_at', 'restored_at', 'restored_by'];

    /** Interne Schluesselspalten (binaer), werden nicht an Aufrufer gegeben. */
    private const HIDDEN = ['path_hash', 'client_hash'];

    private const MAX_RETRIES = 5;

    private readonly bool $mysql;

    private readonly CatalogCoordinator $coordinator;

    /** @var array<string,PDOStatement> */
    private array $statements = [];

    public function __construct(private readonly PDO $pdo, ?CatalogCoordinator $coordinator = null)
    {
        $this->coordinator = $coordinator ?? new LocalCoordinator();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
                if ((string) ($database['file'] ?? '') !== '') {
                    throw new RuntimeException('Der Katalog von storage-sync darf nicht in eine SQLite-Datei geschrieben werden (MySQL-Container storage-sync-catalog verwenden).');
                }
            }
        } elseif ($driver !== 'mysql') {
            throw new RuntimeException('Nicht unterstuetzte Datenbank fuer den Katalog: ' . $driver);
        }
        $this->mysql = $driver === 'mysql';
        if ($this->mysql) {
            $pdo->exec("SET SESSION transaction_isolation = 'READ-COMMITTED', innodb_lock_wait_timeout = 60,
                sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        $this->migrate();
    }

    /**
     * Verbindung zum MySQL-Container storage-sync-catalog.
     *
     * @param array{host:string,port:int,database:string,username:string,password:string} $config
     */
    public static function connect(array $config, ?CatalogCoordinator $coordinator = null): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 10,
            ]);
        } catch (PDOException $exception) {
            throw new RuntimeException('Katalog-Datenbank ' . $config['host'] . ':' . $config['port'] . ' nicht erreichbar: ' . $exception->getMessage(), 0, $exception);
        }

        return new self($pdo, $coordinator);
    }

    /**
     * Fluechtiger Katalog im Arbeitsspeicher (Tests).
     */
    public static function memory(?CatalogCoordinator $coordinator = null): self
    {
        return new self(new PDO('sqlite::memory:'), $coordinator);
    }

    /**
     * Schluessel eines Pfads (SHA-256, binaer).
     */
    public static function hash(string $path): string
    {
        return hash('sha256', $path, true);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function isMysql(): bool
    {
        return $this->mysql;
    }

    public function coordinator(): CatalogCoordinator
    {
        return $this->coordinator;
    }

    /**
     * Mehrzeilen-Transaktion unter der prozessuebergreifenden Katalogsperre.
     * Mit $retry wird sie bei Deadlock/Sperr-Zeitueberschreitung wiederholt -
     * nur fuer Rueckrufe ohne Nebenwirkungen ausserhalb der Datenbank.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function transaction(callable $callback, bool $retry = false): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        return $this->coordinator->exclusive('catalog', function () use ($callback, $retry): mixed {
            for ($attempt = 1; ; $attempt++) {
                $this->pdo->beginTransaction();
                try {
                    $result = $callback();
                    $this->pdo->commit();

                    return $result;
                } catch (\Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    if (!$retry || $attempt >= self::MAX_RETRIES || !self::retryable($exception)) {
                        throw $exception;
                    }
                    usleep(random_int(5000, 50000) * $attempt);
                }
            }
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $source, string $path): ?array
    {
        return $this->one('SELECT * FROM files WHERE source = ? AND path_hash = ?', [$source, self::hash($path)]);
    }

    /**
     * Mehrere Pfade einer Quelle in einer Abfrage (Abgleich in Stapeln).
     *
     * @param list<string> $paths
     *
     * @return array<string,array<string,mixed>> Pfad => Zeile (nur vorhandene)
     */
    public function findMany(string $source, array $paths): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($paths)), self::BATCH) as $chunk) {
            $rows = $this->all(
                'SELECT * FROM files WHERE source = ? AND path_hash IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                array_merge([$source], array_map(self::hash(...), $chunk))
            );
            foreach ($rows as $row) {
                $result[(string) $row['path']] = $row;
            }
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        return $this->one('SELECT * FROM files WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function findByInode(string $source, int $inode): array
    {
        return $this->all('SELECT * FROM files WHERE source = ? AND inode = ?', [$source, $inode]);
    }

    public function insert(string $source, string $path, int $size, int $mtime, int $inode, int $now, int $seen, string $state = self::STATE_LOCAL, ?string $sha = null): int
    {
        $this->run(
            'INSERT INTO files (source, path_hash, path, size, mtime, inode, version, sha256, state, tiered, last_access, changed_at, seen)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
            [$source, self::hash($path), $path, $size, $mtime, $inode, $sha, $state, PathRules::isTiered($source, $path) ? 1 : 0, $mtime, $now, $seen]
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Inhalt geaendert: neue Version, liegt im Hot-Tier.
     */
    public function changed(int $id, int $size, int $mtime, int $inode, int $now, int $seen): void
    {
        $this->run(
            "UPDATE files SET size = ?, mtime = ?, inode = ?, version = version + 1, sha256 = NULL, state = 'local',
                    evict_reason = NULL, changed_at = ?, seen = ? WHERE id = ?",
            [$size, $mtime, $inode, $now, $seen, $id]
        );
    }

    public function touchSeen(int $id, int $inode, int $seen): void
    {
        $this->run('UPDATE files SET inode = ?, seen = ? WHERE id = ?', [$inode, $seen, $id]);
    }

    /**
     * Unveraenderte Dateien eines Stapels als gesehen markieren (eine Anweisung).
     *
     * @param list<int> $ids
     */
    public function touchSeenMany(array $ids, int $seen): void
    {
        foreach (array_chunk(array_values(array_unique($ids)), self::BATCH) as $chunk) {
            $this->run(
                'UPDATE files SET seen = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                array_merge([$seen], $chunk)
            );
        }
    }

    /**
     * Nur der Zeitstempel hat sich geaendert (Inhalt unveraendert).
     */
    public function touchMtime(int $id, int $mtime, int $inode, int $seen): void
    {
        $this->run('UPDATE files SET mtime = ?, inode = ?, seen = ? WHERE id = ?', [$mtime, $inode, $seen, $id]);
    }

    /**
     * Administrative Wiederherstellung einer Dateiversion: neue Katalogversion
     * (damit der Cold-Tier den Stand erhaelt), Pruefsumme bekannt – ohne dass
     * daraus eine Benutzeraenderung (und damit ein Snapshot) wird.
     */
    public function restored(int $id, int $size, int $mtime, int $inode, string $sha, int $now): void
    {
        $this->run(
            "UPDATE files SET size = ?, mtime = ?, inode = ?, version = version + 1, sha256 = ?, state = 'local',
                    evict_reason = NULL, changed_at = ? WHERE id = ?",
            [$size, $mtime, $inode, $sha, $now, $id]
        );
    }

    public function rename(int $id, string $newPath, int $seen): void
    {
        $file = $this->get($id);
        $hash = self::hash($newPath);
        $this->run('DELETE FROM files WHERE source = ? AND path_hash = ? AND id <> ?', [$file['source'] ?? '', $hash, $id]);
        $this->run(
            'UPDATE files SET path_hash = ?, path = ?, tiered = ?, seen = ? WHERE id = ?',
            [$hash, $newPath, PathRules::isTiered((string) ($file['source'] ?? ''), $newPath) ? 1 : 0, $seen, $id]
        );
        // Vorgaengerversionen folgen der Datei (kein neuer Inhalts-Snapshot).
        $this->run('UPDATE snapshots SET path_hash = ?, path = ?, mirrored = 0 WHERE file_id = ?', [$hash, $newPath, $id]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM files WHERE id = ?', [$id]);
        $this->run('DELETE FROM target_files WHERE file_id = ?', [$id]);
        $this->run('DELETE FROM access WHERE file_id = ?', [$id]);
        // Snapshots bleiben erhalten (Datei geloescht), verlieren nur den Bezug.
        $this->run('UPDATE snapshots SET file_id = NULL WHERE file_id = ?', [$id]);
    }

    // --- Snapshots (Vorgaengerversionen) ------------------------------------

    /**
     * @param array<string,mixed> $row uid, file_id, source, path, user, version, size, mtime, sha256, status, error, created_at
     */
    public function addSnapshot(array $row): bool
    {
        $statement = $this->run(
            $this->insertIgnore('snapshots', ['uid', 'file_id', 'source', 'path_hash', 'path', 'user', 'version', 'size', 'mtime', 'sha256', 'status', 'error', 'created_at', 'mirrored'], 'uid'),
            [
                (string) $row['uid'], $row['file_id'] ?? null, (string) $row['source'], self::hash((string) $row['path']), (string) $row['path'],
                (string) ($row['user'] ?? ''), (int) $row['version'], (int) $row['size'], (int) $row['mtime'], $row['sha256'] ?? null,
                (string) $row['status'], self::clip((string) ($row['error'] ?? '')), (int) $row['created_at'], 0,
            ]
        );

        return $statement->rowCount() === 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function snapshot(string $uid): ?array
    {
        return $this->one('SELECT * FROM snapshots WHERE uid = ?', [$uid]);
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateSnapshot(string $uid, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::SNAPSHOT_FIELDS));
        if ($values === []) {
            return;
        }
        if (array_key_exists('error', $values)) {
            $values['error'] = self::clip((string) $values['error']);
        }
        if (array_key_exists('path', $values)) {
            $values['path_hash'] = self::hash((string) $values['path']);
        }
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        $params[] = $uid;
        $this->run('UPDATE snapshots SET ' . implode(', ', $sets) . ', mirrored = 0 WHERE uid = ?', $params);
    }

    /**
     * Noch nicht gesicherte Vorgaengerversionen einer Datei (aelteste zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public function pendingSnapshots(string $source, string $path): array
    {
        return $this->all(
            "SELECT * FROM snapshots WHERE source = ? AND path_hash = ? AND status = 'pending' ORDER BY version ASC",
            [$source, self::hash($path)]
        );
    }

    /**
     * Gesicherte Versionen einer Datei (neueste zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public function snapshotsFor(string $source, string $path, int $limit = 100): array
    {
        return $this->all(
            "SELECT * FROM snapshots WHERE source = ? AND path_hash = ? AND status = 'complete' ORDER BY created_at DESC, version DESC LIMIT " . max(1, $limit),
            [$source, self::hash($path)]
        );
    }

    /**
     * Liste fuer die Kommandozeile (neueste zuerst, optional nach Pfad gefiltert).
     *
     * @return list<array<string,mixed>>
     */
    public function snapshots(?string $pathLike = null, int $limit = 50): array
    {
        if ($pathLike === null || $pathLike === '') {
            return $this->all("SELECT * FROM snapshots WHERE status <> 'deleted' ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit));
        }

        return $this->all(
            "SELECT * FROM snapshots WHERE status <> 'deleted' AND instr(path, ?) > 0 ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit),
            [$pathLike]
        );
    }

    /**
     * Gesicherte Versionen, die aelter als der Zeitpunkt sind.
     *
     * @return list<array<string,mixed>>
     */
    public function snapshotsBefore(int $createdBefore, int $limit = 500): array
    {
        return $this->all(
            "SELECT * FROM snapshots WHERE status = 'complete' AND created_at < ? ORDER BY created_at ASC LIMIT " . max(1, $limit),
            [$createdBefore]
        );
    }

    /**
     * Ueberzaehlige Versionen je Datei (aelteste zuerst), wenn mehr als $keep vorhanden sind.
     *
     * @return list<array<string,mixed>>
     */
    public function snapshotsExceeding(int $keep, int $limit = 500): array
    {
        return $this->all(
            "SELECT s.* FROM snapshots s
             WHERE s.status = 'complete' AND (
                SELECT COUNT(*) FROM snapshots n
                WHERE n.source = s.source AND n.path_hash = s.path_hash AND n.status = 'complete'
                  AND (n.created_at > s.created_at OR (n.created_at = s.created_at AND n.version > s.version))
             ) >= " . max(0, $keep) . ' ORDER BY s.created_at ASC LIMIT ' . max(1, $limit)
        );
    }

    /**
     * Fehlgeschlagene Sicherungen erneut einplanen.
     */
    public function retrySnapshots(): int
    {
        return $this->run("UPDATE snapshots SET status = 'pending', next_attempt = 0, error = '', mirrored = 0 WHERE status = 'failed'")->rowCount();
    }

    /**
     * Vormerkungen, die seit der Haltefrist nicht gesichert werden konnten, aufgeben.
     */
    public function expirePendingSnapshots(int $createdBefore): int
    {
        return $this->run(
            "UPDATE snapshots SET status = 'failed', mirrored = 0,
                    error = CASE WHEN error = '' THEN ? ELSE error END
             WHERE status = 'pending' AND created_at < ?",
            ['Die bisherige Version konnte nicht mehr gesichert werden.', $createdBefore]
        )->rowCount();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function unmirroredSnapshots(int $limit = 500): array
    {
        return $this->all('SELECT * FROM snapshots WHERE mirrored = 0 ORDER BY id ASC LIMIT ' . max(1, $limit));
    }

    /**
     * @param list<string> $uids
     */
    public function markSnapshotsMirrored(array $uids): void
    {
        foreach (array_chunk(array_values($uids), self::BATCH) as $chunk) {
            $this->run(
                'UPDATE snapshots SET mirrored = 1 WHERE mirrored = 0 AND uid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            );
        }
    }

    public function removeSnapshot(string $uid): void
    {
        $this->run('DELETE FROM snapshots WHERE uid = ?', [$uid]);
    }

    /**
     * Versionen einer (wieder angelegten) Datei erneut mit ihr verknuepfen.
     */
    public function relinkSnapshots(string $source, string $path, int $fileId): void
    {
        $this->run('UPDATE snapshots SET file_id = ?, mirrored = 0 WHERE source = ? AND path_hash = ? AND file_id IS NULL', [$fileId, $source, self::hash($path)]);
    }

    /**
     * @return array{complete:int,bytes:int,pending:int,failed:int,unavailable:int,last_stored_at:?int}
     */
    public function snapshotStats(): array
    {
        $row = $this->one(
            "SELECT SUM(status = 'complete') AS complete, COALESCE(SUM(CASE WHEN status = 'complete' THEN size ELSE 0 END), 0) AS bytes,
                    SUM(status = 'pending') AS pending, SUM(status = 'failed') AS failed, SUM(status = 'unavailable') AS unavailable,
                    MAX(stored_at) AS last_stored_at
             FROM snapshots"
        ) ?? [];

        return [
            'complete' => (int) ($row['complete'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'unavailable' => (int) ($row['unavailable'] ?? 0),
            'last_stored_at' => isset($row['last_stored_at']) ? (int) $row['last_stored_at'] : null,
        ];
    }

    // --- Vorfallerkennung (ThreatDetector) -----------------------------------

    /**
     * Letzter bekannter Schreiber eines Pfads (Nextcloud-Schreibprotokoll).
     */
    public function lastWriter(string $path): string
    {
        return (string) ($this->value('SELECT uid FROM writes WHERE path_hash = ?', [self::hash($path)]) ?? '');
    }

    /**
     * Schreibprotokoll aus Nextcloud uebernehmen (eine Transaktion je Stapel).
     *
     * @param list<array{t:int,u:string,ip:string,ua:string,p:string}> $entries
     */
    public function ingestWrites(array $entries): void
    {
        $write = $this->upsert(
            'writes',
            ['path_hash', 'path', 'uid', 'ip', 'ua', 'at'],
            ['path_hash'],
            // "at" zuletzt: MySQL wertet die Zuweisungen der Reihe nach aus.
            [
                'uid' => 'CASE WHEN @at >= writes.at THEN @uid ELSE writes.uid END',
                'ip' => 'CASE WHEN @at >= writes.at THEN @ip ELSE writes.ip END',
                'ua' => 'CASE WHEN @at >= writes.at THEN @ua ELSE writes.ua END',
                'at' => $this->greatest() . '(writes.at, @at)',
            ]
        );
        $client = $this->upsert(
            'clients',
            ['client_hash', 'uid', 'ip', 'ua', 'first_at', 'last_at', 'writes'],
            ['client_hash'],
            ['last_at' => $this->greatest() . '(clients.last_at, @last_at)', 'writes' => 'clients.writes + 1']
        );
        foreach (array_chunk($entries, self::BATCH) as $chunk) {
            $this->transaction(function () use ($chunk, $write, $client): void {
                foreach ($chunk as $entry) {
                    if ($entry['u'] === '' || $entry['p'] === '') {
                        continue;
                    }
                    $uid = substr($entry['u'], 0, 255);
                    $ip = substr($entry['ip'], 0, 255);
                    $this->run($write, [self::hash($entry['p']), $entry['p'], $uid, $ip, $entry['ua'], $entry['t']]);
                    $this->run($client, [hash('sha256', $uid . "\0" . $ip . "\0" . $entry['ua'], true), $uid, $ip, $entry['ua'], $entry['t'], $entry['t'], 1]);
                }
            }, true);
        }
    }

    public function recordActivity(string $owner, int $at, string $kind, string $path, int $size, string $detail, bool $changed): void
    {
        $this->run(
            'INSERT INTO activity (owner, at, kind, path_hash, path, size, detail, changed) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [substr($owner, 0, 255), $at, $kind, self::hash($path), $path, $size, substr($detail, 0, 1024), $changed ? 1 : 0]
        );
    }

    public function pruneActivity(int $before): void
    {
        $this->run('DELETE FROM activity WHERE at < ?', [$before]);
        $this->run('DELETE FROM writes WHERE at < ?', [$before]);
        $this->run('DELETE FROM clients WHERE last_at < ?', [$before]);
    }

    /**
     * Aktivitaet je Benutzer seit $since (Zuordnung ueber das Schreibprotokoll).
     *
     * @return list<array{who:string,changed:int,content:int,extension:int}>
     */
    public function activityByUser(int $since): array
    {
        return array_map(static fn (array $row): array => [
            'who' => (string) $row['who'], 'changed' => (int) $row['changed'], 'content' => (int) $row['content'], 'extension' => (int) $row['extension'],
        ], $this->all(
            "SELECT COALESCE(w.uid, a.owner) AS who,
                    SUM(a.changed) AS changed,
                    SUM(CASE WHEN a.kind = 'content' THEN 1 ELSE 0 END) AS content,
                    SUM(CASE WHEN a.kind = 'extension' THEN 1 ELSE 0 END) AS extension
             FROM activity a LEFT JOIN writes w ON w.path_hash = a.path_hash
             WHERE a.at >= ? GROUP BY COALESCE(w.uid, a.owner)",
            [$since]
        ));
    }

    /**
     * Kennzahlen, Beispiele und Gruppierungen der Aktivitaet eines Benutzers.
     *
     * @return array{totals:array<string,mixed>,samples:list<array<string,mixed>>,patterns:array<string,int>,reasons:array<string,int>,owners:array<string,int>,clients:list<array<string,mixed>>}
     */
    public function activityOf(string $user, int $since, int $samples): array
    {
        $scope = 'FROM activity a LEFT JOIN writes w ON w.path_hash = a.path_hash WHERE a.at >= ? AND (w.uid = ? OR (w.uid IS NULL AND a.owner = ?))';
        $params = [$since, $user, $user];
        $grouped = function (string $column, string $kind) use ($scope, $params): array {
            $result = [];
            $rows = $this->all(
                'SELECT ' . $column . ' AS k, COUNT(*) AS n ' . $scope . ($kind !== '' ? ' AND a.kind = ?' : '')
                . ' GROUP BY ' . $column . ' ORDER BY n DESC LIMIT 20',
                $kind !== '' ? array_merge($params, [$kind]) : $params
            );
            foreach ($rows as $row) {
                $result[(string) $row['k']] = (int) $row['n'];
            }

            return $result;
        };

        return [
            'totals' => $this->one(
                "SELECT COUNT(*) AS rows_total, COALESCE(SUM(a.changed), 0) AS changed,
                        SUM(CASE WHEN a.kind = 'content' THEN 1 ELSE 0 END) AS content,
                        SUM(CASE WHEN a.kind = 'extension' THEN 1 ELSE 0 END) AS extension,
                        COALESCE(SUM(a.size), 0) AS bytes, MIN(a.at) AS first_at, MAX(a.at) AS last_at,
                        SUM(CASE WHEN w.uid IS NOT NULL THEN 1 ELSE 0 END) AS attributed " . $scope,
                $params
            ) ?? [],
            'samples' => $this->all(
                'SELECT a.path, a.kind, a.detail, a.size, a.at ' . $scope . "
                 ORDER BY CASE a.kind WHEN 'extension' THEN 0 WHEN 'content' THEN 1 ELSE 2 END, a.at DESC LIMIT " . max(1, $samples),
                $params
            ),
            'patterns' => $grouped('a.detail', 'extension'),
            'reasons' => $grouped('a.detail', 'content'),
            'owners' => $grouped('a.owner', ''),
            'clients' => $this->all(
                'SELECT ip, ua, writes, last_at FROM clients WHERE uid = ? AND last_at >= ? ORDER BY writes DESC, last_at DESC LIMIT 5',
                [$user, $since]
            ),
        ];
    }

    // --- Dateizustand und Ziele ----------------------------------------------

    public function setState(int $id, string $state, ?string $reason, int $inode): void
    {
        $this->run('UPDATE files SET state = ?, evict_reason = ?, inode = ? WHERE id = ?', [$state, $reason, $inode, $id]);
    }

    public function setHash(int $id, int $version, string $sha): void
    {
        $this->run('UPDATE files SET sha256 = ? WHERE id = ? AND version = ?', [$sha, $id, $version]);
    }

    /**
     * Dateien einer Quelle, die im aktuellen Durchlauf nicht gesehen wurden.
     *
     * @return list<array<string,mixed>>
     */
    public function unseen(string $source, int $generation, string $prefix = ''): array
    {
        if ($prefix === '') {
            return $this->all('SELECT * FROM files WHERE source = ? AND seen < ?', [$source, $generation]);
        }

        return $this->all(
            'SELECT * FROM files WHERE source = ? AND seen < ? AND substr(path, 1, ?) = ?',
            [$source, $generation, strlen($prefix), $prefix]
        );
    }

    public function count(?string $source = null): int
    {
        return (int) ($source === null
            ? $this->value('SELECT COUNT(*) FROM files')
            : $this->value('SELECT COUNT(*) FROM files WHERE source = ?', [$source]));
    }

    /**
     * @param int $memberId Ziel innerhalb des Cold-Tiers (Basisziel/Erweiterung), 0 = Basisziel
     */
    public function markSynced(int $targetId, int $fileId, int $version, int $memberId = 0): void
    {
        $this->run(
            $this->upsert('target_files', ['target_id', 'file_id', 'version', 'member_id'], ['target_id', 'file_id'], ['version' => '@version', 'member_id' => '@member_id']),
            [$targetId, $fileId, $version, $memberId === $targetId ? 0 : $memberId]
        );
    }

    public function unsync(int $targetId, int $fileId): void
    {
        $this->run('DELETE FROM target_files WHERE target_id = ? AND file_id = ?', [$targetId, $fileId]);
    }

    public function hasOnTarget(int $targetId, int $fileId): bool
    {
        return $this->value('SELECT 1 FROM target_files WHERE target_id = ? AND file_id = ? AND version > 0', [$targetId, $fileId]) !== null;
    }

    /**
     * Welche Katalogversion liegt auf dem Ziel (0 = keine)?
     */
    public function targetVersion(int $targetId, int $fileId): int
    {
        return (int) ($this->value('SELECT version FROM target_files WHERE target_id = ? AND file_id = ?', [$targetId, $fileId]) ?? 0);
    }

    /**
     * Speicherziele, die die aktuelle Version einer Datei vollstaendig haben.
     *
     * @return list<int>
     */
    public function targetsWithCurrent(int $fileId): array
    {
        $rows = $this->all(
            'SELECT tf.target_id FROM target_files tf JOIN files f ON f.id = tf.file_id
             WHERE tf.file_id = ? AND tf.version = f.version ORDER BY tf.target_id',
            [$fileId]
        );

        return array_map(static fn (array $r): int => (int) $r['target_id'], $rows);
    }

    /**
     * Fuer ein Ziel ausstehende Dateien (aelteste Aenderung zuerst), seitenweise
     * nach [changed_at, id] der letzten Zeile.
     *
     * @param array{0:int,1:int}|null $after
     *
     * @return list<array<string,mixed>>
     */
    public function pending(int $targetId, int $limit = 500, ?array $after = null): array
    {
        $cursor = '';
        $params = [$targetId];
        if ($after !== null) {
            $cursor = ' AND (f.changed_at > ? OR (f.changed_at = ? AND f.id > ?))';
            array_push($params, $after[0], $after[0], $after[1]);
        }

        return $this->all(
            'SELECT f.* FROM files f LEFT JOIN target_files tf ON tf.file_id = f.id AND tf.target_id = ?
             WHERE COALESCE(tf.version, 0) < f.version' . $cursor . ' ORDER BY f.changed_at ASC, f.id ASC LIMIT ' . max(1, $limit),
            $params
        );
    }

    /**
     * @return array{files:int,bytes:int,oldest:?int}
     */
    public function pendingStats(int $targetId): array
    {
        $row = $this->one(
            'SELECT COUNT(*) AS files, COALESCE(SUM(f.size), 0) AS bytes, MIN(f.changed_at) AS oldest
             FROM files f LEFT JOIN target_files tf ON tf.file_id = f.id AND tf.target_id = ?
             WHERE COALESCE(tf.version, 0) < f.version',
            [$targetId]
        ) ?? [];
        $ops = $this->one('SELECT COUNT(*) AS files, MIN(created_at) AS oldest FROM ops WHERE target_id = ?', [$targetId]) ?? [];
        $oldest = array_filter([$row['oldest'] ?? null, $ops['oldest'] ?? null], static fn ($v): bool => $v !== null);

        return [
            'files' => (int) ($row['files'] ?? 0) + (int) ($ops['files'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'oldest' => $oldest === [] ? null : (int) min($oldest),
        ];
    }

    /**
     * @return array{files:int,bytes:int}
     */
    public function syncedStats(int $targetId): array
    {
        $row = $this->one(
            'SELECT COUNT(*) AS files, COALESCE(SUM(f.size), 0) AS bytes FROM target_files tf
             JOIN files f ON f.id = tf.file_id AND tf.version = f.version WHERE tf.target_id = ?',
            [$targetId]
        ) ?? [];

        return ['files' => (int) ($row['files'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }

    /**
     * Synchronisierte Dateien je Ziel eines Cold-Tiers (Kennung des Ziels;
     * Dateien auf dem Basisziel unter der Kennung des Tiers).
     *
     * @return array<int,array{files:int,bytes:int}>
     */
    public function memberStats(int $targetId): array
    {
        $rows = $this->all(
            'SELECT tf.member_id, COUNT(*) AS files, COALESCE(SUM(f.size), 0) AS bytes FROM target_files tf
             JOIN files f ON f.id = tf.file_id AND tf.version = f.version WHERE tf.target_id = ? GROUP BY tf.member_id',
            [$targetId]
        );
        $result = [];
        foreach ($rows as $row) {
            $member = (int) $row['member_id'] === 0 ? $targetId : (int) $row['member_id'];
            $result[$member] = [
                'files' => ($result[$member]['files'] ?? 0) + (int) $row['files'],
                'bytes' => ($result[$member]['bytes'] ?? 0) + (int) $row['bytes'],
            ];
        }

        return $result;
    }

    public function addOp(int $targetId, string $op, string $source, string $path, ?string $newPath, int $now): void
    {
        $this->run(
            'INSERT INTO ops (target_id, op, source, path, new_path, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$targetId, $op, $source, $path, $newPath, $now]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function ops(int $targetId, int $limit = 1000): array
    {
        return $this->all('SELECT * FROM ops WHERE target_id = ? ORDER BY id ASC LIMIT ' . max(1, $limit), [$targetId]);
    }

    public function removeOp(int $id): void
    {
        $this->run('DELETE FROM ops WHERE id = ?', [$id]);
    }

    /**
     * Entfernt Stand und Auftraege nicht mehr eingerichteter Ziele.
     *
     * @param list<int> $keep
     */
    public function forgetTargets(array $keep): void
    {
        $keep = array_values(array_map('intval', $keep));
        $list = $keep === [] ? '-1' : implode(',', $keep);
        $this->run('DELETE FROM target_files WHERE target_id NOT IN (' . $list . ')');
        $this->run('DELETE FROM ops WHERE target_id NOT IN (' . $list . ')');
        $this->coordinator->forgetCounters($keep);
    }

    public function recordAccess(int $fileId, int $timestamp): void
    {
        $this->run('UPDATE files SET last_access = ' . $this->greatest() . '(last_access, CAST(? AS ' . ($this->mysql ? 'SIGNED' : 'INTEGER') . ')) WHERE id = ?', [$timestamp, $fileId]);
        $this->run($this->insertIgnore('access', ['file_id', 'day'], 'file_id'), [$fileId, intdiv($timestamp, 86400)]);
    }

    /**
     * Zugriffsprotokoll von Nextcloud in einer Transaktion uebernehmen.
     *
     * @param array<string,int> $accesses relativer Pfad => Zeitpunkt
     */
    public function recordAccesses(string $source, array $accesses): void
    {
        foreach (array_chunk($accesses, self::BATCH, true) as $chunk) {
            $this->transaction(function () use ($source, $chunk): void {
                foreach ($this->findMany($source, array_map('strval', array_keys($chunk))) as $path => $file) {
                    $this->recordAccess((int) $file['id'], (int) $chunk[$path]);
                }
            }, true);
        }
    }

    public function pruneAccess(int $now): void
    {
        $this->run('DELETE FROM access WHERE day < ?', [intdiv($now, 86400) - 31]);
    }

    /**
     * Dateien im Hot-Tier, die ausgelagert werden koennten (inkl. Zugriffstage
     * der letzten 30 Tage und Anzahl der Ziele mit aktueller Version).
     *
     * @param list<int> $targets
     *
     * @return list<array<string,mixed>>
     */
    public function evictable(array $targets, int $now, int $limit = 2000): array
    {
        if ($targets === []) {
            return [];
        }
        $list = implode(',', array_map('intval', $targets));

        return $this->all(
            'SELECT f.*, ' . $this->greatest() . "(f.mtime, f.last_access) AS activity,
                    (SELECT COUNT(*) FROM access a WHERE a.file_id = f.id AND a.day >= ?) AS access_days,
                    (SELECT COUNT(*) FROM target_files tf WHERE tf.file_id = f.id AND tf.version = f.version
                        AND tf.target_id IN ($list)) AS synced_targets
             FROM files f
             WHERE f.tiered = 1 AND f.state = 'local' AND f.size >= ?
             ORDER BY activity ASC, f.size DESC LIMIT " . max(1, $limit),
            [intdiv($now, 86400) - 30, PathRules::MIN_TIER_SIZE]
        );
    }

    /**
     * Ausgelagerte Dateien, die (wieder) in den Hot-Tier gehoeren koennten.
     *
     * @return list<array<string,mixed>>
     */
    public function evicted(?string $reason, int $now, int $limit = 200): array
    {
        $params = [intdiv($now, 86400) - 30];
        $where = '';
        if ($reason !== null) {
            $where = ' AND f.evict_reason = ?';
            $params[] = $reason;
        }

        return $this->all(
            'SELECT f.*, ' . $this->greatest() . "(f.mtime, f.last_access) AS activity,
                    (SELECT COUNT(*) FROM access a WHERE a.file_id = f.id AND a.day >= ?) AS access_days
             FROM files f WHERE f.state = 'evicted'" . $where . '
             ORDER BY activity DESC LIMIT ' . max(1, $limit),
            $params
        );
    }

    /**
     * @return array{files_total:int,bytes_total:int,files_local:int,bytes_local:int,files_evicted:int,bytes_evicted:int}
     */
    public function totals(): array
    {
        $row = $this->one(
            "SELECT COUNT(*) AS files_total, COALESCE(SUM(size), 0) AS bytes_total,
                    COALESCE(SUM(CASE WHEN state = 'local' THEN 1 ELSE 0 END), 0) AS files_local,
                    COALESCE(SUM(CASE WHEN state = 'local' THEN size ELSE 0 END), 0) AS bytes_local,
                    COALESCE(SUM(CASE WHEN state = 'evicted' THEN 1 ELSE 0 END), 0) AS files_evicted,
                    COALESCE(SUM(CASE WHEN state = 'evicted' THEN size ELSE 0 END), 0) AS bytes_evicted
             FROM files WHERE source <> ?",
            [PathRules::SOURCE_NEXTCLOUD_DB]
        ) ?? [];

        return array_map('intval', $row + ['files_total' => 0, 'bytes_total' => 0, 'files_local' => 0, 'bytes_local' => 0, 'files_evicted' => 0, 'bytes_evicted' => 0]);
    }

    /**
     * I/O-Zaehler (Redis-Helfer, keine Zeilensperren in MySQL).
     */
    public function addCounters(int $targetId, int $readBytes, int $writeBytes, int $readOps, int $writeOps): void
    {
        if ($readBytes === 0 && $writeBytes === 0 && $readOps === 0 && $writeOps === 0) {
            return;
        }
        $this->coordinator->addCounters($targetId, $readBytes, $writeBytes, $readOps, $writeOps);
    }

    /**
     * @return array<int,array{read_bytes:int,write_bytes:int,read_ops:int,write_ops:int}>
     */
    public function counters(): array
    {
        return $this->coordinator->counters();
    }

    public function meta(string $key, string $default = ''): string
    {
        $value = $this->value('SELECT value FROM meta WHERE name = ?', [$key]);

        return $value === null ? $default : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->run($this->upsert('meta', ['name', 'value'], ['name'], ['value' => '@value']), [$key, $value]);
    }

    public function nextGeneration(): int
    {
        return $this->transaction(function (): int {
            // Atomar hochzaehlen (Zeilensperre bis zum Ende der Transaktion).
            $this->run(
                $this->upsert('meta', ['name', 'value'], ['name'], ['value' => $this->mysql ? 'CAST(CAST(meta.value AS UNSIGNED) + 1 AS CHAR)' : 'CAST(meta.value AS INTEGER) + 1']),
                ['generation', '1']
            );

            return (int) $this->meta('generation', '1');
        }, true);
    }

    /**
     * Vergisst alle Dateien (nach einer Wiederherstellung neu aufbauen).
     */
    public function reset(): void
    {
        $tables = ['files', 'target_files', 'ops', 'access', 'activity', 'writes', 'clients'];
        $keys = ['last_full_scan', 'blocked', 'confirm_deletes', 'mode', 'mode_reason'];
        $this->coordinator->exclusive('catalog', function () use ($tables, $keys): void {
            if ($this->mysql) {
                // TRUNCATE statt DELETE: kein Undo-Log fuer Millionen Zeilen.
                foreach ($tables as $table) {
                    $this->pdo->exec('TRUNCATE TABLE ' . $table);
                }
            } else {
                foreach ($tables as $table) {
                    $this->pdo->exec('DELETE FROM ' . $table);
                }
            }
            $this->run('DELETE FROM meta WHERE name IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
        });
    }

    /**
     * Uebernimmt einen alten SQLite-Katalog (catalog.sqlite) einmalig. Nur in
     * einen leeren Katalog; Kennungen der Dateien bleiben erhalten.
     *
     * @return array<string,int> Tabelle => uebernommene Zeilen
     */
    public function importLegacy(PDO $legacy): array
    {
        if ($this->meta('legacy_import') === 'running') {
            // Vorheriger Import wurde unterbrochen (Neustart): Teilstand verwerfen.
            $this->coordinator->exclusive('catalog', function (): void {
                foreach (['files', 'target_files', 'ops', 'access', 'activity', 'writes', 'clients', 'snapshots'] as $table) {
                    $this->pdo->exec(($this->mysql ? 'TRUNCATE TABLE ' : 'DELETE FROM ') . $table);
                }
                $this->run("DELETE FROM meta WHERE name NOT IN ('schema_version', 'legacy_import')");
            });
        } elseif ($this->count() > 0 || (int) $this->value('SELECT COUNT(*) FROM snapshots') > 0) {
            throw new RuntimeException('Der Katalog ist nicht leer - Import alter Daten abgebrochen.');
        }
        $this->setMeta('legacy_import', 'running');
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tables = array_flip($legacy->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN));
        $hash = static fn (array $r): string => self::hash((string) $r['path']);
        $map = [
            'files' => [['id', 'source', 'path_hash', 'path', 'size', 'mtime', 'inode', 'version', 'sha256', 'state', 'tiered', 'last_access', 'evict_reason', 'changed_at', 'seen'], ['path_hash' => $hash]],
            'target_files' => [['target_id', 'file_id', 'version', 'member_id'], ['member_id' => static fn (array $r): int => (int) ($r['member_id'] ?? 0)]],
            'ops' => [['id', 'target_id', 'op', 'source', 'path', 'new_path', 'created_at'], []],
            'access' => [['file_id', 'day'], []],
            'meta' => [['name', 'value'], ['name' => static fn (array $r): string => (string) $r['key']]],
            'activity' => [['id', 'owner', 'at', 'kind', 'path_hash', 'path', 'size', 'detail', 'changed'], ['path_hash' => $hash]],
            'writes' => [['path_hash', 'path', 'uid', 'ip', 'ua', 'at'], ['path_hash' => $hash]],
            'clients' => [['client_hash', 'uid', 'ip', 'ua', 'first_at', 'last_at', 'writes'], [
                'client_hash' => static fn (array $r): string => hash('sha256', $r['uid'] . "\0" . $r['ip'] . "\0" . $r['ua'], true),
            ]],
            'snapshots' => [['id', 'uid', 'file_id', 'source', 'path_hash', 'path', 'user', 'version', 'size', 'mtime', 'sha256', 'status', 'error', 'attempts', 'next_attempt', 'created_at', 'stored_at', 'restored_at', 'restored_by', 'mirrored'], [
                'path_hash' => $hash,
                'error' => static fn (array $r): string => self::clip((string) ($r['error'] ?? '')),
            ]],
        ];
        $counts = [];
        foreach ($map as $table => [$columns, $computed]) {
            $counts[$table] = 0;
            if (!isset($tables[$table])) {
                continue;
            }
            $source = $legacy->query('SELECT * FROM ' . $table);
            $batch = [];
            $flush = function () use (&$batch, $table, $columns, &$counts): void {
                if ($batch === []) {
                    return;
                }
                $rows = $batch;
                $batch = [];
                $this->transaction(function () use ($rows, $table, $columns): void {
                    $values = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                    $this->run(
                        'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(',', array_fill(0, count($rows), $values)),
                        array_merge(...$rows)
                    );
                }, true);
                $counts[$table] += count($rows);
            };
            while (($row = $source->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($table === 'meta' && in_array((string) $row['key'], ['schema_version', 'legacy_import'], true)) {
                    continue;
                }
                $values = [];
                foreach ($columns as $column) {
                    $values[] = isset($computed[$column]) ? $computed[$column]($row) : ($row[$column] ?? null);
                }
                $batch[] = $values;
                if (count($batch) >= self::BATCH) {
                    $flush();
                }
            }
            $flush();
        }
        $this->setMeta('legacy_import', 'done ' . date('c'));

        return $counts;
    }

    // --- Schema ----------------------------------------------------------------

    private function migrate(): void
    {
        // Schneller Pfad: jede Verbindung (auch jedes recall-one) prueft nur die Version.
        if ($this->installedSchema() === self::SCHEMA_VERSION) {
            return;
        }
        $this->coordinator->exclusive('catalog-schema', function (): void {
            $installed = $this->installedSchema();
            foreach ($this->mysql ? self::mysqlSchema() : self::sqliteSchema() as $statement) {
                $this->pdo->exec($statement);
            }
            // Spaetere Schemaaenderungen hier als Schritte "if ($installed > 0 && $installed < N) { ALTER TABLE … }".
            if ($installed !== self::SCHEMA_VERSION) {
                $this->setMeta('schema_version', (string) self::SCHEMA_VERSION);
            }
        });
    }

    private function installedSchema(): int
    {
        try {
            return (int) $this->pdo->query("SELECT value FROM meta WHERE name = 'schema_version'")->fetchColumn();
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * InnoDB-Schema. Pfade bytegenau (VARBINARY), Suche ueber path_hash
     * (BINARY(32)); Indizes passend zu den Abfragen dieses Katalogs.
     *
     * @return list<string>
     */
    private static function mysqlSchema(): array
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC';

        return [
            'CREATE TABLE IF NOT EXISTS files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                path_hash BINARY(32) NOT NULL,
                path VARBINARY(4096) NOT NULL,
                size BIGINT NOT NULL DEFAULT 0,
                mtime BIGINT NOT NULL DEFAULT 0,
                inode BIGINT NOT NULL DEFAULT 0,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                sha256 VARBINARY(64) NULL,
                state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'local\',
                tiered TINYINT NOT NULL DEFAULT 0,
                last_access BIGINT NOT NULL DEFAULT 0,
                evict_reason VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
                changed_at BIGINT NOT NULL DEFAULT 0,
                seen BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY files_path (source, path_hash),
                KEY files_inode (source, inode),
                KEY files_seen (source, seen),
                KEY files_tier (tiered, state, size),
                KEY files_evicted (state, evict_reason),
                KEY files_changed (changed_at, id)
            )' . $table,
            'CREATE TABLE IF NOT EXISTS target_files (
                target_id INT NOT NULL,
                file_id BIGINT UNSIGNED NOT NULL,
                version INT UNSIGNED NOT NULL,
                member_id INT NOT NULL DEFAULT 0,
                PRIMARY KEY (target_id, file_id),
                KEY target_files_file (file_id, version)
            )' . $table,
            'CREATE TABLE IF NOT EXISTS ops (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                target_id INT NOT NULL,
                op VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                path VARBINARY(4096) NOT NULL,
                new_path VARBINARY(4096) NULL,
                created_at BIGINT NOT NULL,
                PRIMARY KEY (id),
                KEY ops_target (target_id, id)
            )' . $table,
            'CREATE TABLE IF NOT EXISTS access (
                file_id BIGINT UNSIGNED NOT NULL,
                day INT NOT NULL,
                PRIMARY KEY (file_id, day),
                KEY access_day (day)
            )' . $table,
            'CREATE TABLE IF NOT EXISTS meta (
                name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                value MEDIUMBLOB NOT NULL,
                PRIMARY KEY (name)
            )' . $table,
            // Vorfallerkennung (ThreatDetector), 24 h aufbewahrt
            'CREATE TABLE IF NOT EXISTS activity (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner VARBINARY(255) NOT NULL,
                at BIGINT NOT NULL,
                kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                path_hash BINARY(32) NOT NULL,
                path VARBINARY(4096) NOT NULL,
                size BIGINT NOT NULL DEFAULT 0,
                detail VARBINARY(1024) NOT NULL DEFAULT \'\',
                changed TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY activity_at (at),
                KEY activity_path (path_hash),
                KEY activity_owner (owner, at)
            )' . $table,
            'CREATE TABLE IF NOT EXISTS writes (
                path_hash BINARY(32) NOT NULL,
                path VARBINARY(4096) NOT NULL,
                uid VARBINARY(255) NOT NULL,
                ip VARBINARY(255) NOT NULL,
                ua BLOB NOT NULL,
                at BIGINT NOT NULL,
                PRIMARY KEY (path_hash),
                KEY writes_at (at),
                KEY writes_uid (uid)
            )' . $table,
            'CREATE TABLE IF NOT EXISTS clients (
                client_hash BINARY(32) NOT NULL,
                uid VARBINARY(255) NOT NULL,
                ip VARBINARY(255) NOT NULL,
                ua BLOB NOT NULL,
                first_at BIGINT NOT NULL,
                last_at BIGINT NOT NULL,
                writes INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (client_hash),
                KEY clients_uid (uid, last_at),
                KEY clients_last (last_at)
            )' . $table,
            // Vorgaengerversionen im Snapshot-Speicher (SnapshotEngine); ueberlebt reset().
            'CREATE TABLE IF NOT EXISTS snapshots (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                uid VARBINARY(64) NOT NULL,
                file_id BIGINT UNSIGNED NULL,
                source VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                path_hash BINARY(32) NOT NULL,
                path VARBINARY(4096) NOT NULL,
                user VARBINARY(255) NOT NULL DEFAULT \'\',
                version INT UNSIGNED NOT NULL,
                size BIGINT NOT NULL DEFAULT 0,
                mtime BIGINT NOT NULL DEFAULT 0,
                sha256 VARBINARY(64) NULL,
                status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'pending\',
                error VARBINARY(2048) NOT NULL DEFAULT \'\',
                attempts INT NOT NULL DEFAULT 0,
                next_attempt BIGINT NOT NULL DEFAULT 0,
                created_at BIGINT NOT NULL,
                stored_at BIGINT NULL,
                restored_at BIGINT NULL,
                restored_by VARBINARY(255) NOT NULL DEFAULT \'\',
                mirrored TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY snapshots_uid (uid),
                KEY snapshots_path (source, path_hash, status, version),
                KEY snapshots_status (status, created_at),
                KEY snapshots_mirrored (mirrored, id),
                KEY snapshots_file (file_id),
                KEY snapshots_created (created_at, id)
            )' . $table,
        ];
    }

    /**
     * Gleiches Schema fuer SQLite im Arbeitsspeicher (Tests).
     *
     * @return list<string>
     */
    private static function sqliteSchema(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS files (id INTEGER PRIMARY KEY, source TEXT NOT NULL, path_hash BLOB NOT NULL, path TEXT NOT NULL,
                size INTEGER NOT NULL DEFAULT 0, mtime INTEGER NOT NULL DEFAULT 0, inode INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1,
                sha256 TEXT NULL, state TEXT NOT NULL DEFAULT \'local\', tiered INTEGER NOT NULL DEFAULT 0, last_access INTEGER NOT NULL DEFAULT 0,
                evict_reason TEXT NULL, changed_at INTEGER NOT NULL DEFAULT 0, seen INTEGER NOT NULL DEFAULT 0, UNIQUE (source, path_hash))',
            'CREATE INDEX IF NOT EXISTS files_inode ON files (source, inode)',
            'CREATE INDEX IF NOT EXISTS files_seen ON files (source, seen)',
            'CREATE INDEX IF NOT EXISTS files_tier ON files (tiered, state, size)',
            'CREATE TABLE IF NOT EXISTS target_files (target_id INTEGER NOT NULL, file_id INTEGER NOT NULL, version INTEGER NOT NULL,
                member_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (target_id, file_id))',
            'CREATE INDEX IF NOT EXISTS target_files_file ON target_files (file_id, version)',
            'CREATE TABLE IF NOT EXISTS ops (id INTEGER PRIMARY KEY, target_id INTEGER NOT NULL, op TEXT NOT NULL, source TEXT NOT NULL,
                path TEXT NOT NULL, new_path TEXT NULL, created_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS access (file_id INTEGER NOT NULL, day INTEGER NOT NULL, PRIMARY KEY (file_id, day))',
            'CREATE TABLE IF NOT EXISTS meta (name TEXT PRIMARY KEY, value TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS activity (id INTEGER PRIMARY KEY, owner TEXT NOT NULL, at INTEGER NOT NULL, kind TEXT NOT NULL,
                path_hash BLOB NOT NULL, path TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, detail TEXT NOT NULL DEFAULT \'\', changed INTEGER NOT NULL DEFAULT 0)',
            'CREATE INDEX IF NOT EXISTS activity_at ON activity (at)',
            'CREATE INDEX IF NOT EXISTS activity_path ON activity (path_hash)',
            'CREATE TABLE IF NOT EXISTS writes (path_hash BLOB PRIMARY KEY, path TEXT NOT NULL, uid TEXT NOT NULL, ip TEXT NOT NULL, ua TEXT NOT NULL, at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS clients (client_hash BLOB PRIMARY KEY, uid TEXT NOT NULL, ip TEXT NOT NULL, ua TEXT NOT NULL,
                first_at INTEGER NOT NULL, last_at INTEGER NOT NULL, writes INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE IF NOT EXISTS snapshots (id INTEGER PRIMARY KEY, uid TEXT NOT NULL UNIQUE, file_id INTEGER NULL, source TEXT NOT NULL,
                path_hash BLOB NOT NULL, path TEXT NOT NULL, user TEXT NOT NULL DEFAULT \'\', version INTEGER NOT NULL, size INTEGER NOT NULL DEFAULT 0,
                mtime INTEGER NOT NULL DEFAULT 0, sha256 TEXT NULL, status TEXT NOT NULL DEFAULT \'pending\', error TEXT NOT NULL DEFAULT \'\',
                attempts INTEGER NOT NULL DEFAULT 0, next_attempt INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, stored_at INTEGER NULL,
                restored_at INTEGER NULL, restored_by TEXT NOT NULL DEFAULT \'\', mirrored INTEGER NOT NULL DEFAULT 0)',
            'CREATE INDEX IF NOT EXISTS snapshots_path ON snapshots (source, path_hash, status, version)',
            'CREATE INDEX IF NOT EXISTS snapshots_status ON snapshots (status, created_at)',
            'CREATE INDEX IF NOT EXISTS snapshots_mirrored ON snapshots (mirrored, id)',
        ];
    }

    // --- Hilfsfunktionen -------------------------------------------------------

    /**
     * INSERT mit Aktualisierung bei Schluesselkonflikt. In $set steht "@spalte"
     * fuer den einzufuegenden Wert, "tabelle.spalte" fuer den bisherigen.
     *
     * @param list<string> $columns
     * @param list<string> $keys
     * @param array<string,string> $set
     */
    private function upsert(string $table, array $columns, array $keys, array $set): string
    {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $assign = [];
        foreach ($set as $column => $expression) {
            $assign[] = $column . ' = ' . $expression;
        }
        $assign = implode(', ', $assign);

        return $this->mysql
            ? $sql . ' AS incoming ON DUPLICATE KEY UPDATE ' . str_replace('@', 'incoming.', $assign)
            : $sql . ' ON CONFLICT (' . implode(', ', $keys) . ') DO UPDATE SET ' . str_replace('@', 'excluded.', $assign);
    }

    /**
     * INSERT, das bestehende Zeilen unveraendert laesst (rowCount 0).
     *
     * @param list<string> $columns
     */
    private function insertIgnore(string $table, array $columns, string $anyKey): string
    {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        return $this->mysql ? $sql . ' ON DUPLICATE KEY UPDATE ' . $anyKey . ' = ' . $anyKey : $sql . ' ON CONFLICT DO NOTHING';
    }

    private function greatest(): string
    {
        return $this->mysql ? 'GREATEST' : 'MAX';
    }

    private static function clip(string $text): string
    {
        return strlen($text) <= 2000 ? $text : mb_strcut($text, 0, 2000);
    }

    private static function retryable(\Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }
        $code = (int) ($exception->errorInfo[1] ?? 0);

        // 1213 Deadlock, 1205 Sperr-Zeitueberschreitung
        return in_array($code, [1213, 1205], true) || $exception->getCode() === '40001';
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->statements[$sql] ?? null;
        if ($statement === null) {
            // Begrenzt: Abfragen mit variablen IN-Listen sollen max_prepared_stmt_count nicht ausschoepfen.
            if (count($this->statements) >= 128) {
                array_shift($this->statements);
            }
            $statement = $this->statements[$sql] = $this->pdo->prepare($sql);
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                $statement->execute($params);

                return $statement;
            } catch (PDOException $exception) {
                // Einzelanweisung ausserhalb einer Transaktion: sicher wiederholbar.
                if ($this->pdo->inTransaction() || $attempt >= self::MAX_RETRIES || !self::retryable($exception)) {
                    throw $exception;
                }
                usleep(random_int(2000, 20000) * $attempt);
            }
        }
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->run($sql, $params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $row === false ? null : array_diff_key($row, array_flip(self::HIDDEN));
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $hidden = array_flip(self::HIDDEN);

        return array_map(static fn (array $row): array => array_diff_key($row, $hidden), $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param list<mixed> $params
     */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->run($sql, $params);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value === false ? null : $value;
    }
}
