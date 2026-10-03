<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use PDO;

/**
 * Lokaler Katalog von storage-sync (SQLite im Volume storage_sync_state):
 * alle Dateien mit Version, Zustand (Hot-/Cold-Tier), Stand je Speicherziel,
 * ausstehende Loesch-/Umbenennungsauftraege, Zugriffe und I/O-Zaehler.
 *
 * Mehrere Prozesse (sync, recall, monitor) nutzen ihn parallel (WAL).
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

    private const SNAPSHOT_FIELDS = ['file_id', 'path', 'user', 'sha256', 'status', 'error', 'attempts', 'next_attempt', 'stored_at', 'restored_at', 'restored_by'];

    private PDO $pdo;

    public function __construct(string $file)
    {
        $dir = dirname($file);
        if ($file !== ':memory:' && !is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $this->pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 60,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout = 60000');
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $exception) {
            $this->pdo->exec('ROLLBACK');
            throw $exception;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $source, string $path): ?array
    {
        return $this->one('SELECT * FROM files WHERE source = ? AND path = ?', [$source, $path]);
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
            'INSERT INTO files (source, path, size, mtime, inode, version, sha256, state, tiered, last_access, changed_at, seen)
             VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
            [$source, $path, $size, $mtime, $inode, $sha, $state, PathRules::isTiered($source, $path) ? 1 : 0, $mtime, $now, $seen]
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
                    evict_reason = NULL, changed_at = ?, seen = seen WHERE id = ?",
            [$size, $mtime, $inode, $sha, $now, $id]
        );
    }

    public function rename(int $id, string $newPath, int $seen): void
    {
        $file = $this->get($id);
        $this->run('DELETE FROM files WHERE source = ? AND path = ? AND id <> ?', [$file['source'] ?? '', $newPath, $id]);
        $this->run(
            'UPDATE files SET path = ?, tiered = ?, seen = ? WHERE id = ?',
            [$newPath, PathRules::isTiered((string) ($file['source'] ?? ''), $newPath) ? 1 : 0, $seen, $id]
        );
        // Vorgaengerversionen folgen der Datei (kein neuer Inhalts-Snapshot).
        $this->run('UPDATE snapshots SET path = ?, mirrored = 0 WHERE file_id = ?', [$newPath, $id]);
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
        $this->run(
            'INSERT OR IGNORE INTO snapshots (uid, file_id, source, path, user, version, size, mtime, sha256, status, error, created_at, mirrored)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)',
            [
                (string) $row['uid'], $row['file_id'] ?? null, (string) $row['source'], (string) $row['path'], (string) ($row['user'] ?? ''),
                (int) $row['version'], (int) $row['size'], (int) $row['mtime'], $row['sha256'] ?? null, (string) $row['status'],
                (string) ($row['error'] ?? ''), (int) $row['created_at'],
            ]
        );

        return (int) $this->value('SELECT changes()') === 1;
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
            "SELECT * FROM snapshots WHERE source = ? AND path = ? AND status = 'pending' ORDER BY version ASC",
            [$source, $path]
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
            "SELECT * FROM snapshots WHERE source = ? AND path = ? AND status = 'complete' ORDER BY created_at DESC, version DESC LIMIT " . max(1, $limit),
            [$source, $path]
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
                WHERE n.source = s.source AND n.path = s.path AND n.status = 'complete'
                  AND (n.created_at > s.created_at OR (n.created_at = s.created_at AND n.version > s.version))
             ) >= " . max(0, $keep) . ' ORDER BY s.created_at ASC LIMIT ' . max(1, $limit)
        );
    }

    /**
     * Fehlgeschlagene Sicherungen erneut einplanen.
     */
    public function retrySnapshots(): int
    {
        $this->run("UPDATE snapshots SET status = 'pending', next_attempt = 0, error = '', mirrored = 0 WHERE status = 'failed'");

        return (int) $this->value('SELECT changes()');
    }

    /**
     * Vormerkungen, die seit der Haltefrist nicht gesichert werden konnten, aufgeben.
     */
    public function expirePendingSnapshots(int $createdBefore): int
    {
        $this->run(
            "UPDATE snapshots SET status = 'failed', mirrored = 0,
                    error = CASE WHEN error = '' THEN 'Die bisherige Version konnte nicht mehr gesichert werden.' ELSE error END
             WHERE status = 'pending' AND created_at < ?",
            [$createdBefore]
        );

        return (int) $this->value('SELECT changes()');
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
        foreach ($uids as $uid) {
            $this->run('UPDATE snapshots SET mirrored = 1 WHERE uid = ? AND mirrored = 0', [$uid]);
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
        $this->run('UPDATE snapshots SET file_id = ?, mirrored = 0 WHERE source = ? AND path = ? AND file_id IS NULL', [$fileId, $source, $path]);
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

    /**
     * Letzter bekannter Schreiber eines Pfads (Nextcloud-Schreibprotokoll).
     */
    public function lastWriter(string $path): string
    {
        return (string) ($this->value('SELECT uid FROM writes WHERE path = ?', [$path]) ?? '');
    }

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

    public function markSynced(int $targetId, int $fileId, int $version): void
    {
        $this->run(
            'INSERT INTO target_files (target_id, file_id, version) VALUES (?, ?, ?)
             ON CONFLICT (target_id, file_id) DO UPDATE SET version = excluded.version',
            [$targetId, $fileId, $version]
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
        $list = $keep === [] ? '-1' : implode(',', array_map('intval', $keep));
        $this->pdo->exec('DELETE FROM target_files WHERE target_id NOT IN (' . $list . ')');
        $this->pdo->exec('DELETE FROM ops WHERE target_id NOT IN (' . $list . ')');
        $this->pdo->exec('DELETE FROM counters WHERE target_id <> 0 AND target_id NOT IN (' . $list . ')');
    }

    public function recordAccess(int $fileId, int $timestamp): void
    {
        $this->run('UPDATE files SET last_access = MAX(last_access, ?) WHERE id = ?', [$timestamp, $fileId]);
        $this->run('INSERT OR IGNORE INTO access (file_id, day) VALUES (?, ?)', [$fileId, intdiv($timestamp, 86400)]);
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
            "SELECT f.*, MAX(f.mtime, f.last_access) AS activity,
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
        $where = $reason === null ? '' : ' AND f.evict_reason = ' . $this->pdo->quote($reason);

        return $this->all(
            "SELECT f.*, MAX(f.mtime, f.last_access) AS activity,
                    (SELECT COUNT(*) FROM access a WHERE a.file_id = f.id AND a.day >= ?) AS access_days
             FROM files f WHERE f.state = 'evicted'" . $where . '
             ORDER BY activity DESC LIMIT ' . max(1, $limit),
            [intdiv($now, 86400) - 30]
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

    public function addCounters(int $targetId, int $readBytes, int $writeBytes, int $readOps, int $writeOps): void
    {
        if ($readBytes === 0 && $writeBytes === 0 && $readOps === 0 && $writeOps === 0) {
            return;
        }
        $this->run(
            'INSERT INTO counters (target_id, read_bytes, write_bytes, read_ops, write_ops) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (target_id) DO UPDATE SET read_bytes = read_bytes + excluded.read_bytes,
                 write_bytes = write_bytes + excluded.write_bytes, read_ops = read_ops + excluded.read_ops,
                 write_ops = write_ops + excluded.write_ops',
            [$targetId, $readBytes, $writeBytes, $readOps, $writeOps]
        );
    }

    /**
     * @return array<int,array{read_bytes:int,write_bytes:int,read_ops:int,write_ops:int}>
     */
    public function counters(): array
    {
        $result = [];
        foreach ($this->all('SELECT * FROM counters') as $row) {
            $result[(int) $row['target_id']] = [
                'read_bytes' => (int) $row['read_bytes'],
                'write_bytes' => (int) $row['write_bytes'],
                'read_ops' => (int) $row['read_ops'],
                'write_ops' => (int) $row['write_ops'],
            ];
        }

        return $result;
    }

    public function meta(string $key, string $default = ''): string
    {
        $value = $this->value('SELECT value FROM meta WHERE key = ?', [$key]);

        return $value === null ? $default : (string) $value;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->run('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value', [$key, $value]);
    }

    public function nextGeneration(): int
    {
        $generation = (int) $this->meta('generation', '0') + 1;
        $this->setMeta('generation', (string) $generation);

        return $generation;
    }

    /**
     * Vergisst alle Dateien (nach einer Wiederherstellung neu aufbauen).
     */
    public function reset(): void
    {
        $this->transaction(function (): void {
            foreach (['files', 'target_files', 'ops', 'access', 'activity', 'writes', 'clients'] as $table) {
                $this->pdo->exec('DELETE FROM ' . $table);
            }
            $this->pdo->exec("DELETE FROM meta WHERE key IN ('last_full_scan', 'blocked', 'confirm_deletes', 'mode', 'mode_reason')");
        });
    }

    private function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY,
            source TEXT NOT NULL,
            path TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            mtime INTEGER NOT NULL DEFAULT 0,
            inode INTEGER NOT NULL DEFAULT 0,
            version INTEGER NOT NULL DEFAULT 1,
            sha256 TEXT NULL,
            state TEXT NOT NULL DEFAULT \'local\',
            tiered INTEGER NOT NULL DEFAULT 0,
            last_access INTEGER NOT NULL DEFAULT 0,
            evict_reason TEXT NULL,
            changed_at INTEGER NOT NULL DEFAULT 0,
            seen INTEGER NOT NULL DEFAULT 0,
            UNIQUE (source, path)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS files_inode ON files (source, inode)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS files_state ON files (tiered, state)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS target_files (
            target_id INTEGER NOT NULL,
            file_id INTEGER NOT NULL,
            version INTEGER NOT NULL,
            PRIMARY KEY (target_id, file_id)
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS target_files_file ON target_files (file_id)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ops (
            id INTEGER PRIMARY KEY,
            target_id INTEGER NOT NULL,
            op TEXT NOT NULL,
            source TEXT NOT NULL,
            path TEXT NOT NULL,
            new_path TEXT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS ops_target ON ops (target_id, id)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS access (file_id INTEGER NOT NULL, day INTEGER NOT NULL, PRIMARY KEY (file_id, day))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS counters (
            target_id INTEGER PRIMARY KEY,
            read_bytes INTEGER NOT NULL DEFAULT 0,
            write_bytes INTEGER NOT NULL DEFAULT 0,
            read_ops INTEGER NOT NULL DEFAULT 0,
            write_ops INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        // Erkennung auffaelligen Ueberschreibens (ThreatDetector)
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY,
            owner TEXT NOT NULL,
            at INTEGER NOT NULL,
            kind TEXT NOT NULL,
            path TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            detail TEXT NOT NULL DEFAULT \'\',
            changed INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS activity_at ON activity (at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS activity_path ON activity (path)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS writes (path TEXT PRIMARY KEY, uid TEXT NOT NULL, ip TEXT NOT NULL, ua TEXT NOT NULL, at INTEGER NOT NULL)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS writes_at ON writes (at)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS clients (
            uid TEXT NOT NULL,
            ip TEXT NOT NULL,
            ua TEXT NOT NULL,
            first_at INTEGER NOT NULL,
            last_at INTEGER NOT NULL,
            writes INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (uid, ip, ua)
        )');
        // Vorgaengerversionen im Snapshot-Speicher (SnapshotEngine); ueberlebt reset().
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS snapshots (
            id INTEGER PRIMARY KEY,
            uid TEXT NOT NULL UNIQUE,
            file_id INTEGER NULL,
            source TEXT NOT NULL,
            path TEXT NOT NULL,
            user TEXT NOT NULL DEFAULT \'\',
            version INTEGER NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            mtime INTEGER NOT NULL DEFAULT 0,
            sha256 TEXT NULL,
            status TEXT NOT NULL DEFAULT \'pending\',
            error TEXT NOT NULL DEFAULT \'\',
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            stored_at INTEGER NULL,
            restored_at INTEGER NULL,
            restored_by TEXT NOT NULL DEFAULT \'\',
            mirrored INTEGER NOT NULL DEFAULT 0
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS snapshots_path ON snapshots (source, path, status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS snapshots_status ON snapshots (status, created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS snapshots_mirrored ON snapshots (mirrored)');
    }

    /**
     * @param list<mixed> $params
     */
    private function run(string $sql, array $params = []): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string,mixed>|null
     */
    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string,mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param list<mixed> $params
     */
    private function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }
}
