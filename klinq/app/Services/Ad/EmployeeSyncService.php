<?php

declare(strict_types=1);

namespace App\Services\Ad;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\AdSyncRunRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Services\Ldap\LdapClientInterface;
use RuntimeException;

/**
 * Synchronisiert Mitarbeiter aus dem Active Directory.
 *
 * Grundsätze:
 * - Identifikation ausschließlich über die stabile objectGUID.
 * - Es wird nie gelöscht: Im AD fehlende oder deaktivierte Konten werden auf inaktiv gesetzt.
 * - Lokale Zuordnungen (location_id, cost_center_id) bleiben erhalten; sie werden nur
 *   automatisch gesetzt, wenn sie leer sind und der AD-Wert eindeutig zugeordnet werden kann.
 */
final class EmployeeSyncService
{
    /** Felder, die aus dem AD überschrieben werden */
    private const AD_FIELDS = ['username', 'first_name', 'last_name', 'display_name', 'email', 'personnel_number', 'department', 'position', 'phone', 'ad_location', 'ad_cost_center'];

    public function __construct(
        private readonly Config $config,
        private readonly LdapClientInterface $ldap,
        private readonly AdUserMapper $mapper,
        private readonly EmployeeRepository $employees,
        private readonly AdSyncRunRepository $runs,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly Logger $logger
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('ldap.enabled');
    }

    /**
     * Führt eine Synchronisation aus und protokolliert sie in ad_sync_runs.
     *
     * @return array<string,mixed> Ergebniszeile aus ad_sync_runs
     */
    public function run(string $triggeredBy, bool $dryRun = false): array
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException('Die AD-Synchronisation ist deaktiviert (AD_ENABLED=false).');
        }
        $this->runs->failStale();
        if ($this->runs->running() !== null) {
            throw new RuntimeException('Es läuft bereits eine Synchronisation.');
        }

        $runId = $this->runs->start($triggeredBy . ($dryRun ? ' (Testlauf)' : ''));
        $stats = ['total_count' => 0, 'created_count' => 0, 'updated_count' => 0, 'deactivated_count' => 0, 'reactivated_count' => 0, 'error_count' => 0];
        $details = ['created' => [], 'updated' => [], 'adopted' => [], 'deactivated' => [], 'reactivated' => [], 'errors' => [], 'dry_run' => $dryRun];

        try {
            $entries = $this->fetchEntries();
            $stats['total_count'] = count($entries);
            $seen = [];

            $this->employees->transaction(function () use ($entries, &$stats, &$details, &$seen, $dryRun): void {
                foreach ($entries as $entry) {
                    try {
                        $mapped = $this->mapper->map($entry);
                        if ($mapped === null) {
                            $stats['error_count']++;
                            $details['errors'][] = 'Eintrag ohne GUID übersprungen: ' . ($entry['dn'] ?? '?');
                            continue;
                        }
                        $guid = $mapped['ad_object_guid'];
                        if (isset($seen[$guid])) {
                            continue;
                        }
                        $seen[$guid] = true;
                        $this->syncOne($mapped, $stats, $details, $dryRun);
                    } catch (\Throwable $e) {
                        $stats['error_count']++;
                        $details['errors'][] = ($entry['dn'] ?? '?') . ': ' . $e->getMessage();
                        $this->logger->warning('AD-Sync: Eintrag fehlgeschlagen', ['dn' => $entry['dn'] ?? null, 'error' => $e->getMessage()]);
                    }
                }

                // Im AD nicht mehr vorhandene Konten deaktivieren – nie löschen
                foreach ($this->employees->allFromAd() as $existing) {
                    if ((int) $existing['is_active'] === 1 && !isset($seen[$existing['ad_object_guid']])) {
                        $stats['deactivated_count']++;
                        $details['deactivated'][] = $existing['display_name'];
                        if (!$dryRun) {
                            $this->employees->update((int) $existing['id'], ['is_active' => 0, 'deactivated_at' => gmdate('Y-m-d H:i:s'), 'last_synced_at' => gmdate('Y-m-d H:i:s')]);
                        }
                    }
                }

                if ($dryRun) {
                    throw new DryRunRollback();
                }
            });
        } catch (DryRunRollback) {
            // Testlauf: alle Änderungen zurückgerollt
        } catch (\Throwable $e) {
            $this->logger->error('AD-Sync fehlgeschlagen', ['error' => $e->getMessage()]);
            $this->runs->finish($runId, array_merge($stats, ['status' => 'failed', 'message' => $e->getMessage(), 'details' => json_encode($this->trimDetails($details), JSON_UNESCAPED_UNICODE)]));

            return $this->runs->find($runId) ?? [];
        } finally {
            $this->ldap->close();
        }

        $message = sprintf(
            '%d Konten gelesen · %d neu · %d aktualisiert · %d deaktiviert · %d reaktiviert · %d Fehler%s',
            $stats['total_count'], $stats['created_count'], $stats['updated_count'], $stats['deactivated_count'], $stats['reactivated_count'], $stats['error_count'],
            $dryRun ? ' (Testlauf, keine Änderungen gespeichert)' : ''
        );
        $this->runs->finish($runId, array_merge($stats, ['status' => 'success', 'message' => $message, 'details' => json_encode($this->trimDetails($details), JSON_UNESCAPED_UNICODE)]));
        $this->logger->info('AD-Sync abgeschlossen', $stats);

        return $this->runs->find($runId) ?? [];
    }

    /**
     * @param array<string,mixed> $mapped
     * @param array<string,int> $stats
     * @param array<string,mixed> $details
     */
    private function syncOne(array $mapped, array &$stats, array &$details, bool $dryRun): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->employees->findByGuid($mapped['ad_object_guid']);

        // Manuell angelegter Mitarbeiter ohne GUID mit gleichem Benutzernamen: übernehmen statt duplizieren
        if ($existing === null && $mapped['username'] !== null) {
            $candidate = $this->employees->findByUsername($mapped['username']);
            if ($candidate !== null && empty($candidate['ad_object_guid']) && $candidate['source'] === 'manual') {
                $existing = $candidate;
                $details['adopted'][] = $mapped['display_name'];
            }
        }

        if ($existing === null) {
            $data = $mapped + ['source' => 'ad', 'last_synced_at' => $now];
            $data['location_id'] = $this->resolveLocation($mapped['ad_location']);
            $data['cost_center_id'] = $this->resolveCostCenter($mapped['ad_cost_center']);
            if ((int) $mapped['is_active'] === 0) {
                $data['deactivated_at'] = $now;
            }
            $stats['created_count']++;
            $details['created'][] = $mapped['display_name'];
            if (!$dryRun) {
                $this->employees->create($data);
            }

            return;
        }

        $changes = [];
        foreach (self::AD_FIELDS as $field) {
            if (($existing[$field] ?? null) !== $mapped[$field]) {
                $changes[$field] = $mapped[$field];
            }
        }
        if ($existing['source'] !== 'ad') {
            $changes['source'] = 'ad';
            $changes['ad_object_guid'] = $mapped['ad_object_guid'];
        }
        if (empty($existing['location_id']) && ($loc = $this->resolveLocation($mapped['ad_location'])) !== null) {
            $changes['location_id'] = $loc;
        }
        if (empty($existing['cost_center_id']) && ($cc = $this->resolveCostCenter($mapped['ad_cost_center'])) !== null) {
            $changes['cost_center_id'] = $cc;
        }

        $wasActive = (int) $existing['is_active'] === 1;
        $isActive = (int) $mapped['is_active'] === 1;
        if ($wasActive && !$isActive) {
            $changes['is_active'] = 0;
            $changes['deactivated_at'] = $now;
            $stats['deactivated_count']++;
            $details['deactivated'][] = $mapped['display_name'];
        } elseif (!$wasActive && $isActive) {
            $changes['is_active'] = 1;
            $changes['deactivated_at'] = null;
            $stats['reactivated_count']++;
            $details['reactivated'][] = $mapped['display_name'];
        } elseif ($changes !== []) {
            $stats['updated_count']++;
            $details['updated'][] = $mapped['display_name'];
        }

        if (!$dryRun) {
            $this->employees->update((int) $existing['id'], $changes + ['last_synced_at' => $now]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchEntries(): array
    {
        $bindDn = (string) $this->config->get('ldap.bind_dn');
        $bindPassword = (string) $this->config->get('ldap.bind_password');
        if ($bindDn !== '' && !$this->ldap->bind($bindDn, $bindPassword)) {
            throw new RuntimeException('LDAP-Bind mit dem Dienstkonto fehlgeschlagen.');
        }

        return $this->ldap->search(
            (string) $this->config->get('ldap.base_dn'),
            (string) $this->config->get('ldap.user_filter'),
            $this->mapper->ldapAttributes()
        );
    }

    /** Ordnet den AD-Standorttext einem lokalen Standort zu (Name, Kürzel oder vollständiger Pfad). */
    private function resolveLocation(?string $adLocation): ?int
    {
        if ($adLocation === null || $adLocation === '') {
            return null;
        }
        static $cache = [];
        if (!array_key_exists($adLocation, $cache)) {
            $match = $this->locations->findByPath($adLocation) ?? $this->locations->findUniqueByNameOrCode($adLocation);
            $cache[$adLocation] = $match !== null ? (int) $match['id'] : null;
        }

        return $cache[$adLocation];
    }

    private function resolveCostCenter(?string $adCostCenter): ?int
    {
        if ($adCostCenter === null || !preg_match('/\d{5}/', $adCostCenter, $m)) {
            return null;
        }
        $row = $this->costCenters->findByNumber($m[0]);

        return $row !== null ? (int) $row['id'] : null;
    }

    /** @param array<string,mixed> $details @return array<string,mixed> */
    private function trimDetails(array $details): array
    {
        foreach (['created', 'updated', 'adopted', 'deactivated', 'reactivated', 'errors'] as $key) {
            if (count($details[$key]) > 200) {
                $details[$key] = array_merge(array_slice($details[$key], 0, 200), ['… und ' . (count($details[$key]) - 200) . ' weitere']);
            }
        }

        return $details;
    }
}
