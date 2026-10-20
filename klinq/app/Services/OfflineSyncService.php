<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Security\CurrentUser;

/**
 * Offline-Synchronisation der mobilen Erfassung.
 *
 * bootstrap(): kompakte Stammdaten (Assets, Mitarbeiter, Standorte, Kostenstellen), die das Gerät lokal
 * zwischenspeichert, um ohne Netz Assets anzuzeigen und Entnahmen/Retouren zu erfassen.
 *
 * process(): verarbeitet eine Liste offline erfasster Transaktionen. Jede Transaktion trägt eine clientseitige
 * ID (client_transaction_id, UNIQUE in movements) – erneutes Senden erzeugt keine Duplikate ("duplicate").
 * Jede Transaktion wird einzeln verarbeitet, damit ein Konflikt die übrigen nicht blockiert. Versionskonflikte
 * (asset_version ≠ aktuelle Version) werden als "conflict" gemeldet und nie stillschweigend überschrieben.
 */
final class OfflineSyncService
{
    public const MAX_BATCH = 100;
    public const MAX_PHOTOS = 8;
    public const MAX_PHOTO_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly MovementService $movements,
        private readonly MovementRepository $movementRepo,
        private readonly AssetRepository $assets,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly DocumentService $documents,
        private readonly CurrentUser $currentUser,
        private readonly Config $config
    ) {}

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        $this->currentUser->require('assets.view');

        return [
            'generated_at' => gmdate('c'),
            'user' => ['id' => $this->currentUser->id(), 'display_name' => $this->currentUser->displayName()],
            'permissions' => [
                'checkout' => $this->currentUser->can('movements.checkout'),
                'return' => $this->currentUser->can('movements.return'),
                'retire' => $this->currentUser->can('assets.retire'),
            ],
            'assets' => array_map(static fn (array $a): array => [
                'id' => (int) $a['id'],
                'inventory_number' => $a['inventory_number'],
                'serial_number' => $a['serial_number'],
                'name' => $a['name'],
                'version' => (int) $a['version'],
                'type' => $a['type'],
                'type_code' => $a['type_code'],
                'manufacturer' => $a['manufacturer'],
                'status_code' => $a['status_code'],
                'status_name' => $a['status_name'],
                'status_color' => $a['status_color'],
                'employee_id' => $a['employee_id'] !== null ? (int) $a['employee_id'] : null,
                'employee_name' => $a['employee_name'],
                'location_id' => $a['location_id'] !== null ? (int) $a['location_id'] : null,
                'location_path' => $a['location_path'],
                'cost_center_id' => $a['cost_center_id'] !== null ? (int) $a['cost_center_id'] : null,
            ], $this->assets->forOffline()),
            'employees' => array_map(static fn (array $e): array => [
                'id' => (int) $e['id'],
                'name' => $e['display_name'],
                'meta' => implode(' · ', array_filter([$e['personnel_number'] ?? null, $e['department'] ?? null])),
            ], $this->employees->activeForSelect()),
            'locations' => array_map(static fn (array $l): array => [
                'id' => (int) $l['id'],
                'name' => $l['name'],
                'meta' => $l['full_path'],
            ], $this->locations->all(true)),
            'cost_centers' => array_map(static fn (array $c): array => [
                'id' => (int) $c['id'],
                'number' => $c['number'],
                'description' => $c['description'],
            ], $this->costCenters->activeForSelect()),
            'conditions' => MovementService::CONDITIONS,
            'return_targets' => MovementService::RETURN_TARGETS,
        ];
    }

    /**
     * @param array<int,mixed> $transactions Liste von {client_transaction_id, type: checkout|return, payload: {...}, photos?: [{name,type,data}]}
     * @return array<int,array<string,mixed>> Ergebnis je Transaktion (gleiche Reihenfolge)
     */
    public function process(array $transactions): array
    {
        if (count($transactions) > self::MAX_BATCH) {
            throw ValidationException::single('transactions', 'Höchstens ' . self::MAX_BATCH . ' Vorgänge je Synchronisation.');
        }
        $results = [];
        foreach ($transactions as $tx) {
            $results[] = $this->processOne(is_array($tx) ? $tx : []);
        }

        return $results;
    }

    /** @param array<string,mixed> $tx @return array<string,mixed> */
    private function processOne(array $tx): array
    {
        $txId = trim((string) ($tx['client_transaction_id'] ?? ''));
        $type = (string) ($tx['type'] ?? '');
        $base = ['client_transaction_id' => $txId, 'type' => $type];
        if (!preg_match('/^[A-Za-z0-9-]{8,36}$/', $txId)) {
            return $base + ['status' => 'error', 'message' => 'Ungültige Transaktions-ID.', 'errors' => ['client_transaction_id' => 'Ungültige Transaktions-ID.']];
        }
        if (!in_array($type, ['checkout', 'return'], true)) {
            return $base + ['status' => 'error', 'message' => 'Unbekannter Vorgangstyp.', 'errors' => ['type' => 'Unbekannter Vorgangstyp.']];
        }

        $existing = $this->movementRepo->findByClientTransaction($txId);
        if ($existing !== null) {
            return $base + ['status' => 'duplicate', 'movement_id' => (int) $existing['id'], 'message' => 'Bereits übertragen.'];
        }

        $payload = is_array($tx['payload'] ?? null) ? $tx['payload'] : [];
        $payload['client_transaction_id'] = $txId;
        unset($payload['_csrf']);
        // Ein explizit angeforderter Override (Konflikt vom Nutzer bestätigt) verzichtet auf die Versionsprüfung
        if (!empty($tx['force'])) {
            unset($payload['asset_version']);
        }

        try {
            $movement = $type === 'checkout'
                ? $this->movements->checkout($payload, 'offline_sync')
                : $this->movements->returnAsset($payload, 'offline_sync');
        } catch (ConflictException $e) {
            return $base + ['status' => 'conflict', 'message' => $e->getMessage(), 'conflict' => $e->details()];
        } catch (ValidationException $e) {
            return $base + ['status' => 'error', 'message' => $e->getMessage(), 'errors' => $e->errors()];
        } catch (ForbiddenException $e) {
            return $base + ['status' => 'forbidden', 'message' => $e->getMessage()];
        }

        $warnings = [];
        if ($type === 'return') {
            $warnings = $this->storePhotos((int) $movement['id'], $tx['photos'] ?? null);
        }

        $result = $base + ['status' => 'ok', 'movement_id' => (int) $movement['id'], 'movement_status' => $movement['status'], 'message' => $type === 'checkout' ? 'Entnahme gespeichert.' : 'Rückgabe gespeichert.'];
        if ($warnings !== []) {
            $result['warnings'] = $warnings;
        }

        return $result;
    }

    /**
     * Base64-kodierte Fotos aus der Warteschlange als Dokumente ablegen.
     * @return list<string> Warnungen (abgelehnte Fotos), die Bewegung selbst bleibt gespeichert
     */
    private function storePhotos(int $movementId, mixed $photos): array
    {
        if (!is_array($photos) || $photos === []) {
            return [];
        }
        $warnings = [];
        $tmpDir = rtrim((string) $this->config->get('uploads.path'), '/') . '/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            return ['Fotos konnten nicht gespeichert werden (Temp-Verzeichnis).'];
        }
        foreach (array_slice(array_values($photos), 0, self::MAX_PHOTOS) as $i => $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $name = basename(str_replace('\\', '/', (string) ($photo['name'] ?? ('foto-' . ($i + 1) . '.jpg'))));
            $data = (string) ($photo['data'] ?? '');
            if (str_contains($data, ',')) {
                $data = substr($data, strpos($data, ',') + 1);
            }
            $binary = base64_decode($data, true);
            if ($binary === false || $binary === '' || strlen($binary) > self::MAX_PHOTO_BYTES) {
                $warnings[] = 'Foto „' . $name . '“ wurde abgelehnt (ungültig oder zu groß).';
                continue;
            }
            $tmp = $tmpDir . '/' . bin2hex(random_bytes(8));
            file_put_contents($tmp, $binary);
            try {
                $this->documents->store('movement', $movementId, 'photo', [
                    'name' => $name, 'type' => (string) ($photo['type'] ?? ''), 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($binary),
                ], null, $this->documents->imageExtensions(), 'photos');
            } catch (ValidationException $e) {
                $warnings[] = 'Foto „' . $name . '“: ' . implode(' ', $e->errors());
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $warnings;
    }
}
