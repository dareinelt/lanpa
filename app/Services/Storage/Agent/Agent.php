<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Core\Container;
use App\Core\Database;
use App\Repositories\IncidentRepository;
use App\Repositories\StorageRepository;
use App\Services\Storage\IncidentSettings;
use App\Services\Storage\StorageHealth;
use App\Services\Storage\StorageSettings;
use PDOException;
use Throwable;

/**
 * Container storage-sync: drei dauerhaft laufende Prozesse.
 *
 * - monitor: bindet die Speicherziele des Cold-Tiers (SMB-/S3-Tier) ein, misst
 *   Fuellstand, Datenrate und IOPS und bewertet den HA-Status (alle 5 s).
 * - sync:    erfasst Aenderungen, synchronisiert alle Ziele, sichert die
 *   Nextcloud-Datenbank und verschiebt Dateien zwischen Hot- und Cold-Tier.
 * - recall:  holt ausgelagerte Dateien auf Anforderung von Nextcloud zurueck
 *   (bis zu vier gleichzeitig, Fortschritt fuer den Fortschrittsbalken).
 */
final class Agent
{
    private const MONITOR_INTERVAL = 5;
    private const SAMPLE_INTERVAL = 300;
    private const MAX_RECALLS = 4;
    private const RECALL_PROGRESS_INTERVAL = 0.5;

    /** @var array<string,string> */
    private array $config;

    private ?Catalog $catalog = null;

    private ?TieringStore $store = null;

    private ?FileCopier $copier = null;

    /**
     * @param array<string,string> $config Pfade (siehe defaults())
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + self::defaults();
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $env = static fn (string $key, string $default): string => (string) (getenv($key) ?: $default);

        return [
            'state_dir' => $env('STORAGE_STATE_DIR', '/var/lib/storage-sync'),
            'tiering_dir' => $env('STORAGE_TIERING_DIR', '/var/lib/lanpa-tiering'),
            'mount_base' => $env('STORAGE_MOUNT_BASE', '/mnt/targets'),
            'credential_dir' => $env('STORAGE_CREDENTIAL_DIR', '/run/storage-sync'),
            'nextcloud_data' => $env('STORAGE_NEXTCLOUD_DATA', '/data/nextcloud-data'),
            'nextcloud_config' => $env('STORAGE_NEXTCLOUD_CONFIG', '/data/nextcloud-html/config'),
            'eurooffice_data' => $env('STORAGE_EUROOFFICE_DATA', '/data/eurooffice-data'),
            'db_host' => $env('NEXTCLOUD_DB_HOST', 'nextcloud-db'),
            'db_secret' => $env('NEXTCLOUD_DB_PASSWORD_FILE', '/run/secrets/nextcloud_db_password'),
            'owner' => $env('STORAGE_DATA_OWNER', '33:33'),
            'script' => $env('STORAGE_SYNC_SCRIPT', dirname(__DIR__, 4) . '/scripts/storage_sync.php'),
        ];
    }

    // --- gemeinsame Bausteine ----------------------------------------------

    /**
     * @return array<string,string>
     */
    public function sources(): array
    {
        return [
            PathRules::SOURCE_NEXTCLOUD_DATA => $this->config['nextcloud_data'],
            PathRules::SOURCE_NEXTCLOUD_CONFIG => $this->config['nextcloud_config'],
            PathRules::SOURCE_EUROOFFICE_DATA => $this->config['eurooffice_data'],
            PathRules::SOURCE_NEXTCLOUD_DB => $this->config['state_dir'] . '/dumps',
        ];
    }

    public function catalog(): Catalog
    {
        return $this->catalog ??= new Catalog($this->config['state_dir'] . '/catalog.sqlite');
    }

    public function store(): TieringStore
    {
        [$uid, $gid] = array_map('intval', explode(':', $this->config['owner']) + [1 => '33']);

        return $this->store ??= new TieringStore($this->config['tiering_dir'], $this->config['nextcloud_data'], [$uid, $gid]);
    }

    public function targetMap(): TargetMap
    {
        return new TargetMap($this->config['state_dir'] . '/targets.json');
    }

    public function copier(): FileCopier
    {
        return $this->copier ??= new FileCopier($this->catalog());
    }

    public function engine(?ThreatDetector $detector = null): SyncEngine
    {
        return new SyncEngine(
            $this->catalog(),
            $this->store(),
            $this->targetMap(),
            $this->copier(),
            $this->sources(),
            function (string $level, string $category, string $message, ?int $targetId): void {
                $this->event($level, $category, $message, $targetId);
            },
            null,
            $detector
        );
    }

    public function detector(): ThreatDetector
    {
        return new ThreatDetector($this->catalog(), $this->incidentSettings());
    }

    public function recaller(): Recaller
    {
        return new Recaller($this->catalog(), $this->store(), $this->targetMap(), $this->copier());
    }

    private function repository(): StorageRepository
    {
        return Container::storageRepository();
    }

    private function settings(): StorageSettings
    {
        Container::settings()->resetCache();

        return new StorageSettings(Container::settings()->all());
    }

    private function incidentSettings(): IncidentSettings
    {
        return new IncidentSettings(Container::settings()->all());
    }

    private function incidents(): IncidentRepository
    {
        return Container::incidentRepository();
    }

    /**
     * Offene Sicherheitsvorfaelle (leer, falls die Tabelle noch fehlt).
     *
     * @return list<array<string,mixed>>
     */
    private function openIncidents(): array
    {
        try {
            return $this->incidents()->open();
        } catch (PDOException $exception) {
            $this->log('error', 'Sicherheitsvorfälle können nicht gelesen werden: ' . $exception->getMessage());

            return [];
        }
    }

    /**
     * Schreibgeschuetztes Schutzziel der offenen Vorfaelle.
     *
     * @param list<array<string,mixed>> $open
     */
    private static function frozenTargetId(array $open): ?int
    {
        foreach ($open as $incident) {
            if ($incident['frozen_target_id'] !== null) {
                return (int) $incident['frozen_target_id'];
            }
        }

        return null;
    }

    /**
     * Konfiguration fuer Nextcloud (TieringWrapper): Tiering aktiv,
     * Wartezeit fuer Rueckholungen und eingeschraenkte Benutzer.
     *
     * @param list<array<string,mixed>> $open
     */
    private function publishTieringConfig(StorageSettings $settings, array $open): void
    {
        $restricted = [];
        foreach ($open as $incident) {
            if ((int) $incident['user_restricted'] === 1 && (string) $incident['uid'] !== '') {
                $restricted[(string) $incident['uid']] = true;
            }
        }
        $this->store()->writeConfig([
            'enabled' => $settings->enabled(),
            'recall_timeout' => $settings->recallTimeout(),
            'updated' => time(),
            'restricted' => array_keys($restricted),
            'restriction_message' => $this->incidentSettings()->userMessage(),
        ]);
    }

    private function event(string $level, string $category, string $message, ?int $targetId = null): void
    {
        $this->log($level, $message);
        try {
            $this->repository()->addEvent($level, $category, $message, $targetId);
        } catch (Throwable) {
            // Ereignisse sind nicht kritisch.
        }
    }

    private function log(string $level, string $message): void
    {
        fwrite($level === 'info' ? STDOUT : STDERR, '[' . date('c') . '] ' . $level . ': ' . $message . PHP_EOL);
    }

    /**
     * Fuehrt einen Schritt aus; Datenbankfehler fuehren zu neuem Verbindungsaufbau.
     */
    private function guarded(string $name, callable $step): void
    {
        try {
            $step();
        } catch (PDOException $exception) {
            $this->log('error', $name . ': Datenbankfehler – ' . $exception->getMessage());
            Database::set(null);
            Container::reset();
            sleep(5);
        } catch (Throwable $exception) {
            $this->log('error', $name . ': ' . $exception->getMessage() . ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ')');
            sleep(2);
        }
    }

    // --- Monitor ------------------------------------------------------------

    public function monitor(): never
    {
        $this->log('info', 'storage-sync monitor gestartet.');
        $metrics = new Metrics();
        $previous = [];
        $sparse = null;
        $lastSparseCheck = 0;
        $lastTrim = 0;
        while (true) {
            $started = microtime(true);
            $this->guarded('monitor', function () use ($metrics, &$previous, &$sparse, &$lastSparseCheck, &$lastTrim): void {
                Container::reset();
                $settings = $this->settings();
                $store = $this->store();
                $store->prepare();
                $open = $this->openIncidents();
                $this->publishTieringConfig($settings, $open);
                if ($sparse === null || time() - $lastSparseCheck > 3600) {
                    $sparse = $store->sparseSupported();
                    $lastSparseCheck = time();
                    $this->catalog()->setMeta('sparse_supported', $sparse ? '1' : '0');
                }
                $frozen = $this->incidentSettings()->freezeTarget() ? self::frozenTargetId($open) : null;
                $this->monitorPass($settings, $metrics, $previous, $sparse, $frozen);
                if (time() - $lastTrim > 3600) {
                    $this->repository()->trimEvents();
                    $lastTrim = time();
                }
            });
            $sleep = self::MONITOR_INTERVAL - (microtime(true) - $started);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1e6));
            }
        }
    }

    /**
     * @param array<int,string> $previous Letzter Zustand je Ziel
     */
    private function monitorPass(StorageSettings $settings, Metrics $metrics, array &$previous, bool $sparse, ?int $frozen = null): void
    {
        $repository = $this->repository();
        $instance = $settings->instanceId();
        $mounter = new Mounter($this->config['mount_base'], $this->config['credential_dir'], Container::secretBox(), $instance, s3TempDir: $this->config['state_dir'] . '/s3-tmp');
        $rows = $repository->targets();
        $mounter->cleanup(array_map(static fn (array $r): int => (int) $r['id'], $rows));

        $remount = [];
        while (($request = $repository->claimRequest(['remount'])) !== null) {
            $remount[] = $request['target_id'] === null ? 0 : (int) $request['target_id'];
            $repository->finishRequest((int) $request['id'], 'Neu eingebunden.');
        }

        $map = [];
        $cifs = Metrics::cifsCounters();
        $counters = $this->catalog()->counters();
        $evaluated = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!$settings->enabled() || $instance === '') {
                $mounter->unmount($id);
                $check = ['state' => 'disabled', 'message' => 'Speicher-Tiering ist nicht aktiviert.', 'total_bytes' => 0, 'free_bytes' => 0, 'root' => $mounter->mountPoint($id), 'share' => ''];
            } else {
                $check = $mounter->check($row, in_array(0, $remount, true) || in_array($id, $remount, true), $id === $frozen);
            }

            $key = $check['share'] !== '' && isset($cifs[$check['share']]) ? 'cifs:' . $check['share'] : 'agent:' . $id;
            $raw = $key === 'agent:' . $id
                ? array_values($counters[$id] ?? ['read_bytes' => 0, 'write_bytes' => 0, 'read_ops' => 0, 'write_ops' => 0])
                : $cifs[$check['share']];
            $rates = $metrics->rates($key, $raw);

            $old = $previous[$id] ?? (string) ($row['state'] ?? 'unknown');
            if ($old !== $check['state'] && $check['state'] !== 'disabled') {
                $this->event(
                    $check['state'] === 'online' ? 'info' : 'error',
                    'target',
                    'Speicherziel „' . $row['label'] . '“: ' . ($check['state'] === 'online' ? 'wieder erreichbar.' : $check['message']),
                    $id
                );
            }
            $values = [
                'state' => $check['state'],
                'message' => $check['message'],
                'total_bytes' => $check['total_bytes'],
                'free_bytes' => $check['free_bytes'],
                'updated_at' => StorageRepository::NOW,
            ] + $rates;
            if ($old !== $check['state'] || !isset($previous[$id])) {
                if ($old !== $check['state'] || ($row['state_since'] ?? null) === null) {
                    $values['state_since'] = StorageRepository::NOW;
                }
            }
            $previous[$id] = $check['state'];
            $repository->updateTargetStatus($id, $values);

            $map[] = [
                'id' => $id,
                'label' => (string) $row['label'],
                'root' => $check['root'],
                'online' => $check['state'] === 'online',
                'primary' => (int) $row['is_primary'] === 1,
                'active' => (int) $row['active'] === 1,
            ];
            $evaluated[] = [
                'id' => $id,
                'label' => (string) $row['label'],
                'active' => (int) $row['active'] === 1,
                'state' => $check['state'],
                'in_sync' => (int) ($row['in_sync'] ?? 0) === 1,
                'lag_seconds' => (int) ($row['lag_seconds'] ?? 0),
                'frozen' => $id === $frozen,
            ];
        }
        $this->targetMap()->write($map);

        // Hot-Tier (lokales Storage)
        $dataDir = $this->config['nextcloud_data'];
        $total = (int) (@disk_total_space($dataDir) ?: 0);
        $free = (int) (@disk_free_space($dataDir) ?: 0);
        $block = Metrics::blockCounters($dataDir);
        $local = $block !== null
            ? $metrics->rates('block', $block)
            : $metrics->rates('agent:0', array_values($counters[0] ?? ['read_bytes' => 0, 'write_bytes' => 0, 'read_ops' => 0, 'write_ops' => 0]));

        $status = $repository->status();
        $health = StorageHealth::evaluate(
            $settings->enabled(),
            $evaluated,
            0,
            isset($status['sync_heartbeat_age']) ? (int) $status['sync_heartbeat_age'] : null,
            $status,
            $settings->lagWarnSeconds()
        );
        $repository->updateStatus([
            'heartbeat_at' => StorageRepository::NOW,
            'ha_state' => $health['ha']['state'],
            'ha_message' => $health['ha']['message'],
            'local_total_bytes' => $total,
            'local_free_bytes' => $free,
            'local_limit_bytes' => $settings->localLimitBytes(),
            'local_read_bps' => $local['read_bps'],
            'local_write_bps' => $local['write_bps'],
            'local_read_iops' => $local['read_iops'],
            'local_write_iops' => $local['write_iops'],
            'metrics_source' => $block !== null ? 'blockdev' : 'agent',
            'sparse_supported' => $sparse ? 1 : 0,
        ]);
        if ($settings->enabled() && (($age = $repository->lastSampleAge()) === null || $age >= self::SAMPLE_INTERVAL - 10)) {
            $totals = $this->catalog()->totals();
            $repository->addSample($total - $free, $free, $totals['bytes_total'], $totals['bytes_local'], $health['online']);
        }
    }

    // --- Synchronisation ----------------------------------------------------

    public function sync(): never
    {
        $this->log('info', 'storage-sync Synchronisation gestartet.');
        $watcher = new InotifyWatcher($this->sources());
        $hints = [];
        $more = false;
        $watcherWarned = false;
        while (true) {
            $this->guarded('sync', function () use ($watcher, &$hints, &$more, &$watcherWarned): void {
                Container::reset();
                $settings = $this->settings();
                $repository = $this->repository();
                $catalog = $this->catalog();
                $repository->updateStatus(['sync_heartbeat_at' => StorageRepository::NOW]);

                if (!$settings->enabled() || $settings->instanceId() === '') {
                    $watcher->stop();
                    $repository->updateStatus(['sync_state' => 'disabled', 'sync_message' => 'Speicher-Tiering ist nicht aktiviert.']);
                    sleep(10);

                    return;
                }
                if ($catalog->meta('paused') === '1') {
                    $repository->updateStatus(['sync_state' => 'paused', 'sync_message' => 'Synchronisation angehalten (Wiederherstellung läuft).']);
                    sleep(5);

                    return;
                }
                if (!$watcher->running()) {
                    if (!$watcher->start() && !$watcherWarned) {
                        $this->log('warning', 'Änderungsüberwachung nicht verfügbar (' . $watcher->error() . ') – vollständiger Abgleich alle 5 Minuten.');
                        $watcherWarned = true;
                    }
                }

                $more = $this->syncPass($settings, $watcher->running(), $hints);
                $hints = [];
            });
            // Auf Aenderungen warten (bei Rueckstand nur kurz).
            foreach ($watcher->collect($more ? 0.2 : 5.0) as $source => $paths) {
                $hints[$source] = array_merge($hints[$source] ?? [], $paths);
            }
        }
    }

    /**
     * @param array<string,list<string>> $hints
     *
     * @return bool true = es ist noch Arbeit offen
     */
    private function syncPass(StorageSettings $settings, bool $watching, array $hints): bool
    {
        $repository = $this->repository();
        $catalog = $this->catalog();
        $detector = $this->detector();
        $engine = $this->engine($detector);
        $now = time();
        // Wer hat welche Datei geschrieben (Zuordnung von Vorfaellen)?
        $detector->ingestWrites($this->store()->takeWriteLog());

        $fullScan = false;
        while (($request = $repository->claimRequest(['sync_now', 'full_scan', 'confirm_deletes'])) !== null) {
            if ($request['action'] === 'confirm_deletes') {
                $engine->confirmDeletes();
                $this->event('warning', 'sync', 'Löschungen wurden von ' . $request['requested_by'] . ' bestätigt.');
            }
            $fullScan = $fullScan || $request['action'] !== 'sync_now';
            $repository->finishRequest((int) $request['id'], 'Ausgeführt.');
        }

        // Datenbanksicherung (wird wie eine Datei synchronisiert)
        $dumpInterval = $settings->dbDumpSeconds();
        if ($now - (int) $catalog->meta('last_db_dump', '0') >= $dumpInterval) {
            $this->dumpDatabase();
            $catalog->setMeta('last_db_dump', (string) $now);
            $fullScan = $fullScan || !$watching;
        }

        $lastFull = (int) $catalog->meta('last_full_scan', '0');
        $fullInterval = $watching ? $settings->fullScanSeconds() : min(300, $settings->fullScanSeconds());
        $statusUpdate = [];
        if ($fullScan || $now - $lastFull >= $fullInterval) {
            $engine->scan(null);
            $catalog->setMeta('last_full_scan', (string) time());
            $statusUpdate['last_full_scan_at'] = StorageRepository::NOW;
            $statusUpdate['last_scan_at'] = StorageRepository::NOW;
        } elseif ($hints !== []) {
            $engine->scan($hints);
            $statusUpdate['last_scan_at'] = StorageRepository::NOW;
        }

        foreach ($this->store()->takeAccessLog() as $path => $time) {
            $file = $catalog->find(PathRules::SOURCE_NEXTCLOUD_DATA, $path);
            if ($file !== null) {
                $catalog->recordAccess((int) $file['id'], $time);
            }
        }
        if ($catalog->meta('access_pruned') !== date('Y-m-d')) {
            $catalog->pruneAccess($now);
            $catalog->setMeta('access_pruned', date('Y-m-d'));
        }

        $rows = $repository->targets();
        $catalog->forgetTargets(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        $frozen = $this->handleIncidents($detector, $settings, $rows);
        $online = $this->targetMap()->online();
        $onlineIds = array_map(static fn (array $t): int => $t['id'], $online);

        $more = false;
        $errors = [];
        foreach ($online as $target) {
            if ($target['id'] === $frozen) {
                // Schutzziel bei einem Sicherheitsvorfall: keine Schreibvorgaenge.
                continue;
            }
            $result = $engine->syncTarget($target, 20);
            $more = $more || $result['more'];
            if ($result['error'] !== null) {
                $errors[] = $target['label'] . ': ' . $result['error'];
            }
            if ($result['copied'] + $result['ops'] > 0) {
                $repository->updateTargetStatus($target['id'], ['last_sync_at' => StorageRepository::NOW]);
                $statusUpdate['last_sync_at'] = StorageRepository::NOW;
            }
            if ($result['failed'] > 0) {
                $errors[] = sprintf('%s: %d Datei(en) nicht übertragen', $target['label'], $result['failed']);
            }
        }

        $tier = $engine->tier($settings, $catalog->meta('sparse_supported', '1') === '1');

        // Rueckstand je Ziel
        $maxPending = 0;
        $maxPendingBytes = 0;
        $maxLag = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ((int) $row['active'] !== 1) {
                continue;
            }
            $pending = $catalog->pendingStats($id);
            $synced = $catalog->syncedStats($id);
            $lag = $pending['oldest'] === null ? 0 : max(0, time() - $pending['oldest']);
            $repository->updateTargetStatus($id, [
                'in_sync' => $pending['files'] === 0 ? 1 : 0,
                'pending_files' => $pending['files'],
                'pending_bytes' => $pending['bytes'],
                'lag_seconds' => $lag,
                'synced_files' => $synced['files'],
                'synced_bytes' => $synced['bytes'],
                'sync_updated_at' => StorageRepository::NOW,
            ]);
            if ($id === $frozen) {
                continue;
            }
            $maxPending = max($maxPending, $pending['files']);
            $maxPendingBytes = max($maxPendingBytes, $pending['bytes']);
            $maxLag = max($maxLag, $lag);
        }

        $blocked = $engine->blockedMessage();
        if ($blocked !== '') {
            [$state, $message] = ['blocked', $blocked];
        } elseif ($errors !== []) {
            [$state, $message] = ['error', implode('; ', $errors)];
        } elseif ($maxPending > 0) {
            [$state, $message] = ['syncing', $onlineIds === [] ? 'Kein Speicherziel erreichbar.' : ''];
        } else {
            [$state, $message] = ['in_sync', ''];
        }
        if ($frozen !== null && in_array($state, ['in_sync', 'syncing'], true)) {
            $label = self::targetLabel($rows, $frozen);
            $message = trim($message . ' Speicherziel „' . $label . '“ wegen Sicherheitsvorfall schreibgeschützt – wird nicht synchronisiert.');
        }

        $totals = $catalog->totals();
        $repository->updateStatus($statusUpdate + [
            'sync_heartbeat_at' => StorageRepository::NOW,
            'sync_state' => $state,
            'sync_message' => $message,
            'mode' => $tier['mode'],
            'mode_reason' => $tier['mode_reason'],
            'files_total' => $totals['files_total'],
            'bytes_total' => $totals['bytes_total'],
            'files_local' => $totals['files_local'],
            'bytes_local' => $totals['bytes_local'],
            'files_evicted' => $totals['files_evicted'],
            'bytes_evicted' => $totals['bytes_evicted'],
            'pending_files' => $maxPending,
            'pending_bytes' => $maxPendingBytes,
            'lag_seconds' => $maxLag,
            'recalls_total' => (int) $catalog->meta('recalls_total', '0'),
            'recalls_failed' => (int) $catalog->meta('recalls_failed', '0'),
        ]);
        $modeSince = (int) $catalog->meta('mode_since', '0');
        if ($modeSince > 0 && $catalog->meta('mode_since_reported') !== (string) $modeSince) {
            $repository->updateStatus(['mode_since' => date('Y-m-d H:i:s', $modeSince)]);
            $catalog->setMeta('mode_since_reported', (string) $modeSince);
        }
        if ($tier['evicted'] > 0) {
            $this->log('info', sprintf('%d Datei(en) (%s) in den Cold-Tier ausgelagert.', $tier['evicted'], StorageHealth::formatBytes($tier['evicted_bytes'])));
        }

        return $more || $tier['rehydrate'] > 0;
    }

    /**
     * Wertet die Erkennung aus: legt Vorfaelle an bzw. aktualisiert sie,
     * waehlt das Schutzziel und veroeffentlicht die Einschraenkungen.
     *
     * @param list<array<string,mixed>> $rows Speicherziele
     *
     * @return int|null Schreibgeschuetztes Schutzziel (nicht synchronisieren)
     */
    private function handleIncidents(ThreatDetector $detector, StorageSettings $settings, array $rows): ?int
    {
        try {
            $repository = $this->incidents();
            $now = time();
            $open = $repository->open();
            $byUser = [];
            foreach ($open as $incident) {
                $byUser[(string) $incident['uid']] ??= $incident;
            }
            $floors = [];
            foreach ($repository->resolvedSince(date('Y-m-d H:i:s', $now - ThreatDetector::RETENTION_SECONDS)) as $uid => $resolvedAt) {
                $floors[$uid] = (int) strtotime($resolvedAt) + 1;
            }

            $changed = false;
            foreach ($detector->evaluate($floors) as $user => $finding) {
                $incident = $byUser[$user] ?? null;
                if ($incident === null) {
                    $id = $repository->create(self::incidentValues($finding, $finding['rules']) + [
                        'uid' => $user,
                        'source' => PathRules::SOURCE_NEXTCLOUD_DATA,
                        'user_restricted' => 1,
                    ]);
                    $this->event('error', 'incident', sprintf(
                        'Sicherheitsvorfall Nr. %d: %s Der Benutzer „%s“ darf bis zur Erledigung nur noch lesen.',
                        $id,
                        self::summary($finding['rules'], $finding),
                        $user
                    ));
                    $changed = true;
                    continue;
                }
                // Laufenden Vorfall fortschreiben (Umfang seit Beginn).
                $since = $incident['first_seen'] !== null ? (int) strtotime((string) $incident['first_seen']) : $now - ThreatDetector::RETENTION_SECONDS;
                $stats = $detector->stats($user, max($since, (int) ($floors[$user] ?? 0)));
                $stats['changed'] = max($stats['changed'], (int) $incident['files_changed']);
                $stats['suspicious'] = max($stats['suspicious'], (int) $incident['files_suspicious']);
                $stats['extension'] = max($stats['extension'], (int) $incident['files_extension']);
                $stats['bytes'] = max($stats['bytes'], (int) $incident['bytes']);
                $stats['first'] = $stats['first'] === null ? $since : min($stats['first'], $since);
                $rules = array_values(array_unique(array_merge(
                    array_filter(explode(',', (string) $incident['rules'])),
                    $finding['rules']
                )));
                $repository->update((int) $incident['id'], self::incidentValues($stats, $rules));
            }

            if ($changed) {
                $open = $repository->open();
            }
            $frozen = self::frozenTargetId($open);
            if ($open !== [] && !$detector->settings()->freezeTarget()) {
                // Nur Benutzer einschraenken: kein Cold-Ziel aus dem Sync nehmen (bzw. ein geschuetztes wieder freigeben).
                if ($frozen !== null) {
                    $repository->releaseFrozenTarget();
                }
                if ($this->catalog()->meta('incident_frozen', '') !== '') {
                    $this->catalog()->setMeta('incident_frozen', '');
                    $this->event('info', 'incident', 'Schutz des Speicherziels bei Sicherheitsvorfällen ist abgeschaltet – '
                        . ($frozen !== null ? '„' . self::targetLabel($rows, $frozen) . '“ wird wieder synchronisiert. ' : '')
                        . 'Eingeschränkt bleiben nur die betroffenen Benutzer.', $frozen);
                }
                $frozen = null;
            } elseif ($open === []) {
                if ($this->catalog()->meta('incident_frozen', '') !== '') {
                    $this->catalog()->setMeta('incident_frozen', '');
                    $this->event('info', 'incident', 'Alle Sicherheitsvorfälle erledigt – die Synchronisation aller Speicherziele wird fortgesetzt.');
                }
            } else {
                $stillActive = array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $frozen && (int) $r['active'] === 1) !== [];
                if ($frozen === null || !$stillActive) {
                    $target = self::chooseProtectTarget($rows, $detector->settings()->protectTargetId());
                    if ($target !== null) {
                        $frozen = (int) $target['id'];
                        $repository->assignFrozenTarget($frozen, (string) $target['label']);
                    } else {
                        $frozen = null;
                    }
                } else {
                    $repository->assignFrozenTarget($frozen, self::targetLabel($rows, $frozen));
                }
                if ($frozen !== null && $this->catalog()->meta('incident_frozen', '') !== (string) $frozen) {
                    $this->catalog()->setMeta('incident_frozen', (string) $frozen);
                    $this->event('warning', 'incident', 'Speicherziel „' . self::targetLabel($rows, $frozen)
                        . '“ ist wegen eines Sicherheitsvorfalls schreibgeschützt und wird bis zur Erledigung nicht synchronisiert (unveränderter Datenbestand).', $frozen);
                }
            }
            if ($changed) {
                $this->publishTieringConfig($settings, $open);
            }

            return $frozen;
        } catch (PDOException $exception) {
            $this->log('error', 'Sicherheitsvorfälle: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * Schutzziel waehlen: eingestelltes Ziel, sonst ein erreichbares,
     * synchrones Ziel (bevorzugt nicht das primaere), sonst das mit dem
     * geringsten Rueckstand.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,mixed>|null
     */
    public static function chooseProtectTarget(array $rows, int $preferred = 0): ?array
    {
        $active = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['active'] === 1));
        if ($active === []) {
            return null;
        }
        foreach ($active as $row) {
            if ($preferred > 0 && (int) $row['id'] === $preferred) {
                return $row;
            }
        }
        $online = array_values(array_filter($active, static fn (array $r): bool => ($r['state'] ?? '') === 'online'));
        $synced = array_values(array_filter($online, static fn (array $r): bool => (int) ($r['in_sync'] ?? 0) === 1));
        foreach ($synced as $row) {
            if ((int) ($row['is_primary'] ?? 0) !== 1) {
                return $row;
            }
        }
        if ($synced !== []) {
            return $synced[0];
        }
        if ($online !== []) {
            usort($online, static fn (array $a, array $b): int => (int) ($a['lag_seconds'] ?? 0) <=> (int) ($b['lag_seconds'] ?? 0));

            return $online[0];
        }

        return $active[0];
    }

    /**
     * @param list<string> $rules
     *
     * @return array<string,mixed>
     */
    public static function incidentValues(array $stats, array $rules): array
    {
        $details = [
            'samples' => $stats['samples'] ?? [],
            'patterns' => $stats['patterns'] ?? [],
            'reasons' => $stats['reasons'] ?? [],
            'owners' => $stats['owners'] ?? [],
            'clients' => $stats['clients'] ?? [],
        ];

        return [
            'attribution' => (string) ($stats['attribution'] ?? 'owner'),
            'rules' => implode(',', $rules),
            'summary' => mb_substr(self::summary($rules, $stats), 0, 500),
            'files_changed' => (int) ($stats['changed'] ?? 0),
            'files_suspicious' => (int) ($stats['suspicious'] ?? 0),
            'files_extension' => (int) ($stats['extension'] ?? 0),
            'bytes' => (int) ($stats['bytes'] ?? 0),
            'first_seen' => isset($stats['first']) ? date('Y-m-d H:i:s', (int) $stats['first']) : null,
            'last_seen' => isset($stats['last']) ? date('Y-m-d H:i:s', (int) $stats['last']) : null,
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        ];
    }

    /**
     * @param list<string> $rules
     */
    public static function summary(array $rules, array $stats): string
    {
        $parts = [];
        if (in_array('extension', $rules, true)) {
            $parts[] = sprintf('%d Datei(en) mit Ransomware-Endung (%s)', (int) ($stats['extension'] ?? 0), implode(', ', array_slice(array_keys($stats['patterns'] ?? []), 0, 3)));
        }
        if (in_array('content', $rules, true)) {
            $parts[] = sprintf('%d Datei(en) mit verschlüsselt wirkendem Inhalt', (int) ($stats['suspicious'] ?? 0));
        }
        if (in_array('overwrite', $rules, true)) {
            $parts[] = sprintf('%d Datei(en) in kurzer Zeit überschrieben', (int) ($stats['changed'] ?? 0));
        }

        return ($parts === [] ? 'Auffälliges Überschreiben' : implode('; ', $parts)) . '.';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function targetLabel(array $rows, int $id, string $fallback = '#'): string
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return (string) $row['label'];
            }
        }

        return $fallback === '#' ? '#' . $id : $fallback;
    }

    private function dumpDatabase(): void
    {
        $dir = $this->config['state_dir'] . '/dumps';
        FileCopier::ensureDir($dir);
        $password = trim((string) @file_get_contents($this->config['db_secret']));
        if ($password === '') {
            return;
        }
        $temp = $dir . '/.nextcloud.dump' . PathRules::TEMP_SUFFIX;
        $run = Shell::run(
            ['pg_dump', '-h', $this->config['db_host'], '-U', 'nextcloud', '-d', 'nextcloud', '-Fc', '-f', $temp],
            3600,
            ['PGPASSWORD' => $password, 'PATH' => '/usr/local/bin:/usr/bin:/bin']
        );
        if ($run['code'] !== 0) {
            @unlink($temp);
            $this->event('error', 'sync', 'Sicherung der Nextcloud-Datenbank fehlgeschlagen: ' . mb_substr($run['err'], 0, 300));

            return;
        }
        rename($temp, $dir . '/nextcloud.dump');
        $this->repository()->updateStatus(['last_db_dump_at' => StorageRepository::NOW]);
    }

    // --- Rueckholung --------------------------------------------------------

    public function recall(): never
    {
        $this->log('info', 'storage-sync Rückholung gestartet.');
        /** @var array<string,resource> $running */
        $running = [];
        $lastCleanup = 0;
        $lastReport = 0;
        while (true) {
            $this->guarded('recall', function () use (&$running, &$lastCleanup, &$lastReport): void {
                $store = $this->store();
                $store->heartbeat();
                foreach ($running as $id => $process) {
                    $status = proc_get_status($process);
                    if ($status['running']) {
                        continue;
                    }
                    proc_close($process);
                    unset($running[$id]);
                    $catalog = $this->catalog();
                    $catalog->setMeta('recalls_total', (string) ((int) $catalog->meta('recalls_total', '0') + 1));
                    if ($status['exitcode'] !== 0) {
                        $catalog->setMeta('recalls_failed', (string) ((int) $catalog->meta('recalls_failed', '0') + 1));
                    }
                    $catalog->setMeta('last_recall', (string) time());
                }
                foreach ($store->queuedIds() as $id) {
                    if (count($running) >= self::MAX_RECALLS) {
                        break;
                    }
                    if (isset($running[$id])) {
                        continue;
                    }
                    $process = proc_open([PHP_BINARY, $this->config['script'], 'recall-one', $id], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
                    if (is_resource($process)) {
                        $running[$id] = $process;
                    }
                }
                if (time() - $lastCleanup >= 60) {
                    $store->cleanupStatus();
                    $lastCleanup = time();
                }
                if (time() - $lastReport >= 5) {
                    Container::reset();
                    $lastRecall = (int) $this->catalog()->meta('last_recall', '0');
                    $values = ['recalls_active' => count($running)];
                    if ($lastRecall > 0) {
                        $values['last_recall_at'] = date('Y-m-d H:i:s', $lastRecall);
                    }
                    $this->repository()->updateStatus($values);
                    $lastReport = time();
                }
            });
            usleep(250000);
        }
    }

    /**
     * Holt eine Datei zurueck (eigener Prozess je Auftrag).
     *
     * @return int Exit-Code (0 = erfolgreich)
     */
    public function recallOne(string $id): int
    {
        $store = $this->store();
        $request = $store->request($id);
        if ($request === null) {
            $store->dequeue($id);

            return 0;
        }
        $rel = ltrim((string) $request['path'], '/');
        $base = [
            'path' => $rel,
            'uid' => (string) ($request['uid'] ?? ''),
            'request' => (int) ($request['requested_at'] ?? 0),
            'started' => time(),
        ];
        if (PathRules::isExcluded(PathRules::SOURCE_NEXTCLOUD_DATA, $rel) || TieringStore::recallId($rel) !== $id) {
            $store->writeStatus($id, $base + ['state' => 'failed', 'message' => 'Ungültiger Pfad.']);
            $store->dequeue($id);

            return 2;
        }

        $total = (int) ($store->readMarker($rel)['size'] ?? 0);
        $store->writeStatus($id, $base + ['state' => 'running', 'bytes' => 0, 'total' => $total]);
        $last = 0.0;
        try {
            $this->recaller()->recall($rel, function (int $bytes, int $size) use ($store, $id, $base, &$last): void {
                if (microtime(true) - $last < self::RECALL_PROGRESS_INTERVAL) {
                    return;
                }
                $last = microtime(true);
                $store->writeStatus($id, $base + ['state' => 'running', 'bytes' => $bytes, 'total' => $size]);
            });
            $store->writeStatus($id, $base + ['state' => 'done', 'bytes' => $total, 'total' => $total, 'finished' => time()]);
            $code = 0;
        } catch (Throwable $exception) {
            $store->writeStatus($id, $base + ['state' => 'failed', 'message' => $exception->getMessage(), 'finished' => time()]);
            $this->event('warning', 'recall', 'Rückholung von „' . $rel . '“ fehlgeschlagen: ' . $exception->getMessage());
            $code = 2;
        }
        $store->dequeue($id);

        return $code;
    }

    // --- Wiederherstellung --------------------------------------------------

    /**
     * Stellt die Daten von einem Speicherziel des Cold-Tiers wieder her (z. B.
     * nach Verlust der VM). Selten genutzte Dateien werden ohne $full nur als
     * Platzhalter angelegt und bei Bedarf zurueckgeholt.
     */
    /**
     * @param bool $keepPaused Synchronisation bleibt danach angehalten (bis "resume"), damit
     *                         z. B. die Nextcloud-Datenbank zuerst eingespielt werden kann und
     *                         kein veralteter Stand auf die Speicherziele gelangt.
     */
    public function restore(int $targetId, bool $full, bool $keepPaused = false): int
    {
        $target = null;
        foreach ($this->targetMap()->all() as $candidate) {
            if ($candidate['id'] === $targetId && $candidate['online']) {
                $target = $candidate;
            }
        }
        $target ??= $this->adoptTarget($targetId);
        if ($target === null) {
            $this->log('error', 'Speicherziel ' . $targetId . ' ist nicht eingebunden oder nicht erreichbar.');

            return 1;
        }
        $restore = new Restore($this->catalog(), $this->store(), $this->copier(), $this->sources(), $this->settings(), fn (string $m) => $this->log('info', $m));
        $catalog = $this->catalog();
        $catalog->setMeta('paused', '1');
        $this->event('warning', 'restore', 'Wiederherstellung aus „' . $target['label'] . '“ gestartet' . ($full ? ' (vollständig).' : '.'), $targetId);
        try {
            $instance = $restore->targetInstance($target['root']);
            if ($instance !== null && $instance !== $this->settings()->instanceId()) {
                Container::settings()->update(['storage_instance_id' => $instance]);
                $this->log('info', 'Instanz-ID des Speicherziels übernommen: ' . $instance);
            }
            $stats = $restore->run($target, $full);
        } finally {
            if (!$keepPaused) {
                $catalog->setMeta('paused', '');
            }
        }
        $message = sprintf(
            'Wiederherstellung abgeschlossen: %d Datei(en) kopiert, %d als Platzhalter (Cold-Tier), %d unverändert.',
            $stats['copied'],
            $stats['stubbed'],
            $stats['skipped']
        );
        $this->event('info', 'restore', $message, $targetId);

        return 0;
    }

    /**
     * Bindet ein Ziel fuer die Wiederherstellung direkt ein. Auf einer neu
     * aufgesetzten Installation gehoert die Freigabe zu einer anderen
     * Instanz-ID ("invalid"); deren Kennung wird dann uebernommen.
     *
     * @return array{id:int,label:string,root:string,online:bool,primary:bool,active:bool}|null
     */
    private function adoptTarget(int $targetId): ?array
    {
        $row = null;
        foreach ($this->repository()->targets() as $candidate) {
            if ((int) $candidate['id'] === $targetId) {
                $row = $candidate;
            }
        }
        if ($row === null) {
            return null;
        }
        $row['active'] = 1;
        $instance = $this->settings()->instanceId();
        if ($instance === '') {
            $instance = bin2hex(random_bytes(16));
        }
        $mounter = new Mounter($this->config['mount_base'], $this->config['credential_dir'], Container::secretBox(), $instance, s3TempDir: $this->config['state_dir'] . '/s3-tmp');
        $check = $mounter->check($row);
        if ($check['state'] === 'invalid') {
            $data = json_decode((string) @file_get_contents($check['root'] . '/' . PathRules::TARGET_MARKER), true);
            $foreign = is_array($data) ? (string) ($data['instance'] ?? '') : '';
            if ($foreign !== '') {
                $mounter = new Mounter($this->config['mount_base'], $this->config['credential_dir'], Container::secretBox(), $foreign, s3TempDir: $this->config['state_dir'] . '/s3-tmp');
                $check = $mounter->check($row);
            }
        }
        if ($check['state'] !== 'online') {
            $this->log('error', 'Speicherziel ' . $targetId . ': ' . ($check['message'] !== '' ? $check['message'] : 'nicht erreichbar.'));

            return null;
        }

        return [
            'id' => $targetId,
            'label' => (string) $row['label'],
            'root' => $check['root'],
            'online' => true,
            'primary' => (int) ($row['is_primary'] ?? 0) === 1,
            'active' => true,
        ];
    }

    public function resume(): int
    {
        $this->catalog()->setMeta('paused', '');
        $this->event('info', 'restore', 'Synchronisation nach der Wiederherstellung fortgesetzt.', null);

        return 0;
    }
}
