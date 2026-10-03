<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Speicherziele, Zustand, Messwerte, Ereignisse und Auftraege des
 * Speicher-Tierings (Container storage-sync).
 *
 * Zeitstempel werden mit NOW() der Datenbank geschrieben; Alter werden in SQL
 * berechnet, damit Anwendung, Agent und SNMP dieselbe Zeitbasis nutzen.
 */
final class StorageRepository extends Repository
{
    /** Platzhalter fuer NOW() in update*Status(). */
    public const NOW = "\0now";

    private const TARGET_FIELDS = [
        'label', 'kind', 'unc_path', 'username', 'password', 'domain', 'smb_version', 's3_endpoint', 's3_region', 's3_bucket',
        's3_prefix', 's3_path_style', 's3_verify_tls', 'capacity_bytes', 'is_primary', 'active',
    ];

    private const STATUS_FIELDS = [
        'heartbeat_at', 'sync_heartbeat_at', 'ha_state', 'ha_message', 'sync_state', 'sync_message', 'mode', 'mode_reason',
        'mode_since', 'local_total_bytes', 'local_free_bytes', 'local_limit_bytes', 'local_read_bps', 'local_write_bps',
        'local_read_iops', 'local_write_iops', 'metrics_source', 'files_total', 'bytes_total', 'files_local', 'bytes_local',
        'files_evicted', 'bytes_evicted', 'pending_files', 'pending_bytes', 'lag_seconds', 'sparse_supported',
        'recalls_active', 'recalls_total', 'recalls_failed', 'last_recall_at', 'last_scan_at', 'last_full_scan_at',
        'last_sync_at', 'last_db_dump_at',
    ];

    private const TARGET_STATUS_FIELDS = [
        'state', 'message', 'total_bytes', 'free_bytes', 'read_bps', 'write_bps', 'read_iops', 'write_iops', 'in_sync',
        'pending_files', 'pending_bytes', 'lag_seconds', 'synced_files', 'synced_bytes', 'state_since', 'last_sync_at',
        'updated_at', 'sync_updated_at',
    ];

    /**
     * Ziele mit Zustand (Alter der Meldungen in Sekunden).
     *
     * @return list<array<string,mixed>>
     */
    public function targets(): array
    {
        $statement = $this->pdo->query(
            'SELECT t.*, s.state, s.message, s.total_bytes, s.free_bytes, s.read_bps, s.write_bps, s.read_iops, s.write_iops,
                    s.in_sync, s.pending_files, s.pending_bytes, s.lag_seconds, s.synced_files, s.synced_bytes,
                    s.state_since, s.last_sync_at,
                    TIMESTAMPDIFF(SECOND, s.updated_at, NOW()) AS status_age
             FROM storage_targets t
             LEFT JOIN storage_target_status s ON s.target_id = t.id
             ORDER BY t.is_primary DESC, t.id ASC'
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    /**
     * UNC-Pfade aller SMB-Ziele (zur Konfliktpruefung mit dem Snapshot-Speicher).
     *
     * @return list<string>
     */
    public function targetUncPaths(): array
    {
        $statement = $this->pdo->query("SELECT unc_path FROM storage_targets WHERE unc_path <> ''");

        return $statement === false ? [] : array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findTarget(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM storage_targets WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function findTargetByUnc(string $unc): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM storage_targets WHERE unc_path = :unc');
        $statement->execute(['unc' => $unc]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function createTarget(array $values): int
    {
        $values = array_intersect_key($values, array_flip(self::TARGET_FIELDS));
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO storage_targets (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        ));
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateTarget(int $id, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::TARGET_FIELDS));
        if ($values === []) {
            return;
        }
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE storage_targets SET %s WHERE id = :id',
            implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($values)))
        ));
        $statement->execute($values + ['id' => $id]);
    }

    public function clearPrimary(int $exceptId): void
    {
        $statement = $this->pdo->prepare('UPDATE storage_targets SET is_primary = 0 WHERE id <> :id');
        $statement->execute(['id' => $exceptId]);
    }

    public function deleteTarget(int $id): void
    {
        $this->pdo->prepare('DELETE FROM storage_target_status WHERE target_id = :id')->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM storage_targets WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Gesamtzustand inkl. Alter der Lebenszeichen (Sekunden, null = nie).
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $statement = $this->pdo->query(
            'SELECT s.*, TIMESTAMPDIFF(SECOND, s.heartbeat_at, NOW()) AS heartbeat_age,
                    TIMESTAMPDIFF(SECOND, s.sync_heartbeat_at, NOW()) AS sync_heartbeat_age,
                    TIMESTAMPDIFF(SECOND, s.mode_since, NOW()) AS mode_age
             FROM storage_status s WHERE s.id = 1'
        );
        $row = $statement === false ? false : $statement->fetch();

        return $row === false ? [] : $row;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateStatus(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::STATUS_FIELDS));
        if ($values === []) {
            return;
        }
        [$assignments, $params] = $this->assignments($values);
        $this->pdo->exec('INSERT IGNORE INTO storage_status (id) VALUES (1)');
        $statement = $this->pdo->prepare('UPDATE storage_status SET ' . implode(', ', $assignments) . ' WHERE id = 1');
        $statement->execute($params);
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateTargetStatus(int $targetId, array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::TARGET_STATUS_FIELDS));
        if ($values === []) {
            return;
        }
        [$assignments, $params] = $this->assignments($values);
        $exists = $this->pdo->prepare('SELECT 1 FROM storage_targets WHERE id = :id');
        $exists->execute(['id' => $targetId]);
        if ($exists->fetchColumn() === false) {
            return;
        }
        $this->pdo->prepare('INSERT IGNORE INTO storage_target_status (target_id) VALUES (:id)')->execute(['id' => $targetId]);
        $statement = $this->pdo->prepare(
            'UPDATE storage_target_status SET ' . implode(', ', $assignments) . ' WHERE target_id = :target_id'
        );
        $statement->execute($params + ['target_id' => $targetId]);
    }

    public function addEvent(string $level, string $category, string $message, ?int $targetId = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO storage_events (created_at, level, category, target_id, message)
             VALUES (NOW(), :level, :category, :target_id, :message)'
        );
        $statement->execute([
            'level' => substr($level, 0, 8),
            'category' => substr($category, 0, 16),
            'target_id' => $targetId,
            'message' => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function events(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.*, t.label AS target_label FROM storage_events e
             LEFT JOIN storage_targets t ON t.id = e.target_id
             ORDER BY e.id DESC LIMIT ' . max(1, min(1000, $limit))
        );
        $statement->execute();

        return $statement->fetchAll();
    }

    public function trimEvents(int $keep = 2000): void
    {
        $statement = $this->pdo->query('SELECT id FROM storage_events ORDER BY id DESC LIMIT 1 OFFSET ' . max(1, $keep));
        $boundary = $statement === false ? false : $statement->fetchColumn();
        if ($boundary !== false) {
            $this->pdo->prepare('DELETE FROM storage_events WHERE id <= :id')->execute(['id' => (int) $boundary]);
        }
    }

    public function addSample(int $usedBytes, int $freeBytes, int $dataTotal, int $dataLocal, int $targetsOnline): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO storage_usage_samples (sampled_at, local_used_bytes, local_free_bytes, data_total_bytes, data_local_bytes, targets_online)
             VALUES (NOW(), :used, :free, :total, :local, :online)'
        );
        $statement->execute([
            'used' => max(0, $usedBytes),
            'free' => max(0, $freeBytes),
            'total' => max(0, $dataTotal),
            'local' => max(0, $dataLocal),
            'online' => max(0, min(255, $targetsOnline)),
        ]);
        $this->pdo->exec('DELETE FROM storage_usage_samples WHERE sampled_at < NOW() - INTERVAL 35 DAY');
    }

    /**
     * Sekunden seit dem letzten Messwert (null = keiner).
     */
    public function lastSampleAge(): ?int
    {
        $statement = $this->pdo->query('SELECT TIMESTAMPDIFF(SECOND, MAX(sampled_at), NOW()) FROM storage_usage_samples');
        $age = $statement === false ? false : $statement->fetchColumn();

        return $age === false || $age === null ? null : (int) $age;
    }

    /**
     * Messwerte fuer die Hochrechnung, Zeit relativ zur Datenbankzeit.
     *
     * @return array{now:int,samples:list<array{0:int,1:int}>}
     */
    public function samples(int $seconds): array
    {
        $statement = $this->pdo->prepare(
            'SELECT UNIX_TIMESTAMP(sampled_at) AS t, data_total_bytes AS bytes, UNIX_TIMESTAMP(NOW()) AS now
             FROM storage_usage_samples WHERE sampled_at >= NOW() - INTERVAL ' . max(60, $seconds) . ' SECOND
             ORDER BY sampled_at ASC'
        );
        $statement->execute();
        $now = time();
        $samples = [];
        foreach ($statement->fetchAll() as $row) {
            $now = (int) $row['now'];
            $samples[] = [(int) $row['t'], (int) $row['bytes']];
        }

        return ['now' => $now, 'samples' => $samples];
    }

    public function addRequest(string $action, ?int $targetId, string $requestedBy, string $detail = ''): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO storage_requests (action, target_id, requested_by, detail, created_at) VALUES (:action, :target, :by, :detail, NOW())'
        );
        $statement->execute(['action' => $action, 'target' => $targetId, 'by' => mb_substr($requestedBy, 0, 100), 'detail' => mb_substr($detail, 0, 190)]);
        $this->pdo->exec('DELETE FROM storage_requests WHERE created_at < NOW() - INTERVAL 7 DAY');

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function request(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM storage_requests WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Uebernimmt den aeltesten offenen Auftrag einer der Aktionen (atomar).
     *
     * @param list<string> $actions
     *
     * @return array<string,mixed>|null
     */
    public function claimRequest(array $actions): ?array
    {
        if ($actions === []) {
            return null;
        }
        $placeholders = implode(', ', array_fill(0, count($actions), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM storage_requests WHERE picked_at IS NULL AND action IN (' . $placeholders . ') ORDER BY id ASC LIMIT 1'
        );
        $statement->execute($actions);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $claim = $this->pdo->prepare('UPDATE storage_requests SET picked_at = NOW() WHERE id = :id AND picked_at IS NULL');
        $claim->execute(['id' => (int) $row['id']]);

        return $claim->rowCount() === 1 ? $row : null;
    }

    public function finishRequest(int $id, string $result): void
    {
        $statement = $this->pdo->prepare('UPDATE storage_requests SET finished_at = NOW(), result = :result WHERE id = :id');
        $statement->execute(['id' => $id, 'result' => mb_substr($result, 0, 500)]);
    }

    public function openRequests(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM storage_requests WHERE finished_at IS NULL AND created_at > NOW() - INTERVAL 1 HOUR');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Juengste Auftraege einer Aktion: detail => Ergebnis ('' = noch offen).
     *
     * @return array<string,string>
     */
    public function recentRequestResults(string $action, int $maxAgeSeconds = 3600): array
    {
        $statement = $this->pdo->prepare(
            'SELECT detail, result, finished_at FROM storage_requests
             WHERE action = :action AND created_at > NOW() - INTERVAL ' . max(60, $maxAgeSeconds) . ' SECOND ORDER BY id ASC'
        );
        $statement->execute(['action' => $action]);
        $results = [];
        foreach ($statement->fetchAll() as $row) {
            $results[(string) $row['detail']] = $row['finished_at'] === null ? '' : (string) $row['result'];
        }

        return $results;
    }

    // --- Snapshot-Speicher (Dateiversionen) ----------------------------------

    public const SNAPSHOT_STATUSES = ['complete', 'pending', 'failed', 'unavailable'];
    public const SNAPSHOT_LIMITS = [10, 25, 50, 100];

    private const SNAPSHOT_STATUS_FIELDS = [
        'state', 'message', 'total_bytes', 'free_bytes', 'read_bps', 'write_bps', 'read_iops', 'write_iops',
        'snapshots_total', 'snapshots_bytes', 'pending', 'failed', 'last_snapshot_at', 'last_error', 'state_since', 'updated_at',
    ];

    /**
     * @return array<string,mixed>
     */
    public function snapshotStatus(): array
    {
        $statement = $this->pdo->query('SELECT * FROM storage_snapshot_status WHERE id = 1');
        $row = $statement === false ? false : $statement->fetch();

        return $row === false ? [] : $row;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function updateSnapshotStatus(array $values): void
    {
        $values = array_intersect_key($values, array_flip(self::SNAPSHOT_STATUS_FIELDS));
        if ($values === []) {
            return;
        }
        [$assignments, $params] = $this->assignments($values);
        $this->pdo->exec("INSERT IGNORE INTO storage_snapshot_status (id, updated_at) VALUES (1, NOW())");
        $statement = $this->pdo->prepare('UPDATE storage_snapshot_status SET ' . implode(', ', $assignments) . ' WHERE id = 1');
        $statement->execute($params);
    }

    /**
     * Spiegelt eine Snapshotzeile des Agent-Katalogs (Einfuegen oder Aktualisieren).
     *
     * @param array<string,mixed> $row Katalogzeile (uid, source, path, user, version, size, mtime, sha256, status, error, created_at, stored_at, restored_at, restored_by, file_id)
     */
    public function upsertSnapshot(array $row): void
    {
        $values = [
            'source' => mb_substr((string) $row['source'], 0, 32),
            'path' => mb_substr((string) $row['path'], 0, 1024),
            'path_hash' => sha1((string) $row['path']),
            'user' => mb_substr((string) ($row['user'] ?? ''), 0, 100),
            'version' => (int) $row['version'],
            'size' => (int) $row['size'],
            'file_mtime' => self::datetime($row['mtime'] ?? null),
            'sha256' => (string) ($row['sha256'] ?? ''),
            'status' => mb_substr((string) $row['status'], 0, 16),
            'file_deleted' => ($row['file_id'] ?? null) === null ? 1 : 0,
            'error' => mb_substr((string) ($row['error'] ?? ''), 0, 500),
            'created_at' => self::datetime($row['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
            'stored_at' => self::datetime($row['stored_at'] ?? null),
            'restored_at' => self::datetime($row['restored_at'] ?? null),
            'restored_by' => mb_substr((string) ($row['restored_by'] ?? ''), 0, 100),
            'synced_at' => date('Y-m-d H:i:s'),
        ];
        $exists = $this->pdo->prepare('SELECT id FROM storage_snapshots WHERE uid = :uid');
        $exists->execute(['uid' => $row['uid']]);
        if ($exists->fetchColumn() !== false) {
            $sets = [];
            foreach (array_keys($values) as $column) {
                $sets[] = $column . ' = :' . $column;
            }
            $statement = $this->pdo->prepare('UPDATE storage_snapshots SET ' . implode(', ', $sets) . ' WHERE uid = :uid');
        } else {
            $columns = array_keys($values);
            $statement = $this->pdo->prepare(
                'INSERT INTO storage_snapshots (uid, ' . implode(', ', $columns) . ') VALUES (:uid, :' . implode(', :', $columns) . ')'
            );
        }
        $statement->execute($values + ['uid' => (string) $row['uid']]);
    }

    public function deleteSnapshot(string $uid): void
    {
        $this->pdo->prepare('DELETE FROM storage_snapshots WHERE uid = :uid')->execute(['uid' => $uid]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function snapshot(string $uid): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM storage_snapshots WHERE uid = :uid');
        $statement->execute(['uid' => $uid]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Gefilterte Liste (neueste zuerst). Alle Filterwerte werden gebunden.
     *
     * @param array{limit?:int,from?:string,to?:string,user?:string,path?:string,status?:string,deleted?:bool} $filter
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function snapshots(array $filter): array
    {
        $where = ["status <> 'deleted'"];
        $params = [];
        if (($filter['from'] ?? '') !== '') {
            $where[] = 'created_at >= :from';
            $params['from'] = $filter['from'] . ' 00:00:00';
        }
        if (($filter['to'] ?? '') !== '') {
            $where[] = 'created_at <= :to';
            $params['to'] = $filter['to'] . ' 23:59:59';
        }
        if (($filter['user'] ?? '') !== '') {
            $where[] = 'user = :user';
            $params['user'] = $filter['user'];
        }
        if (($filter['path'] ?? '') !== '') {
            $where[] = "path LIKE :path ESCAPE '\\'";
            $params['path'] = '%' . addcslashes((string) $filter['path'], '\\%_') . '%';
        }
        if (in_array($filter['status'] ?? '', self::SNAPSHOT_STATUSES, true)) {
            $where[] = 'status = :status';
            $params['status'] = $filter['status'];
        }
        if (($filter['deleted'] ?? false) === true) {
            $where[] = 'file_deleted = 1';
        }
        $limit = in_array($filter['limit'] ?? 0, self::SNAPSHOT_LIMITS, true) ? (int) $filter['limit'] : 25;
        $sql = ' FROM storage_snapshots WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*)' . $sql);
        $count->execute($params);
        $statement = $this->pdo->prepare('SELECT *' . $sql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit);
        $statement->execute($params);

        return ['rows' => $statement->fetchAll() ?: [], 'total' => (int) $count->fetchColumn()];
    }

    /**
     * @return list<string>
     */
    public function snapshotUsers(): array
    {
        $statement = $this->pdo->query("SELECT DISTINCT user FROM storage_snapshots WHERE user <> '' ORDER BY user LIMIT 500");

        return $statement === false ? [] : array_map(static fn (array $r): string => (string) $r['user'], $statement->fetchAll());
    }

    private static function datetime(mixed $unix): ?string
    {
        return $unix === null || (int) $unix <= 0 ? null : date('Y-m-d H:i:s', (int) $unix);
    }

    /**
     * @param array<string,mixed> $values
     *
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function assignments(array $values): array
    {
        $assignments = [];
        $params = [];
        foreach ($values as $column => $value) {
            if ($value === self::NOW) {
                $assignments[] = $column . ' = NOW()';
                continue;
            }
            $assignments[] = $column . ' = :' . $column;
            $params[$column] = is_bool($value) ? (int) $value : $value;
        }

        return [$assignments, $params];
    }
}
