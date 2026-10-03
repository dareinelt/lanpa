<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Security\SecretBox;
use App\Exceptions\ValidationException;
use App\Repositories\StorageRepository;
use App\Services\SettingsService;
use App\Services\Storage\Agent\SnapshotStore;

/**
 * Snapshot-Speicher (Dateiversionen) im Adminbereich: Einstellungen, Zustand,
 * Versionsliste (Spiegel aus MySQL) und Wiederherstellungsauftraege an den
 * Agenten storage-sync. Die eigentliche Arbeit (Sichern, Wiederherstellen)
 * erledigt ausschliesslich der Agent.
 */
final class SnapshotService
{
    public const ACTION_RESTORE = 'snapshot_restore';

    public function __construct(
        private readonly StorageRepository $repository,
        private readonly SettingsService $settings,
        private readonly SecretBox $secrets
    ) {
    }

    public function settings(): SnapshotSettings
    {
        return new SnapshotSettings($this->settings->all());
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public function saveSettings(array $input): void
    {
        $result = SnapshotSettings::validate($input, $this->repository->targetUncPaths());
        if ($result['errors'] !== []) {
            throw new ValidationException($result['errors']);
        }
        $values = $result['values'];
        $current = $this->settings();
        if ($values['storage_snapshot_password'] !== '') {
            $values['storage_snapshot_password'] = $this->secrets->encrypt($values['storage_snapshot_password']);
        } elseif (!empty($input['storage_snapshot_password_clear'])) {
            $values['storage_snapshot_password'] = '';
        } else {
            $values['storage_snapshot_password'] = $current->encryptedPassword();
        }
        $this->settings->update($values);
    }

    /**
     * Zustand der Freigabe (vom Agenten gemeldet).
     *
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $settings = $this->settings();
        try {
            $row = $this->repository->snapshotStatus();
        } catch (\PDOException) {
            $row = [];
        }
        $state = (string) ($row['state'] ?? ($settings->enabled() ? 'unknown' : 'disabled'));
        if (!$settings->enabled()) {
            $state = 'disabled';
        }
        $total = (int) ($row['total_bytes'] ?? 0);
        $free = (int) ($row['free_bytes'] ?? 0);

        return [
            'enabled' => $settings->enabled(),
            'configured' => $settings->uncPath() !== '',
            'unc_path' => $settings->uncPath(),
            'state' => $state,
            'state_label' => self::stateLabel($state),
            'message' => (string) ($row['message'] ?? ''),
            'total_bytes' => $total,
            'free_bytes' => $free,
            'used_bytes' => max(0, $total - $free),
            'fill' => StorageHealth::fill($total, $free, 85, 95),
            'read_bps' => (int) ($row['read_bps'] ?? 0),
            'write_bps' => (int) ($row['write_bps'] ?? 0),
            'read_iops' => (float) ($row['read_iops'] ?? 0),
            'write_iops' => (float) ($row['write_iops'] ?? 0),
            'snapshots_total' => (int) ($row['snapshots_total'] ?? 0),
            'snapshots_bytes' => (int) ($row['snapshots_bytes'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'last_snapshot_at' => (string) ($row['last_snapshot_at'] ?? ''),
            'last_error' => (string) ($row['last_error'] ?? ''),
            'state_since' => (string) ($row['state_since'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'retention_days' => $settings->retentionDays(),
            'max_versions' => $settings->maxVersions(),
        ];
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'online' => 'Erreichbar',
            'offline' => 'Nicht erreichbar',
            'invalid' => 'Ungültig',
            'disabled' => 'Deaktiviert',
            default => 'Unbekannt',
        };
    }

    /**
     * Bereinigt Filter aus der Anfrage (alle Werte werden spaeter gebunden).
     *
     * @param array<string,mixed> $query
     *
     * @return array{limit:int,from:string,to:string,user:string,path:string,status:string,deleted:bool}
     */
    public static function filter(array $query): array
    {
        $limit = (int) ($query['limit'] ?? 25);
        $date = static fn (string $value): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : '';
        $status = (string) ($query['status'] ?? '');

        return [
            'limit' => in_array($limit, StorageRepository::SNAPSHOT_LIMITS, true) ? $limit : 25,
            'from' => $date(trim((string) ($query['from'] ?? ''))),
            'to' => $date(trim((string) ($query['to'] ?? ''))),
            'user' => mb_substr(trim((string) ($query['user'] ?? '')), 0, 100),
            'path' => mb_substr(trim((string) ($query['path'] ?? '')), 0, 300),
            'status' => in_array($status, StorageRepository::SNAPSHOT_STATUSES, true) ? $status : '',
            'deleted' => !empty($query['deleted']),
        ];
    }

    /**
     * @param array{limit:int,from:string,to:string,user:string,path:string,status:string,deleted:bool} $filter
     *
     * @return array{rows:list<array<string,mixed>>,total:int,users:list<string>}
     */
    public function list(array $filter): array
    {
        try {
            $result = $this->repository->snapshots($filter);
            $users = $this->repository->snapshotUsers();
        } catch (\PDOException) {
            return ['rows' => [], 'total' => 0, 'users' => []];
        }

        return ['rows' => $result['rows'], 'total' => $result['total'], 'users' => $users];
    }

    /**
     * Legt einen Wiederherstellungsauftrag fuer den Agenten an.
     *
     * @return array<string,mixed> Snapshotzeile
     *
     * @throws ValidationException
     */
    public function requestRestore(string $uid, string $by): array
    {
        if (!SnapshotStore::validUid($uid)) {
            throw new ValidationException(['uid' => 'Ungültige Kennung.']);
        }
        $snapshot = $this->repository->snapshot($uid);
        if ($snapshot === null) {
            throw new ValidationException(['uid' => 'Diese Dateiversion ist nicht (mehr) vorhanden.']);
        }
        if ($snapshot['status'] !== 'complete') {
            throw new ValidationException(['uid' => 'Diese Dateiversion wurde nicht vollständig gesichert und kann nicht wiederhergestellt werden.']);
        }
        if (!$this->settings()->enabled()) {
            throw new ValidationException(['uid' => 'Der Snapshot-Speicher ist deaktiviert.']);
        }
        $this->repository->addRequest(self::ACTION_RESTORE, null, $by, $uid);
        $this->repository->addEvent('info', 'snapshot', 'Wiederherstellung angefordert: ' . $snapshot['source'] . '/' . $snapshot['path']
            . ' (Version ' . $snapshot['version'] . ') durch ' . $by);

        return $snapshot;
    }

    /**
     * Laufende/abgeschlossene Wiederherstellungsauftraege (fuer die Liste).
     *
     * @return array<string,string> uid => Ergebnis ('' = laeuft noch)
     */
    public function restoreResults(): array
    {
        try {
            return $this->repository->recentRequestResults(self::ACTION_RESTORE);
        } catch (\PDOException) {
            return [];
        }
    }
}
