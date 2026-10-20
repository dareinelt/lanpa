<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Support\Validator;

/** Lizenzverwaltung: Stammdaten, Zuordnung zu Assets, Ablaufstatus. */
final class LicenseService
{
    public const EXPIRY_LABELS = [
        'perpetual' => 'Unbefristet',
        'valid' => 'Gültig',
        'expiring' => 'Läuft ab',
        'expired' => 'Abgelaufen',
    ];

    public const EXPIRY_COLORS = [
        'perpetual' => 'neutral',
        'valid' => 'success',
        'expiring' => 'warning',
        'expired' => 'danger',
    ];

    /** Übliche Lizenzmodelle als Vorschlagsliste (Freitext bleibt möglich). */
    public const LICENSE_TYPES = ['Einzelplatz', 'Volumenlizenz', 'Abonnement', 'OEM', 'Gerätelizenz', 'Benutzerlizenz', 'Open Source', 'Sonstige'];

    public function __construct(
        private readonly LicenseRepository $licenses,
        private readonly ManufacturerRepository $manufacturers,
        private readonly SupplierRepository $suppliers,
        private readonly CostCenterRepository $costCenters,
        private readonly PurchaseOrderRepository $orders,
        private readonly AssetRepository $assets,
        private readonly AssetService $assetService,
        private readonly MovementService $movements,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $this->currentUser->require('licenses.manage');
        $data = $this->validate($input, null);
        $data['is_active'] = 1;
        $id = $this->licenses->create($data);
        $this->audit->log('create', 'license', $id, $this->label($data), null, $this->auditData($data));

        return $id;
    }

    /** @param array<string,mixed> $existing @param array<string,mixed> $input */
    public function update(int $id, array $existing, array $input): void
    {
        $this->currentUser->require('licenses.manage');
        $data = $this->validate($input, $existing);
        $this->licenses->update($id, $data);
        $this->audit->log('update', 'license', $id, $this->label($data), $this->auditData($existing), $this->auditData($data));
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->currentUser->require('licenses.manage');
        if ((int) $existing['is_active'] === ($active ? 1 : 0)) {
            return;
        }
        $this->licenses->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'license', $id, $this->label($existing), ['is_active' => (int) $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    // ------------------------------------------------------------------ Zuordnung

    /**
     * Ordnet die Lizenz einem Asset zu (belegt eine Einheit).
     *
     * @param array<string,mixed> $license Detail-Datensatz (find)
     * @param array<string,mixed> $input asset_id oder asset_code (Inventar-/Seriennummer), note
     */
    public function assign(array $license, array $input): int
    {
        $this->currentUser->require('licenses.manage');
        if ((int) $license['is_active'] !== 1) {
            throw new ConflictException('Die Lizenz ist deaktiviert und kann nicht zugewiesen werden.');
        }
        if ($license['expiry_status'] === 'expired') {
            throw new ConflictException('Die Lizenz ist abgelaufen und kann nicht mehr zugewiesen werden.');
        }
        $asset = $this->resolveAsset($input);
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            throw ValidationException::single('note', 'Die Bemerkung darf höchstens 255 Zeichen lang sein.');
        }
        if ((int) $asset['status_final'] === 1) {
            throw ValidationException::single('asset_id', 'Das Asset ' . $asset['inventory_number'] . ' ist bereits ausgeschieden.');
        }
        if ($this->licenses->activeAssignment((int) $license['id'], (int) $asset['id']) !== null) {
            throw ValidationException::single('asset_id', 'Diese Lizenz ist dem Asset ' . $asset['inventory_number'] . ' bereits zugeordnet.');
        }
        if ((int) $license['available_count'] <= 0) {
            throw new ConflictException('Alle ' . $license['quantity'] . ' Einheiten dieser Lizenz sind bereits vergeben.');
        }

        return $this->licenses->transaction(function () use ($license, $asset, $note): int {
            $id = $this->licenses->createAssignment([
                'license_id' => (int) $license['id'],
                'asset_id' => (int) $asset['id'],
                'assigned_at' => gmdate('Y-m-d H:i:s'),
                'assigned_by' => $this->currentUser->displayName(),
                'note' => $note !== '' ? $note : null,
            ]);
            $this->assetService->addEvent((int) $asset['id'], 'license_assigned', $this->label($license), $note !== '' ? $note : null, (int) $license['id']);
            $this->audit->log('assign', 'license', (int) $license['id'], $this->label($license), null, ['asset_id' => (int) $asset['id'], 'inventory_number' => $asset['inventory_number'], 'assignment_id' => $id]);

            return $id;
        });
    }

    /** Gibt eine Zuordnung frei (Einheit wird wieder verfügbar). @param array<string,mixed> $license */
    public function release(array $license, int $assignmentId): void
    {
        $this->currentUser->require('licenses.manage');
        $assignment = $this->licenses->findAssignment($assignmentId);
        if ($assignment === null || (int) $assignment['license_id'] !== (int) $license['id']) {
            throw new ConflictException('Zuordnung nicht gefunden.');
        }
        if ($assignment['released_at'] !== null) {
            throw new ConflictException('Diese Zuordnung wurde bereits aufgehoben.');
        }
        $this->licenses->transaction(function () use ($license, $assignment, $assignmentId): void {
            $this->licenses->releaseAssignment($assignmentId, $this->currentUser->displayName(), gmdate('Y-m-d H:i:s'));
            $this->assetService->addEvent((int) $assignment['asset_id'], 'license_removed', $this->label($license), null, (int) $license['id']);
            $this->audit->log('release', 'license', (int) $license['id'], $this->label($license), ['asset_id' => (int) $assignment['asset_id'], 'inventory_number' => $assignment['inventory_number']], null);
        });
    }

    /** Sprechender Name „Hersteller Produkt“. @param array<string,mixed> $license */
    public function label(array $license): string
    {
        $manufacturer = $license['manufacturer_name'] ?? null;
        if ($manufacturer === null && !empty($license['manufacturer_id'])) {
            $manufacturer = $this->manufacturers->find((int) $license['manufacturer_id'])['name'] ?? null;
        }

        return trim(($manufacturer !== null ? $manufacturer . ' ' : '') . $license['product']);
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function resolveAsset(array $input): array
    {
        $assetId = (int) ($input['asset_id'] ?? 0);
        $asset = $assetId > 0 ? $this->assets->find($assetId) : null;
        if ($asset === null) {
            $code = trim((string) ($input['asset_code'] ?? ''));
            if ($code === '') {
                throw ValidationException::single('asset_id', 'Bitte ein Asset wählen (Inventar- oder Seriennummer).');
            }
            $asset = $this->movements->resolveAsset($code);
            if ($asset === null) {
                throw ValidationException::single('asset_id', 'Kein Asset mit der Nummer „' . $code . '“ gefunden.');
            }
        }

        return $asset;
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing @return array<string,mixed> */
    private function validate(array $input, ?array $existing): array
    {
        $data = (new Validator($input))
            ->id('manufacturer_id', 'Hersteller')
            ->string('product', 'Produkt', true, 200)
            ->string('license_type', 'Lizenztyp', false, 100)
            ->text('license_key', 'Lizenzschlüssel', false, 4000)
            ->string('license_number', 'Lizenznummer', false, 120)
            ->int('quantity', 'Anzahl', true, 1, 100000)
            ->date('purchase_date', 'Kaufdatum')
            ->date('expires_at', 'Ablaufdatum')
            ->id('supplier_id', 'Lieferant')
            ->id('purchase_order_id', 'Bestellung')
            ->decimal('cost', 'Kosten', false, 0.0)
            ->id('cost_center_id', 'Kostenstelle')
            ->text('note', 'Bemerkung', false, 5000)
            ->validated();

        if ($data['manufacturer_id'] !== null && $this->manufacturers->find((int) $data['manufacturer_id']) === null) {
            throw ValidationException::single('manufacturer_id', 'Hersteller nicht gefunden.');
        }
        if ($data['supplier_id'] !== null && $this->suppliers->find((int) $data['supplier_id']) === null) {
            throw ValidationException::single('supplier_id', 'Lieferant nicht gefunden.');
        }
        if ($data['cost_center_id'] !== null && $this->costCenters->find((int) $data['cost_center_id']) === null) {
            throw ValidationException::single('cost_center_id', 'Kostenstelle nicht gefunden.');
        }
        if ($data['purchase_order_id'] !== null && $this->orders->find((int) $data['purchase_order_id']) === null) {
            throw ValidationException::single('purchase_order_id', 'Bestellung nicht gefunden.');
        }
        if ($data['purchase_date'] !== null && $data['expires_at'] !== null && $data['expires_at'] < $data['purchase_date']) {
            throw ValidationException::single('expires_at', 'Das Ablaufdatum darf nicht vor dem Kaufdatum liegen.');
        }
        if ($existing !== null && (int) $data['quantity'] < (int) $existing['used_count']) {
            throw ValidationException::single('quantity', 'Es sind bereits ' . $existing['used_count'] . ' Einheiten zugeordnet; die Anzahl kann nicht darunter liegen.');
        }

        return $data;
    }

    /** Lizenzschlüssel nicht im Audit-Log ablegen. @param array<string,mixed> $data @return array<string,mixed> */
    private function auditData(array $data): array
    {
        $keys = ['manufacturer_id', 'product', 'license_type', 'license_number', 'quantity', 'purchase_date', 'expires_at', 'supplier_id', 'purchase_order_id', 'cost', 'cost_center_id', 'note'];
        $out = array_intersect_key($data, array_flip($keys));
        $out['license_key'] = !empty($data['license_key']) ? '***' : null;

        return $out;
    }
}
