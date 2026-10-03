<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Exceptions\ValidationException;
use App\Repositories\IncidentRepository;
use App\Repositories\StorageRepository;
use App\Security\SecretBox;
use App\Services\Office\NetworkDriveService;
use App\Services\SettingsService;
use App\Support\Dates;
use App\Support\Validator;

/**
 * Adminbereich "Speicher (HA)": Hot-Tier (lokales Storage) und Cold-Tier
 * (SMB-/S3-Tier, Speicherziele per UNC), Einstellungen des Tierings, Zustand, Hochrechnung und Auftraege an den Container storage-sync.
 */
final class StorageService
{
    public const SMB_VERSIONS = [
        'auto' => 'Automatisch aushandeln',
        '3.1.1' => 'SMB 3.1.1',
        '3.0' => 'SMB 3.0',
        '2.1' => 'SMB 2.1',
    ];

    public const REQUEST_ACTIONS = ['sync_now', 'full_scan', 'remount', 'confirm_deletes', 'snapshot_remount'];

    public const KIND_SMB = 'smb';
    public const KIND_S3 = 's3';

    public const KINDS = [
        self::KIND_SMB => 'SMB-Freigabe (UNC-Pfad)',
        self::KIND_S3 => 'S3-kompatibler Objektspeicher (Bucket)',
    ];

    /** Obergrenze der angegebenen Kapazitaet eines S3-Ziels (1 EB in GB). */
    public const S3_MAX_CAPACITY_GB = 1073741824;

    public const USERNAME_PATTERN = '/^[^\x00-\x1F\x7F,=\\\\\/]{1,128}$/u';
    public const DOMAIN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-]{0,127}$/';
    private const S3_HOST_PATTERN = '/^(?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/';
    private const S3_BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/';
    private const S3_REGION_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,62}$/';
    private const S3_PREFIX_SEGMENT = '/^[A-Za-z0-9._\-]{1,100}$/';
    private const S3_KEY_PATTERN = '/^[\x21-\x39\x3B-\x7E]{3,128}$/';

    public function __construct(
        private readonly StorageRepository $repository,
        private readonly SettingsService $settings,
        private readonly SecretBox $secrets,
        private readonly bool $officeEnabled,
        private readonly ?IncidentRepository $incidents = null
    ) {
    }

    /**
     * Schreibgeschuetztes Schutzziel offener Sicherheitsvorfaelle.
     *
     * @return array{target_id:?int,open:int}
     */
    private function incidentState(): array
    {
        if ($this->incidents === null) {
            return ['target_id' => null, 'open' => 0];
        }
        try {
            $open = $this->incidents->open();
        } catch (\PDOException) {
            return ['target_id' => null, 'open' => 0];
        }
        $target = null;
        foreach ($open as $incident) {
            if ($incident['frozen_target_id'] !== null) {
                $target = (int) $incident['frozen_target_id'];
                break;
            }
        }

        return ['target_id' => $target, 'open' => count($open)];
    }

    public function officeEnabled(): bool
    {
        return $this->officeEnabled;
    }

    public function settings(): StorageSettings
    {
        return new StorageSettings($this->settings->all());
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public function saveSettings(array $input): void
    {
        $result = StorageSettings::validate($input);
        if ($result['errors'] !== []) {
            throw new ValidationException($result['errors']);
        }
        $values = $result['values'];
        if ($values['storage_enabled'] === '0' && $this->settings()->enabled()) {
            $evicted = (int) ($this->repository->status()['files_evicted'] ?? 0);
            if ($evicted > 0) {
                $values['storage_enabled'] = '1';
                throw new ValidationException(['storage_enabled' => sprintf(
                    'Es sind noch %d Datei(en) in den Cold-Tier (SMB-/S3-Tier) ausgelagert. Deaktivieren Sie zuerst „selten genutzte Dateien aus dem Hot-Tier auslagern“, '
                    . 'damit storage-sync sie in den Hot-Tier (lokales Storage) zurückholt, und schalten Sie das Tiering danach ab.',
                    $evicted
                )]);
            }
        }
        $values += $this->instanceIdValue();
        $this->settings->update($values);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function targets(): array
    {
        return $this->repository->targets();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function target(int $id): ?array
    {
        return $this->repository->findTarget($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public function createTarget(array $input): int
    {
        $values = $this->validateTarget($input, null);
        if ($this->repository->targets() === []) {
            $values['is_primary'] = 1;
        }
        $id = $this->repository->createTarget($values);
        if ((int) $values['is_primary'] === 1) {
            $this->repository->clearPrimary($id);
        }
        $this->settings->update($this->instanceIdValue());
        $this->repository->addEvent('info', 'config', 'Speicherziel „' . $values['label'] . '“ angelegt (' . $values['unc_path'] . ').', $id);

        return $id;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public function updateTarget(int $id, array $input): void
    {
        $existing = $this->repository->findTarget($id);
        if ($existing === null) {
            throw new ValidationException(['target' => 'Das Speicherziel wurde nicht gefunden.']);
        }
        $parentId = self::parentId($existing);
        $tier = $this->tierOf($id);
        if ($parentId !== null && $tier !== null) {
            // Erweiterung: Art, Status und Rolle folgen dem Basisziel des Cold-Tiers.
            $root = $tier['root'];
            $input['kind'] = (string) ($root['kind'] ?? self::KIND_SMB);
            $input['active'] = (int) $root['active'] === 1 ? '1' : '';
            unset($input['is_primary']);
        } elseif ($tier !== null && count($tier['members']) > 1 && (string) ($input['kind'] ?? self::KIND_SMB) !== (string) ($existing['kind'] ?? self::KIND_SMB)) {
            throw new ValidationException(['kind' => 'Die Art eines erweiterten Cold-Tiers kann nicht geändert werden – SMB wird nur mit SMB, S3 nur mit S3 erweitert.']);
        }
        $values = $this->validateTarget($input, $existing);
        if ($parentId !== null) {
            $values['is_primary'] = 0;
        }
        if ((int) $existing['active'] === 1 && (int) $values['active'] === 0) {
            $this->assertRemovable($id, 'deaktiviert');
        }
        $this->repository->updateTarget($id, $values);
        if ($parentId === null && (int) $existing['active'] !== (int) $values['active']) {
            $this->repository->setTierActive($id, (int) $values['active']);
        }
        if ((int) $values['is_primary'] === 1) {
            $this->repository->clearPrimary($id);
        }
        $this->repository->addEvent('info', 'config', 'Speicherziel „' . $values['label'] . '“ geändert.', $id);
        // Geaenderte Zugangsdaten/Pfade erst nach neuer Einbindung wirksam.
        $this->repository->addRequest('remount', $id, 'config');
    }

    /**
     * Entfernt einen Cold-Tier (Basisziel samt Erweiterungen) oder – bei einer
     * Erweiterung – die letzte Erweiterungsstufe aller Cold-Tiers, solange sie
     * noch keine Daten enthaelt (die Balance der Tiers bleibt erhalten).
     *
     * @throws ValidationException
     */
    public function deleteTarget(int $id): string
    {
        $existing = $this->repository->findTarget($id);
        if ($existing === null) {
            throw new ValidationException(['target' => 'Das Speicherziel wurde nicht gefunden.']);
        }
        $tier = $this->tierOf($id);
        if (self::parentId($existing) !== null && $tier !== null) {
            return $this->deleteExtensionLevel($tier, $id);
        }
        $this->assertRemovable($id, 'gelöscht');
        $members = $tier === null ? [$existing] : $tier['members'];
        $this->repository->deleteTargets(array_reverse(array_map(static fn (array $m): int => (int) $m['id'], $members)));
        $extensions = count($members) - 1;
        $this->repository->addEvent('warning', 'config', 'Speicherziel „' . $existing['label'] . '“ '
            . ($extensions > 0 ? sprintf('samt %d Erweiterung(en) ', $extensions) : '') . 'entfernt. Die Daten auf der Freigabe bleiben erhalten.');

        return (string) $existing['label'];
    }

    /**
     * @param array{root:array<string,mixed>,members:list<array<string,mixed>>} $tier
     *
     * @throws ValidationException
     */
    private function deleteExtensionLevel(array $tier, int $id): string
    {
        $level = 0;
        foreach ($tier['members'] as $index => $member) {
            if ((int) $member['id'] === $id) {
                $level = $index;
            }
        }
        $tiers = self::tiers($this->repository->targets());
        $deepest = max(array_map(static fn (array $t): int => count($t['members']) - 1, $tiers));
        if ($level < $deepest) {
            throw new ValidationException(['target' => sprintf(
                'Es kann nur die letzte Erweiterungsstufe (Erweiterung %d) entfernt werden – sie wird in allen Cold-Tiers gemeinsam entfernt.',
                $deepest
            )]);
        }
        $remove = [];
        foreach ($tiers as $other) {
            $member = $other['members'][$level] ?? null;
            if ($member === null) {
                continue;
            }
            if ((int) ($member['synced_files'] ?? 0) > 0) {
                throw new ValidationException(['target' => sprintf(
                    'Die Erweiterung „%s“ enthält bereits %d synchronisierte Datei(en) des Cold-Tiers und kann nicht entfernt werden.',
                    (string) $member['label'],
                    (int) $member['synced_files']
                )]);
            }
            $remove[] = $member;
        }
        $this->repository->deleteTargets(array_map(static fn (array $m): int => (int) $m['id'], $remove));
        $labels = implode(', ', array_map(static fn (array $m): string => '„' . $m['label'] . '“', $remove));
        $this->repository->addEvent('warning', 'config', sprintf('Erweiterung %d der Cold-Tiers entfernt: %s.', $level, $labels));

        return implode(', ', array_map(static fn (array $m): string => (string) $m['label'], $remove));
    }

    /**
     * Cold-Tier, zu dem ein Ziel gehoert.
     *
     * @return array{root:array<string,mixed>,members:list<array<string,mixed>>}|null
     */
    public function tierOf(int $id): ?array
    {
        foreach (self::tiers($this->repository->targets()) as $tier) {
            foreach ($tier['members'] as $member) {
                if ((int) $member['id'] === $id) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * Erweitert alle Cold-Tiers gleichzeitig um je ein weiteres Ziel derselben
     * Art (SMB nur mit SMB, S3 nur mit S3). Erwartet je Basisziel die Angaben
     * unter $input[<id des Basisziels>]; angelegt wird nur, wenn alle gueltig sind.
     *
     * @param array<int|string,mixed> $input
     *
     * @return list<int> Kennungen der neuen Ziele
     *
     * @throws ValidationException
     */
    public function extendTiers(array $input): array
    {
        $tiers = self::tiers($this->repository->targets());
        if ($tiers === []) {
            throw new ValidationException(['tiers' => 'Es ist noch kein Cold-Tier eingerichtet.']);
        }
        $errors = [];
        $create = [];
        $locations = [];
        foreach ($tiers as $tier) {
            $root = $tier['root'];
            $rootId = (int) $root['id'];
            $fields = $input[$rootId] ?? $input[(string) $rootId] ?? null;
            if (!is_array($fields)) {
                $errors['tier_' . $rootId . '_label'] = 'Bitte für jeden Cold-Tier ein weiteres Ziel angeben – alle Cold-Tiers werden gemeinsam erweitert.';
                continue;
            }
            $kind = (string) ($root['kind'] ?? self::KIND_SMB) === self::KIND_S3 ? self::KIND_S3 : self::KIND_SMB;
            if (isset($fields['kind']) && (string) $fields['kind'] !== $kind) {
                $errors['tier_' . $rootId . '_kind'] = $kind === self::KIND_S3
                    ? 'Ein S3-Tier kann nur mit einem S3-Ziel erweitert werden.'
                    : 'Ein SMB-Tier kann nur mit einer SMB-Freigabe erweitert werden.';
                continue;
            }
            $fields['kind'] = $kind;
            unset($fields['is_primary'], $fields['password_clear']);
            $existing = null;
            $reuse = !empty($fields['reuse_credentials']);
            if ($reuse) {
                // Zugangsdaten des Basisziels uebernehmen (Kennwort bzw. Secret bleibt verschluesselt).
                $existing = ['id' => 0, 'kind' => $kind, 'password' => $root['password'] ?? null];
                if ($kind === self::KIND_S3) {
                    $fields['s3_access_key'] = (string) $root['username'];
                    $fields['s3_secret_key'] = '';
                } else {
                    $fields['username'] = (string) $root['username'];
                    $fields['domain'] = (string) $root['domain'];
                    $fields['password'] = '';
                }
            }
            try {
                $values = $this->validateTarget($fields, $existing);
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $message) {
                    $errors['tier_' . $rootId . '_' . $field] = $message;
                }
                continue;
            }
            $location = strtolower(rtrim((string) $values['unc_path'], '\\/'));
            if (isset($locations[$location])) {
                $errors['tier_' . $rootId . '_' . ($kind === self::KIND_S3 ? 's3_bucket' : 'unc_path')] = 'Jeder Cold-Tier benötigt ein eigenes Ziel – dieses ist bereits für einen anderen Cold-Tier angegeben.';
                continue;
            }
            $locations[$location] = true;
            if ($reuse && !array_key_exists('password', $values)) {
                $values['password'] = $root['password'] ?? null;
            }
            $values['parent_id'] = $rootId;
            $values['is_primary'] = 0;
            $values['active'] = (int) $root['active'] === 1 ? 1 : 0;
            $create[] = $values;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $ids = $this->repository->createTargets($create);
        foreach ($create as $index => $values) {
            $this->repository->addEvent('info', 'config', sprintf(
                'Cold-Tier „%s“ um „%s“ erweitert (%s).',
                (string) $this->labelOf($tiers, (int) $values['parent_id']),
                $values['label'],
                $values['unc_path']
            ), $ids[$index]);
        }

        return $ids;
    }

    /**
     * @param list<array{root:array<string,mixed>,members:list<array<string,mixed>>}> $tiers
     */
    private function labelOf(array $tiers, int $rootId): string
    {
        foreach ($tiers as $tier) {
            if ((int) $tier['root']['id'] === $rootId) {
                return (string) $tier['root']['label'];
            }
        }

        return '';
    }

    /**
     * @throws ValidationException
     */
    public function request(string $action, ?int $targetId, string $admin): void
    {
        if (!in_array($action, self::REQUEST_ACTIONS, true)) {
            throw new ValidationException(['action' => 'Unbekannter Auftrag.']);
        }
        if ($this->repository->openRequests() > 20) {
            throw new ValidationException(['action' => 'Es liegen bereits viele offene Aufträge vor – storage-sync arbeitet sie nacheinander ab.']);
        }
        $this->repository->addRequest($action, $targetId, $admin);
    }

    /**
     * Gesamtbild fuer Adminseite, Dashboard und Live-Aktualisierung.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $settings = $this->settings();
        $status = $this->repository->status();
        $rows = $this->repository->targets();
        $incident = $this->incidentState();

        $targets = $this->tierViews($rows, $settings, $incident['target_id']);

        $heartbeat = isset($status['heartbeat_age']) ? (int) $status['heartbeat_age'] : null;
        $syncHeartbeat = isset($status['sync_heartbeat_age']) ? (int) $status['sync_heartbeat_age'] : null;
        $health = StorageHealth::evaluate(
            $settings->enabled(),
            $targets,
            $heartbeat,
            $syncHeartbeat,
            [
                'sync_state' => (string) ($status['sync_state'] ?? ''),
                'sync_message' => (string) ($status['sync_message'] ?? ''),
                'pending_files' => (int) ($status['pending_files'] ?? 0),
                'lag_seconds' => (int) ($status['lag_seconds'] ?? 0),
            ],
            $settings->lagWarnSeconds()
        );

        $localTotal = (int) ($status['local_total_bytes'] ?? 0);
        $localFree = (int) ($status['local_free_bytes'] ?? 0);
        $bytesLocal = (int) ($status['bytes_local'] ?? 0);
        $limit = $settings->localLimitBytes();
        $samples = $this->repository->samples(StorageHealth::FORECAST_WINDOW);
        $forecast = StorageHealth::forecast(
            $samples['samples'],
            $localFree,
            $limit > 0 ? max(0, $limit - $bytesLocal) : null,
            $samples['now']
        );

        return [
            'settings' => $settings,
            'office_enabled' => $this->officeEnabled,
            'status' => $status,
            'targets' => $targets,
            'health' => $health,
            'local' => [
                'total_bytes' => $localTotal,
                'free_bytes' => $localFree,
                'used_bytes' => max(0, $localTotal - $localFree),
                'fill' => StorageHealth::fill($localTotal, $localFree, $settings->fillWarnPercent(), $settings->fillCritPercent()),
                'limit_bytes' => (int) ($status['local_limit_bytes'] ?? 0),
                'limit_auto' => $limit === 0,
                'bytes_local' => $bytesLocal,
                'read_bps' => (int) ($status['local_read_bps'] ?? 0),
                'write_bps' => (int) ($status['local_write_bps'] ?? 0),
                'read_iops' => (float) ($status['local_read_iops'] ?? 0),
                'write_iops' => (float) ($status['local_write_iops'] ?? 0),
                'metrics_source' => (string) ($status['metrics_source'] ?? ''),
            ],
            'mode' => (string) ($status['mode'] ?? 'normal'),
            'mode_reason' => (string) ($status['mode_reason'] ?? ''),
            'forecast' => $forecast,
            'forecast_text' => self::forecastText($forecast, $localFree),
            'incidents_open' => $incident['open'],
            'snapshot' => $this->snapshotService()->status(),
        ];
    }

    private function snapshotService(): SnapshotService
    {
        return new SnapshotService($this->repository, $this->settings, $this->secrets);
    }

    /**
     * Teilt die Zeilen aus storage_targets in Cold-Tiers: je Basisziel
     * (parent_id leer) die Mitglieder Basisziel + Erweiterungen (nach Anlage).
     * Erweiterungen ohne vorhandenes Basisziel gelten als eigener Tier.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{root:array<string,mixed>,members:list<array<string,mixed>>}>
     */
    public static function tiers(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $tiers = [];
        $extensions = [];
        foreach ($rows as $row) {
            $parent = self::parentId($row);
            if ($parent !== null && in_array($parent, $ids, true)) {
                $extensions[$parent][] = $row;
            } else {
                $tiers[(int) $row['id']] = ['root' => $row, 'members' => [$row]];
            }
        }
        foreach ($extensions as $parent => $list) {
            if (!isset($tiers[$parent])) {
                continue;
            }
            usort($list, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            $tiers[$parent]['members'] = array_merge($tiers[$parent]['members'], $list);
        }

        return array_values($tiers);
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function parentId(array $row): ?int
    {
        $parent = $row['parent_id'] ?? null;

        return $parent === null || $parent === '' || (int) $parent === 0 ? null : (int) $parent;
    }

    /**
     * Cold-Tiers fuer Adminseite, Dashboard, SNMP: je Tier die Werte des
     * Basisziels, Fuellstand/Datenrate ueber alle Ziele des Tiers summiert und
     * unter "members" jedes Ziel einzeln (ein volles Ziel bleibt voll).
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array<string,mixed>>
     */
    private function tierViews(array $rows, StorageSettings $settings, ?int $frozenId): array
    {
        $warn = $settings->fillWarnPercent();
        $crit = $settings->fillCritPercent();
        $result = [];
        foreach (self::tiers($rows) as $tier) {
            $members = [];
            foreach ($tier['members'] as $index => $row) {
                $members[] = $this->targetView($row, $settings) + [
                    'role' => $index === 0 ? 'root' : 'extension',
                    'level' => $index,
                ];
            }
            $root = $members[0];
            $root['frozen'] = $frozenId !== null && in_array($frozenId, array_column($members, 'id'), true);
            $view = $root;
            if (count($members) > 1) {
                $sum = static fn (string $key): float => array_sum(array_map(static fn (array $m): float => (float) $m[$key], $members));
                $offline = array_values(array_filter($members, static fn (array $m): bool => $m['state'] !== 'online'));
                if ($root['state'] === 'online' && $offline !== []) {
                    $view['state'] = $offline[0]['state'];
                    $view['message'] = $offline[0]['label'] . ': ' . ($offline[0]['message'] !== '' ? $offline[0]['message'] : 'nicht erreichbar.');
                }
                $view['unbounded'] = array_filter($members, static fn (array $m): bool => $m['unbounded']) !== [];
                $view['total_bytes'] = $view['unbounded'] ? 0 : (int) $sum('total_bytes');
                $view['free_bytes'] = $view['unbounded'] ? 0 : (int) $sum('free_bytes');
                $view['capacity_bytes'] = (int) $sum('capacity_bytes');
                $view['fill'] = StorageHealth::fill($view['total_bytes'], $view['free_bytes'], $warn, $crit);
                foreach (['read_bps', 'write_bps'] as $key) {
                    $view[$key] = (int) $sum($key);
                }
                foreach (['read_iops', 'write_iops'] as $key) {
                    $view[$key] = $sum($key);
                }
                $view['synced_files'] = (int) $sum('synced_files');
                $view['synced_bytes'] = (int) $sum('synced_bytes');
                $view['in_sync'] = $view['state'] === 'online' && $root['in_sync'];
            }
            $view['members'] = $members;
            $result[] = $view;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function targetView(array $row, StorageSettings $settings): array
    {
        $age = $row['status_age'] ?? null;
        $state = (string) ($row['state'] ?? 'unknown');
        if ((int) $row['active'] !== 1) {
            $state = 'disabled';
        } elseif ($age === null || (int) $age > StorageHealth::HEARTBEAT_STALE_SECONDS) {
            $state = 'unknown';
        }
        $total = (int) ($row['total_bytes'] ?? 0);
        $free = (int) ($row['free_bytes'] ?? 0);
        $kind = (string) ($row['kind'] ?? self::KIND_SMB) === self::KIND_S3 ? self::KIND_S3 : self::KIND_SMB;

        return [
            'id' => (int) $row['id'],
            'parent_id' => self::parentId($row),
            'label' => (string) $row['label'],
            'kind' => $kind,
            'unc_path' => (string) $row['unc_path'],
            'username' => (string) $row['username'],
            'domain' => (string) $row['domain'],
            'smb_version' => (string) $row['smb_version'],
            's3_endpoint' => (string) ($row['s3_endpoint'] ?? ''),
            's3_region' => (string) ($row['s3_region'] ?? ''),
            's3_bucket' => (string) ($row['s3_bucket'] ?? ''),
            's3_prefix' => (string) ($row['s3_prefix'] ?? ''),
            'capacity_bytes' => (int) ($row['capacity_bytes'] ?? 0),
            // S3 ohne angegebene Kapazitaet: kein Fuellstand (Objektspeicher ohne feste Groesse).
            'unbounded' => $kind === self::KIND_S3 && (int) ($row['capacity_bytes'] ?? 0) <= 0,
            'has_password' => (string) ($row['password'] ?? '') !== '',
            'is_primary' => (int) $row['is_primary'] === 1,
            'active' => (int) $row['active'] === 1,
            'state' => $state,
            'message' => (string) ($row['message'] ?? ''),
            'total_bytes' => $total,
            'free_bytes' => $free,
            'fill' => StorageHealth::fill($total, $free, $settings->fillWarnPercent(), $settings->fillCritPercent()),
            'read_bps' => (int) ($row['read_bps'] ?? 0),
            'write_bps' => (int) ($row['write_bps'] ?? 0),
            'read_iops' => (float) ($row['read_iops'] ?? 0),
            'write_iops' => (float) ($row['write_iops'] ?? 0),
            'in_sync' => $state === 'online' && (int) ($row['in_sync'] ?? 0) === 1,
            'pending_files' => (int) ($row['pending_files'] ?? 0),
            'pending_bytes' => (int) ($row['pending_bytes'] ?? 0),
            'lag_seconds' => (int) ($row['lag_seconds'] ?? 0),
            'synced_files' => (int) ($row['synced_files'] ?? 0),
            'synced_bytes' => (int) ($row['synced_bytes'] ?? 0),
            'state_since' => (string) ($row['state_since'] ?? ''),
            'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
            'frozen' => false,
        ];
    }

    /**
     * Meldung fuer das Dashboard (null = nichts zu melden).
     *
     * @param array<string,mixed>|null $overview
     *
     * @return array{level:string,title:string,message:string,forecast:string}|null
     */
    public function dashboardAlert(?array $overview = null): ?array
    {
        $overview ??= $this->overview();
        /** @var StorageSettings $settings */
        $settings = $overview['settings'];
        $health = $overview['health'];
        if (!$settings->enabled() || $health['active'] === 0) {
            return null;
        }

        if ($health['remote_unavailable']) {
            $reason = $health['agent_running']
                ? 'Kein Speicherziel des Cold-Tiers (SMB-/S3-Tier) ist erreichbar (' . implode(', ', $health['offline_labels']) . ').'
                : 'Der Dienst storage-sync läuft nicht – der Cold-Tier (SMB-/S3-Tier: ' . implode(', ', $health['offline_labels']) . ') wird nicht beschrieben.';

            return [
                'level' => 'error',
                'title' => 'Cold-Tier (SMB-/S3-Tier) nicht verfügbar',
                'message' => $reason . ' Neue und geänderte Daten aus Nextcloud und Euro-Office werden derzeit ausschließlich im Hot-Tier '
                    . '(lokales Storage auf der VM) gespeichert und sind nicht außerhalb gesichert. Ausgelagerte Dateien können bis zur '
                    . 'Wiederherstellung nicht geöffnet werden.',
                'forecast' => (string) $overview['forecast_text'],
            ];
        }
        if ($health['partial']) {
            return [
                'level' => 'warning',
                'title' => 'Cold-Tier (SMB-/S3-Tier) eingeschränkt',
                'message' => 'Nicht erreichbar: ' . implode(', ', $health['offline_labels']) . '. Die Daten liegen weiterhin auf den übrigen Zielen des Cold-Tiers; '
                    . 'nach der Rückkehr gleicht storage-sync die fehlenden Änderungen automatisch nach.',
                'forecast' => '',
            ];
        }
        if ($overview['mode'] === 'remote_only') {
            return [
                'level' => 'warning',
                'title' => 'Hot-Tier (lokales Storage) am Limit',
                'message' => 'Neue und geänderte Daten werden nur noch im Cold-Tier (SMB-/S3-Tier) vorgehalten (' . $overview['mode_reason']
                    . '). Nach Erweiterung oder Freigabe von Platz im Hot-Tier kehrt storage-sync automatisch zur normalen Vorhaltung zurück.',
                'forecast' => '',
            ];
        }
        $full = self::fullTiers($overview['targets']);
        if ($full !== []) {
            return [
                'level' => 'warning',
                'title' => 'Speicherplatz im Cold-Tier (SMB-/S3-Tier) unzureichend',
                'message' => 'Kaum noch freier Speicherplatz: ' . implode(', ', array_map(
                    static fn (array $t): string => $t['label'] . ' (' . number_format((float) $t['fill']['percent'], 1, ',', '.') . ' % belegt)',
                    $full
                )) . '. Erweitern Sie alle Cold-Tiers gemeinsam um je ein weiteres Ziel derselben Art (SMB mit SMB, S3 mit S3); '
                    . 'neue Dateien werden danach auf den Erweiterungen abgelegt.',
                'forecast' => '',
            ];
        }
        $snapshot = $overview['snapshot'] ?? null;
        if (is_array($snapshot) && $snapshot['enabled'] && in_array($snapshot['state'], ['offline', 'invalid'], true)) {
            return [
                'level' => 'warning',
                'title' => 'Snapshot-Speicher (Dateiversionen) nicht verfügbar',
                'message' => 'Der Snapshot-Speicher ' . ($snapshot['unc_path'] !== '' ? '(' . $snapshot['unc_path'] . ') ' : '')
                    . 'ist ' . ($snapshot['state'] === 'invalid' ? 'ungültig' : 'nicht erreichbar')
                    . ($snapshot['message'] !== '' ? ': ' . $snapshot['message'] : '.')
                    . ' Geänderte oder gelöschte Dateien werden bis zur Rückkehr auf dem betroffenen Speicherziel zurückgehalten, '
                    . 'damit keine Vorgängerversion verloren geht; alle übrigen Dateien werden weiter synchronisiert.',
                'forecast' => '',
            ];
        }

        return null;
    }

    /**
     * Aktive, erreichbare Cold-Tiers, deren Gesamtkapazitaet (Basisziel und
     * Erweiterungen) die kritische Fuellgrenze erreicht hat. Ein einzelnes volles
     * Ziel eines erweiterten Tiers zaehlt nicht, solange der Tier Platz hat.
     *
     * @param list<array<string,mixed>> $targets
     *
     * @return list<array<string,mixed>>
     */
    public static function fullTiers(array $targets): array
    {
        return array_values(array_filter(
            $targets,
            static fn (array $t): bool => $t['active'] && $t['state'] === 'online' && ($t['fill']['state'] ?? '') === 'critical'
        ));
    }

    /**
     * Anteil eines Ziels an der Gesamtkapazitaet seines Cold-Tiers (Prozent, fuer
     * die gestapelte Kapazitaetsleiste) – null ohne feste Kapazitaet.
     *
     * @param array<string,mixed> $tier
     * @param array<string,mixed> $member
     */
    public static function memberShare(array $tier, array $member): ?float
    {
        $total = (int) ($tier['total_bytes'] ?? 0);
        if ($total <= 0 || (int) $member['total_bytes'] <= 0) {
            return null;
        }

        return round(100 * (int) $member['total_bytes'] / $total, 2);
    }

    /**
     * Kompakte Live-Daten fuer die Adminseite (JSON).
     *
     * @param array<string,mixed>|null $overview
     *
     * @return array<string,mixed>
     */
    public function liveData(?array $overview = null): array
    {
        $overview ??= $this->overview();
        $snapshot = $overview['snapshot'] ?? $this->snapshotService()->status();
        $local = $overview['local'];
        $status = $overview['status'];

        return [
            'ha' => $overview['health']['ha'],
            'sync' => $overview['health']['sync'],
            'online' => $overview['health']['online'],
            'active' => $overview['health']['active'],
            'alert' => $this->dashboardAlert($overview),
            'forecast' => $overview['forecast_text'],
            'last_sync' => Dates::formatDateTime((string) ($status['last_sync_at'] ?? '')) ?: '–',
            'mode' => $overview['mode'],
            'inventory' => [
                'bytes' => StorageHealth::formatBytes((int) ($status['bytes_total'] ?? 0)),
                'files' => number_format((int) ($status['files_total'] ?? 0), 0, ',', '.'),
                'local' => StorageHealth::formatBytes((int) ($status['bytes_local'] ?? 0)),
                'evicted' => StorageHealth::formatBytes((int) ($status['bytes_evicted'] ?? 0)),
                'evicted_files' => number_format((int) ($status['files_evicted'] ?? 0), 0, ',', '.'),
                'recalls_total' => (int) ($status['recalls_total'] ?? 0),
                'recalls_failed' => (int) ($status['recalls_failed'] ?? 0),
            ],
            'local' => [
                'fill' => $local['fill'],
                'used' => StorageHealth::formatBytes($local['used_bytes']),
                'total' => StorageHealth::formatBytes($local['total_bytes']),
                'read' => StorageHealth::formatRate($local['read_bps']),
                'write' => StorageHealth::formatRate($local['write_bps']),
                'iops' => number_format($local['read_iops'] + $local['write_iops'], 1, ',', '.'),
            ],
            'targets' => array_map(static fn (array $t): array => [
                'id' => $t['id'],
                'state' => $t['state'],
                'message' => $t['message'],
                'free' => StorageHealth::formatBytes($t['free_bytes']),
                'total' => StorageHealth::formatBytes($t['total_bytes']),
                'fill' => $t['fill'],
                'read' => StorageHealth::formatRate($t['read_bps']),
                'write' => StorageHealth::formatRate($t['write_bps']),
                'iops' => number_format($t['read_iops'] + $t['write_iops'], 1, ',', '.'),
                'pending' => $t['pending_files'],
                'pending_bytes' => StorageHealth::formatBytes($t['pending_bytes']),
                'lag' => StorageHealth::formatDuration($t['lag_seconds']),
                'synced_files' => number_format($t['synced_files'], 0, ',', '.'),
                'synced_bytes' => StorageHealth::formatBytes($t['synced_bytes']),
                'in_sync' => $t['in_sync'],
                'unbounded' => (bool) ($t['unbounded'] ?? false),
                'members' => array_map(static fn (array $m): array => [
                    'id' => $m['id'],
                    'state' => $m['state'],
                    'message' => $m['message'],
                    'free' => StorageHealth::formatBytes($m['free_bytes']),
                    'total' => StorageHealth::formatBytes($m['total_bytes']),
                    'used' => StorageHealth::formatBytes(max(0, $m['total_bytes'] - $m['free_bytes'])),
                    'fill' => $m['fill'],
                    'share' => self::memberShare($t, $m),
                    'synced_files' => number_format($m['synced_files'], 0, ',', '.'),
                    'synced_bytes' => StorageHealth::formatBytes($m['synced_bytes']),
                ], $t['members'] ?? []),
            ], $overview['targets']),
            'pending_files' => (int) ($overview['status']['pending_files'] ?? 0),
            'recalls_active' => (int) ($overview['status']['recalls_active'] ?? 0),
            'snapshot' => [
                'state' => $snapshot['state'],
                'state_label' => $snapshot['state_label'],
                'message' => $snapshot['message'],
                'fill' => $snapshot['fill'],
                'used' => StorageHealth::formatBytes($snapshot['used_bytes']),
                'total' => StorageHealth::formatBytes($snapshot['total_bytes']),
                'free' => StorageHealth::formatBytes($snapshot['free_bytes']),
                'read' => StorageHealth::formatRate($snapshot['read_bps']),
                'write' => StorageHealth::formatRate($snapshot['write_bps']),
                'iops' => number_format($snapshot['read_iops'] + $snapshot['write_iops'], 1, ',', '.'),
                'snapshots_total' => number_format($snapshot['snapshots_total'], 0, ',', '.'),
                'snapshots_bytes' => StorageHealth::formatBytes($snapshot['snapshots_bytes']),
                'pending' => $snapshot['pending'],
                'failed' => $snapshot['failed'],
                'last_snapshot' => Dates::formatDateTime($snapshot['last_snapshot_at']) ?: '–',
            ],
        ];
    }

    public const SNMP_CHECKS = ['storage_ha', 'storage_sync', 'storage_hot_fill', 'storage_cold_fill', 'storage_snapshot', 'storage_metrics', 'storage_targets'];

    /**
     * Werte fuer den SNMP-Dienst (scripts/storage_status.php). Die erste Zeile
     * wird als extOutput, der Exit-Code als extResult ausgeliefert
     * (0 = OK, 1 = Warnung, 2 = kritisch, 3 = inaktiv/unbekannt).
     *
     * @param array<string,mixed>|null $overview
     *
     * @return array{exit:int,lines:list<string>}
     */
    public function snmp(string $check, ?array $overview = null): array
    {
        if (!in_array($check, self::SNMP_CHECKS, true)) {
            return ['exit' => 3, 'lines' => ['unbekannte Pruefung: ' . $check]];
        }
        $overview ??= $this->overview();
        /** @var StorageSettings $settings */
        $settings = $overview['settings'];
        $health = $overview['health'];
        $local = $overview['local'];
        $status = $overview['status'];
        $enabled = $settings->enabled();
        $ascii = static fn (string $text): string => self::snmpText($text);

        switch ($check) {
            case 'storage_ha':
                return ['exit' => $health['ha']['exit'], 'lines' => [$ascii('storage_ha: ' . $health['ha']['state'] . ' - ' . $health['ha']['message'])]];

            case 'storage_sync':
                $sync = $health['sync'];
                $line = 'storage_sync: ' . $sync['state'] . ' - ' . $sync['message'];
                if ($enabled && $sync['state'] !== 'disabled') {
                    $line .= sprintf(' (ausstehend %d Datei(en), Rueckstand %d s)', (int) ($status['pending_files'] ?? 0), (int) ($status['lag_seconds'] ?? 0));
                }

                return ['exit' => $sync['exit'], 'lines' => [$ascii($line)]];

            case 'storage_hot_fill':
                if (!$enabled) {
                    return ['exit' => 3, 'lines' => ['storage_hot_fill: Speicher-Tiering ist nicht aktiviert']];
                }
                $fill = $this->hotFill($overview);
                if ($fill['percent'] === null) {
                    return ['exit' => 3, 'lines' => ['storage_hot_fill: keine Messwerte (storage-sync laeuft nicht?)']];
                }

                return ['exit' => StorageHealth::EXIT[$fill['state']] ?? 3, 'lines' => [$ascii(sprintf(
                    'storage_hot_fill: %s%% (Hot-Tier, lokales Storage: %s von %s belegt%s, Modus %s)',
                    number_format($fill['percent'], 1, '.', ''),
                    StorageHealth::formatBytes($local['used_bytes']),
                    StorageHealth::formatBytes($local['total_bytes']),
                    $local['limit_bytes'] > 0 ? ', Daten ' . StorageHealth::formatBytes($local['bytes_local']) . ' von Limit ' . StorageHealth::formatBytes($local['limit_bytes']) : '',
                    $overview['mode']
                ))]];

            case 'storage_cold_fill':
                $active = array_values(array_filter($overview['targets'], static fn (array $t): bool => $t['active']));
                if (!$enabled || $active === []) {
                    return ['exit' => 3, 'lines' => ['storage_cold_fill: kein aktives Speicherziel']];
                }
                $measured = array_values(array_filter($active, static fn (array $t): bool => $t['state'] === 'online' && $t['fill']['percent'] !== null));
                $unbounded = array_values(array_filter($active, static fn (array $t): bool => $t['state'] === 'online' && $t['fill']['percent'] === null && !empty($t['unbounded'])));
                $missing = count($active) - count($measured) - count($unbounded);
                if ($measured === [] && $unbounded !== []) {
                    return ['exit' => 0, 'lines' => [$ascii(sprintf(
                        'storage_cold_fill: 0%% (Cold-Tier, SMB-/S3-Tier: %s ohne Kapazitaetsgrenze%s)',
                        implode(', ', array_map(static fn (array $t): string => $t['label'], $unbounded)),
                        $missing > 0 ? '; ' . $missing . ' Ziel(e) nicht erreichbar' : ''
                    ))]];
                }
                if ($measured === []) {
                    return ['exit' => 2, 'lines' => ['storage_cold_fill: kein Speicherziel des Cold-Tiers erreichbar']];
                }
                usort($measured, static fn (array $a, array $b): int => $b['fill']['percent'] <=> $a['fill']['percent']);
                $top = $measured[0];
                // Je Cold-Tier die Gesamtbelegung aller Ziele (Basisziel + Erweiterungen).
                $parts = array_map(static fn (array $t): string => $t['label'] . ' ' . number_format((float) $t['fill']['percent'], 1, '.', '') . '%'
                    . (count($t['members'] ?? []) > 1 ? ' (' . count($t['members']) . ' Ziele)' : ''), $measured);
                foreach ($unbounded as $t) {
                    $parts[] = $t['label'] . ' ohne Grenze';
                }

                return ['exit' => StorageHealth::EXIT[$top['fill']['state']] ?? 3, 'lines' => [$ascii(sprintf(
                    'storage_cold_fill: %s%% (Cold-Tier, SMB-/S3-Tier: %s%s)',
                    number_format((float) $top['fill']['percent'], 1, '.', ''),
                    implode(', ', $parts),
                    $missing > 0 ? '; ' . $missing . ' Ziel(e) nicht erreichbar' : ''
                ))]];

            case 'storage_snapshot':
                $snap = $overview['snapshot'];
                if (!$snap['enabled']) {
                    return ['exit' => 3, 'lines' => ['storage_snapshot: Snapshot-Speicher ist nicht aktiviert']];
                }
                if ($snap['state'] !== 'online') {
                    return ['exit' => 2, 'lines' => [$ascii('storage_snapshot: ' . $snap['state'] . ' - ' . ($snap['message'] !== '' ? $snap['message'] : 'nicht erreichbar'))]];
                }
                $exit = StorageHealth::EXIT[$snap['fill']['state']] ?? 3;
                if ($snap['failed'] > 0) {
                    $exit = max($exit, 1);
                }

                return ['exit' => $exit, 'lines' => [$ascii(sprintf(
                    'storage_snapshot: %s%% (Snapshot-Speicher: %s von %s belegt, %d Versionen, %d vorgemerkt, %d fehlgeschlagen)',
                    $snap['fill']['percent'] === null ? '0' : number_format((float) $snap['fill']['percent'], 1, '.', ''),
                    StorageHealth::formatBytes($snap['used_bytes']),
                    StorageHealth::formatBytes($snap['total_bytes']),
                    $snap['snapshots_total'],
                    $snap['pending'],
                    $snap['failed']
                ))]];

            case 'storage_metrics':
                $hot = $this->hotFill($overview);
                $cold = array_values(array_filter($overview['targets'], static fn (array $t): bool => $t['active'] && $t['state'] === 'online'));
                $sum = static fn (string $key): float => array_sum(array_map(static fn (array $t): float => (float) $t[$key], $cold));
                $coldTotal = (int) $sum('total_bytes');
                $coldFree = (int) $sum('free_bytes');
                $coldFill = StorageHealth::fill($coldTotal, $coldFree, $settings->fillWarnPercent(), $settings->fillCritPercent());
                $forecast = $overview['forecast'];
                $values = [
                    'ha_state' => $health['ha']['state'],
                    'sync_state' => $health['sync']['state'],
                    'mode' => $overview['mode'],
                    'targets_active' => $health['active'],
                    'targets_online' => $health['online'],
                    'hot_fill_percent' => $hot['percent'] ?? -1,
                    'hot_total_bytes' => $local['total_bytes'],
                    'hot_used_bytes' => $local['used_bytes'],
                    'hot_free_bytes' => $local['free_bytes'],
                    'hot_limit_bytes' => $local['limit_bytes'],
                    'hot_data_bytes' => $local['bytes_local'],
                    'hot_read_bps' => $local['read_bps'],
                    'hot_write_bps' => $local['write_bps'],
                    'hot_read_mbps' => round($local['read_bps'] / StorageSettings::MIB, 2),
                    'hot_write_mbps' => round($local['write_bps'] / StorageSettings::MIB, 2),
                    'hot_read_iops' => round($local['read_iops'], 1),
                    'hot_write_iops' => round($local['write_iops'], 1),
                    'cold_fill_percent' => $coldFill['percent'] ?? -1,
                    'cold_total_bytes' => $coldTotal,
                    'cold_free_bytes' => $coldFree,
                    'cold_read_bps' => (int) $sum('read_bps'),
                    'cold_write_bps' => (int) $sum('write_bps'),
                    'cold_read_mbps' => round($sum('read_bps') / StorageSettings::MIB, 2),
                    'cold_write_mbps' => round($sum('write_bps') / StorageSettings::MIB, 2),
                    'cold_read_iops' => round($sum('read_iops'), 1),
                    'cold_write_iops' => round($sum('write_iops'), 1),
                    'files_total' => (int) ($status['files_total'] ?? 0),
                    'bytes_total' => (int) ($status['bytes_total'] ?? 0),
                    'files_evicted' => (int) ($status['files_evicted'] ?? 0),
                    'bytes_evicted' => (int) ($status['bytes_evicted'] ?? 0),
                    'pending_files' => (int) ($status['pending_files'] ?? 0),
                    'pending_bytes' => (int) ($status['pending_bytes'] ?? 0),
                    'lag_seconds' => (int) ($status['lag_seconds'] ?? 0),
                    'recalls_active' => (int) ($status['recalls_active'] ?? 0),
                    'recalls_total' => (int) ($status['recalls_total'] ?? 0),
                    'recalls_failed' => (int) ($status['recalls_failed'] ?? 0),
                    'forecast_days' => $forecast['days_free'] !== null ? round((float) $forecast['days_free'], 1) : -1,
                ];
                $lines = [];
                foreach ($values as $key => $value) {
                    $lines[] = $key . '=' . (string) $value;
                }

                return ['exit' => $health['ha']['exit'], 'lines' => $lines];

            case 'storage_targets':
            default:
                $lines = [];
                $rows = [];
                foreach ($overview['targets'] as $tier) {
                    // Je physischem Ziel eine Zeile; Rueckstand/Synchronitaet gelten fuer den ganzen Tier.
                    foreach ($tier['members'] ?? [$tier] as $member) {
                        $rows[] = $member + ['tier_id' => $tier['id'], 'tier_in_sync' => $tier['in_sync'],
                            'tier_pending' => $tier['pending_files'], 'tier_lag' => $tier['lag_seconds']];
                    }
                }
                foreach ($rows as $t) {
                    $lines[] = $ascii(sprintf(
                        'id=%d label=%s state=%s active=%d primary=%d fill_percent=%s total_bytes=%d free_bytes=%d read_mbps=%s write_mbps=%s read_iops=%s write_iops=%s in_sync=%d pending_files=%d lag_seconds=%d kind=%s tier=%d role=%s',
                        $t['id'],
                        str_replace(' ', '_', $t['label']),
                        $t['state'],
                        $t['active'] ? 1 : 0,
                        $t['is_primary'] ? 1 : 0,
                        $t['fill']['percent'] !== null ? number_format((float) $t['fill']['percent'], 1, '.', '') : '-1',
                        $t['total_bytes'],
                        $t['free_bytes'],
                        number_format($t['read_bps'] / StorageSettings::MIB, 2, '.', ''),
                        number_format($t['write_bps'] / StorageSettings::MIB, 2, '.', ''),
                        number_format($t['read_iops'], 1, '.', ''),
                        number_format($t['write_iops'], 1, '.', ''),
                        $t['tier_in_sync'] ? 1 : 0,
                        $t['tier_pending'],
                        $t['tier_lag'],
                        $t['kind'] ?? self::KIND_SMB,
                        $t['tier_id'],
                        $t['role'] ?? 'root'
                    ));
                }
                if ($lines === []) {
                    return ['exit' => 3, 'lines' => ['keine Speicherziele']];
                }

                return ['exit' => $health['ha']['exit'], 'lines' => $lines];
        }
    }

    /**
     * Fuellstand des Hot-Tiers: der hoehere Wert aus Belegung des Volumes und
     * Anteil der lokal vorgehaltenen Daten am eingestellten Limit.
     *
     * @param array<string,mixed> $overview
     *
     * @return array{percent:?float,state:string}
     */
    public function hotFill(array $overview): array
    {
        /** @var StorageSettings $settings */
        $settings = $overview['settings'];
        $local = $overview['local'];
        $fill = $local['fill'];
        if ($local['limit_bytes'] > 0) {
            $limitFill = StorageHealth::fill($local['limit_bytes'], $local['limit_bytes'] - $local['bytes_local'], $settings->fillWarnPercent(), $settings->fillCritPercent());
            if ($fill['percent'] === null || $limitFill['percent'] > $fill['percent']) {
                $fill = $limitFill;
            }
        }

        return $fill;
    }

    /** SNMP-Ausgabe: ASCII ohne Steuerzeichen (Umlaute umschreiben). */
    private static function snmpText(string $text): string
    {
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', '–' => '-', '…' => '...', 'Ø' => 'avg']);
        $text = (string) preg_replace('/[^\x20-\x7E]/', '?', $text);

        return mb_substr($text, 0, 250);
    }

    /**
     * @param array{rate_per_day:?float,days_free:?float,days_limit:?float,until_free:?int,basis_seconds:int,growing:bool} $forecast
     */
    public static function forecastText(array $forecast, int $freeBytes): string
    {
        $free = StorageHealth::formatBytes($freeBytes);
        if ($forecast['rate_per_day'] === null) {
            return 'Hochrechnung: Für eine Hochrechnung liegen noch nicht genügend Messwerte vor (mindestens 1 Stunde). '
                . 'Derzeit frei auf der VM: ' . $free . '.';
        }
        $basis = StorageHealth::formatDuration($forecast['basis_seconds']);
        if (!$forecast['growing']) {
            return 'Hochrechnung: Der Datenbestand ist in den letzten ' . $basis . ' nicht gewachsen – der Hot-Tier (lokales Storage, frei: '
                . $free . ') reicht bei gleichem Verhalten voraussichtlich weiterhin aus.';
        }
        $text = sprintf(
            'Hochrechnung anhand des bisherigen Verhaltens (Ø %s pro Tag, Grundlage: letzte %s): Der Hot-Tier (lokales Storage, frei: %s) reicht noch %s',
            StorageHealth::formatBytes((float) $forecast['rate_per_day']),
            $basis,
            $free,
            StorageHealth::formatDays((float) $forecast['days_free'])
        );
        if ($forecast['until_free'] !== null && $forecast['days_free'] < 3650) {
            $text .= ' (bis ca. ' . date('d.m.Y H:i', $forecast['until_free']) . ')';
        }
        $text .= '.';
        if ($forecast['days_limit'] !== null && $forecast['days_limit'] < (float) $forecast['days_free']) {
            $text .= ' Das eingestellte Limit des Hot-Tiers ist ' . ($forecast['days_limit'] <= 0 ? 'bereits erreicht' : 'in ' . StorageHealth::formatDays((float) $forecast['days_limit']) . ' erreicht') . '.';
        }

        return $text;
    }

    /**
     * Mount-Quelle fuer mount.cifs (//host/freigabe[/unterordner]).
     */
    public static function mountDevice(string $unc): ?string
    {
        $parsed = NetworkDriveService::parseUnc($unc);
        if ($parsed === null) {
            return null;
        }

        return '//' . $parsed['host'] . '/' . $parsed['share'] . ($parsed['root'] !== '' ? '/' . $parsed['root'] : '');
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     *
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    public function validateTarget(array $input, ?array $existing): array
    {
        $errors = [];
        $label = Validator::cleanText((string) ($input['label'] ?? ''), 100);
        if ($label === '') {
            $errors['label'] = 'Bitte eine Bezeichnung angeben.';
        }

        $kind = (string) ($input['kind'] ?? self::KIND_SMB);
        if (!array_key_exists($kind, self::KINDS)) {
            $errors['kind'] = 'Ungültige Art des Speicherziels.';
            throw new ValidationException($errors);
        }
        if ($kind === self::KIND_S3) {
            return $this->validateS3Target($input, $existing, $label, $errors);
        }
        $kindChanged = $existing !== null && (string) ($existing['kind'] ?? self::KIND_SMB) !== $kind;

        $parsed = NetworkDriveService::parseUnc((string) ($input['unc_path'] ?? ''));
        $unc = $parsed['unc'] ?? '';
        if ($parsed === null) {
            $errors['unc_path'] = 'Bitte einen UNC-Pfad wie \\\\server\\freigabe oder \\\\server\\freigabe\\ordner angeben.';
        } else {
            $other = $this->repository->findTargetByUnc($unc);
            if ($other !== null && ($existing === null || $other !== (int) $existing['id'])) {
                $errors['unc_path'] = 'Dieses Ziel ist bereits eingerichtet.';
            }
            $snapshotUnc = $this->snapshotService()->settings()->uncPath();
            if ($snapshotUnc !== '' && strcasecmp(rtrim($snapshotUnc, '\\'), rtrim($unc, '\\')) === 0) {
                $errors['unc_path'] = 'Diese Freigabe ist als Snapshot-Speicher eingerichtet und kann nicht zugleich Speicherziel sein.';
            }
        }

        $username = trim((string) ($input['username'] ?? ''));
        if ($username !== '' && preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors['username'] = 'Benutzername ohne Domäne angeben (Domäne im eigenen Feld), keine Steuerzeichen, Komma oder Schrägstriche.';
        }
        $domain = trim((string) ($input['domain'] ?? ''));
        if ($domain !== '' && preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            $errors['domain'] = 'Ungültige Domäne (z. B. FIRMA oder firma.local).';
        }

        $password = (string) ($input['password'] ?? '');
        $clear = !empty($input['password_clear']);
        if ($password !== '' && (strlen($password) > 256 || preg_match('/[\x00\r\n]/', $password) === 1)) {
            $errors['password'] = 'Das Kennwort darf höchstens 256 Zeichen und keine Zeilenumbrüche enthalten.';
        }

        $version = (string) ($input['smb_version'] ?? 'auto');
        if (!array_key_exists($version, self::SMB_VERSIONS)) {
            $errors['smb_version'] = 'Ungültige SMB-Version.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $values = [
            'label' => $label,
            'kind' => self::KIND_SMB,
            'unc_path' => $unc,
            'username' => $username,
            'domain' => $domain,
            'smb_version' => $version,
            's3_endpoint' => '',
            's3_region' => '',
            's3_bucket' => '',
            's3_prefix' => '',
            's3_path_style' => 1,
            's3_verify_tls' => 1,
            'capacity_bytes' => 0,
            'is_primary' => !empty($input['is_primary']) ? 1 : 0,
            'active' => !array_key_exists('active', $input) || !empty($input['active']) ? 1 : 0,
        ];
        if ($password !== '') {
            $values['password'] = $this->secrets->encrypt($password);
        } elseif ($clear || $existing === null || $kindChanged) {
            // Ein S3-Secret ist kein SMB-Kennwort.
            $values['password'] = null;
        }

        return $values;
    }

    /**
     * Pruefung eines S3-Ziels (Endpunkt, Bucket, Praefix, Access Key, Secret).
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @param array<string,string> $errors
     *
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private function validateS3Target(array $input, ?array $existing, string $label, array $errors): array
    {
        $kindChanged = $existing !== null && (string) ($existing['kind'] ?? self::KIND_SMB) !== self::KIND_S3;

        $endpoint = self::normalizeS3Endpoint((string) ($input['s3_endpoint'] ?? ''));
        if ($endpoint === null) {
            $errors['s3_endpoint'] = 'Bitte die Adresse des S3-Endpunkts wie https://s3.example.local:9000 oder https://s3.eu-central-1.amazonaws.com angeben (ohne Pfad).';
        }
        $region = strtolower(trim((string) ($input['s3_region'] ?? '')));
        if ($region !== '' && preg_match(self::S3_REGION_PATTERN, $region) !== 1) {
            $errors['s3_region'] = 'Ungültige Region (z. B. eu-central-1 oder us-east-1).';
        }
        $bucket = strtolower(trim((string) ($input['s3_bucket'] ?? '')));
        if (preg_match(self::S3_BUCKET_PATTERN, $bucket) !== 1 || str_contains($bucket, '..')) {
            $errors['s3_bucket'] = 'Ungültiger Bucket-Name (3–63 Zeichen: Kleinbuchstaben, Ziffern, Punkt und Bindestrich).';
        }
        $prefix = self::normalizeS3Prefix((string) ($input['s3_prefix'] ?? ''));
        if ($prefix === null) {
            $errors['s3_prefix'] = 'Ungültiges Präfix: Ordnernamen aus Buchstaben, Ziffern, Punkt, Unter- und Bindestrich, getrennt durch „/“.';
        }

        $location = '';
        if ($endpoint !== null && !isset($errors['s3_bucket']) && $prefix !== null) {
            $location = self::s3Location($endpoint, $bucket, $prefix);
            $other = $this->repository->findTargetByUnc($location);
            if ($other !== null && ($existing === null || $other !== (int) $existing['id'])) {
                $errors['s3_bucket'] = 'Dieses Ziel (Endpunkt, Bucket und Präfix) ist bereits eingerichtet.';
            }
        }

        $accessKey = trim((string) ($input['s3_access_key'] ?? ''));
        if (preg_match(self::S3_KEY_PATTERN, $accessKey) !== 1) {
            $errors['s3_access_key'] = 'Bitte die Access Key ID angeben (3–128 Zeichen, ohne Leerzeichen und Doppelpunkt).';
        }
        $secret = (string) ($input['s3_secret_key'] ?? '');
        $hasSecret = $existing !== null && !$kindChanged && (string) ($existing['password'] ?? '') !== '';
        if ($secret === '' && !$hasSecret) {
            $errors['s3_secret_key'] = 'Bitte den Secret Access Key angeben.';
        } elseif ($secret !== '' && (strlen($secret) > 256 || preg_match('/[\x00-\x20\x7F]/', $secret) === 1)) {
            $errors['s3_secret_key'] = 'Der Secret Access Key darf höchstens 256 Zeichen und keine Leer- oder Steuerzeichen enthalten.';
        }

        $capacity = trim((string) ($input['capacity_gb'] ?? '0'));
        if ($capacity === '') {
            $capacity = '0';
        }
        if (!ctype_digit($capacity) || (int) $capacity > self::S3_MAX_CAPACITY_GB) {
            $errors['capacity_gb'] = 'Bitte eine ganze Zahl in GB angeben (0 = ohne Grenze).';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $values = [
            'label' => $label,
            'kind' => self::KIND_S3,
            'unc_path' => $location,
            'username' => $accessKey,
            'domain' => '',
            'smb_version' => 'auto',
            's3_endpoint' => (string) $endpoint,
            's3_region' => $region,
            's3_bucket' => $bucket,
            's3_prefix' => (string) $prefix,
            's3_path_style' => !empty($input['s3_path_style']) ? 1 : 0,
            's3_verify_tls' => !array_key_exists('s3_verify_tls', $input) || !empty($input['s3_verify_tls']) ? 1 : 0,
            'capacity_bytes' => (int) $capacity * 1073741824,
            'is_primary' => !empty($input['is_primary']) ? 1 : 0,
            'active' => !array_key_exists('active', $input) || !empty($input['active']) ? 1 : 0,
        ];
        if ($secret !== '') {
            $values['password'] = $this->secrets->encrypt($secret);
        }

        return $values;
    }

    /**
     * Endpunkt als scheme://host[:port] (ohne Pfad, Zugangsdaten, Query).
     */
    public static function normalizeS3Endpoint(string $endpoint): ?string
    {
        $endpoint = rtrim(trim($endpoint), '/');
        if ($endpoint === '' || strlen($endpoint) > 255 || preg_match('/[\x00-\x20\x7F]/', $endpoint) === 1) {
            return null;
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && $parts['path'] !== '/')) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $ipv6 = str_starts_with($host, '[') && filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$ipv6 && (strlen($host) > 253 || preg_match(self::S3_HOST_PATTERN, $host) !== 1)) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Praefix (Unterordner im Bucket) ohne fuehrende/abschliessende "/".
     */
    public static function normalizeS3Prefix(string $prefix): ?string
    {
        $prefix = trim(str_replace('\\', '/', trim($prefix)), '/');
        if ($prefix === '') {
            return '';
        }
        if (strlen($prefix) > 255) {
            return null;
        }
        foreach (explode('/', $prefix) as $segment) {
            if (preg_match(self::S3_PREFIX_SEGMENT, $segment) !== 1 || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $prefix;
    }

    /**
     * Kanonischer Ort eines S3-Ziels: s3://host[:port]/bucket[/praefix].
     */
    public static function s3Location(string $endpoint, string $bucket, string $prefix): string
    {
        $host = (string) preg_replace('#^https?://#', '', $endpoint);

        return 's3://' . $host . '/' . $bucket . ($prefix !== '' ? '/' . $prefix : '');
    }

    /**
     * Ausgelagerte Daten muessen auf mindestens einem verbleibenden Ziel liegen.
     *
     * @throws ValidationException
     */
    private function assertRemovable(int $id, string $verb): void
    {
        $evicted = (int) ($this->repository->status()['files_evicted'] ?? 0);
        if ($evicted === 0) {
            return;
        }
        foreach (self::tiers($this->repository->targets()) as $tier) {
            $row = $tier['root'];
            $ids = array_map(static fn (array $m): int => (int) $m['id'], $tier['members']);
            if (!in_array($id, $ids, true) && (int) $row['active'] === 1 && (int) ($row['in_sync'] ?? 0) === 1) {
                return;
            }
        }
        throw new ValidationException(['target' => sprintf(
            'Das Ziel kann nicht %s werden: %d ausgelagerte Datei(en) liegen auf keinem anderen aktiven, synchronen Ziel.',
            $verb,
            $evicted
        )]);
    }

    /**
     * @return array<string,string>
     */
    private function instanceIdValue(): array
    {
        $current = $this->settings->get('storage_instance_id', '');

        return $current !== '' ? [] : ['storage_instance_id' => bin2hex(random_bytes(16))];
    }
}
