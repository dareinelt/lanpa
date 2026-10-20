<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\MovementRepository;
use App\Core\Logger;
use App\Security\CurrentUser;
use App\Support\Validator;

/**
 * Entnahme und Retoure – der Kernworkflow.
 *
 * Eine Bewegung darf unvollständig gespeichert werden (z. B. Standort unbekannt): Sie bleibt dann „offen“ und wird
 * am Desktop nachbearbeitet. Jede Bewegung aktualisiert das Asset sofort (Mitarbeiter, Standort, Kostenstelle, Status)
 * und schreibt alle Änderungen in die Assethistorie. client_transaction_id macht die Verarbeitung idempotent
 * (Offline-Synchronisation), asset_version erkennt Konflikte mit zwischenzeitlichen Änderungen.
 */
final class MovementService
{
    public const CONDITIONS = ['ok' => 'In Ordnung', 'worn' => 'Gebrauchsspuren', 'damaged' => 'Beschädigt', 'defective' => 'Defekt'];
    public const RETURN_TARGETS = ['in_stock' => 'Lagerbestand', 'repair' => 'Reparatur', 'defective' => 'Defekt', 'retired' => 'Ausmustern'];
    public const MISSING_LABELS = ['employee' => 'Mitarbeiter', 'location' => 'Standort', 'cost_center' => 'Kostenstelle', 'condition' => 'Zustand'];
    public const SOURCES = ['web' => 'Desktop', 'mobile' => 'Mobil', 'offline_sync' => 'Offline-Sync', 'import' => 'Import'];

    public function __construct(
        private readonly MovementRepository $movements,
        private readonly AssetRepository $assets,
        private readonly AssetStatusRepository $statuses,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly AssetHistoryRepository $history,
        private readonly AssetService $assetService,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser,
        private readonly DocumentService $documents,
        private readonly PdfClient $pdf,
        private readonly MailClient $mail,
        private readonly SettingsService $settings,
        private readonly Logger $logger
    ) {}

    /**
     * Asset anhand eines Scans oder einer Eingabe finden: Inventarnummer, QR-URL (…/a/PC26001) oder Seriennummer.
     * @return array<string,mixed>|null
     */
    public function resolveAsset(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        if (preg_match('#/a/([^/?\#]+)#', $code, $m)) {
            $code = rawurldecode($m[1]);
        }
        $asset = $this->assets->findByInventoryNumber(strtoupper($code));
        if ($asset !== null) {
            return $asset;
        }
        $normalized = AssetService::normalizeSerial($code);
        if ($normalized !== null) {
            $hits = $this->assets->search(['q' => $code, 'status' => 'all'], 2);
            $exact = array_values(array_filter($hits, static fn (array $a): bool => ($a['serial_number_normalized'] ?? null) === $normalized));
            if (count($exact) === 1) {
                return $exact[0];
            }
        }

        return null;
    }

    /**
     * Entnahme speichern.
     * @param array<string,mixed> $input asset_id|inventory_number, employee_id, location_id, cost_center_id, movement_date, expected_return_at, note, client_transaction_id, asset_version
     * @param bool $signedElectronically Desktop-Erfassung ohne Kamera-Scan: Vorgang wurde durch erneute Passworteingabe elektronisch signiert
     * @return array<string,mixed> Bewegung inkl. Asset-Daten
     */
    public function checkout(array $input, string $source = 'web', bool $signedElectronically = false): array
    {
        $this->currentUser->require('movements.checkout');
        if ($existing = $this->existingByTransaction($input)) {
            return $existing;
        }
        $asset = $this->assetFromInput($input);
        $this->assertVersion($asset, $input);

        $v = new Validator($input);
        $v->id('employee_id', 'Mitarbeiter', true)
            ->id('location_id', 'Standort')
            ->id('cost_center_id', 'Kostenstelle')
            ->date('movement_date', 'Entnahmedatum')
            ->date('expected_return_at', 'Rückgabe erwartet bis')
            ->text('note', 'Bemerkung', false, 2000)
            ->bool('send_email')
            ->string('client_transaction_id', 'Transaktions-ID', false, 36);
        $data = $v->validated();

        if ((int) $asset['status_final'] === 1) {
            throw new ConflictException('Das Asset ist ' . mb_strtolower((string) $asset['status_name']) . ' und kann nicht ausgegeben werden.');
        }
        if ($asset['employee_id'] !== null) {
            throw new ConflictException(
                'Das Asset ist bereits an ' . $asset['employee_name'] . ' ausgegeben. Bitte zuerst die Rückgabe erfassen.',
                ['asset_id' => (int) $asset['id'], 'employee_id' => (int) $asset['employee_id'], 'employee_name' => $asset['employee_name']]
            );
        }
        $employee = $this->employees->find((int) $data['employee_id']);
        if ($employee === null || (int) $employee['is_active'] !== 1) {
            throw ValidationException::single('employee_id', 'Der Mitarbeiter existiert nicht oder ist deaktiviert.');
        }
        $location = $this->optionalLocation($data['location_id']);
        // Kostenstelle: Eingabe → Mitarbeiter → bisherige des Assets
        $costCenterId = $data['cost_center_id'] ?? ($employee['cost_center_id'] !== null ? (int) $employee['cost_center_id'] : ($asset['cost_center_id'] !== null ? (int) $asset['cost_center_id'] : null));
        $costCenter = $this->optionalCostCenter($costCenterId);

        $missing = [];
        if ($location === null) {
            $missing[] = 'location';
        }
        if ($costCenter === null) {
            $missing[] = 'cost_center';
        }
        $movementDate = $data['movement_date'] ?? date('Y-m-d');
        $issued = $this->statuses->requireByCode('issued');

        $movement = $this->movements->transaction(function () use ($asset, $employee, $location, $costCenter, $missing, $movementDate, $data, $source, $issued, $signedElectronically): array {
            $movementId = $this->movements->create([
                'type' => 'checkout',
                'status' => $missing === [] ? 'completed' : 'open',
                'asset_id' => (int) $asset['id'],
                'employee_id' => (int) $employee['id'],
                'from_location_id' => $asset['location_id'],
                'to_location_id' => $location['id'] ?? null,
                'cost_center_id' => $costCenter['id'] ?? null,
                'movement_date' => $movementDate,
                'movement_at' => self::movementAt($movementDate),
                'missing_fields' => json_encode($missing),
                'note' => $data['note'],
                'client_transaction_id' => $data['client_transaction_id'] ?: null,
                'source' => $source,
                'signed_electronically' => $signedElectronically ? 1 : 0,
                'signed_at' => $signedElectronically ? gmdate('Y-m-d H:i:s') : null,
                'notify_email' => $data['send_email'] ? 1 : 0,
                'created_by' => $this->currentUser->id(),
                'created_by_name' => $this->currentUser->displayName(),
                'completed_by' => $missing === [] ? $this->currentUser->id() : null,
                'completed_at' => $missing === [] ? gmdate('Y-m-d H:i:s') : null,
            ]);

            $update = ['employee_id' => (int) $employee['id'], 'status_id' => (int) $issued['id'], 'expected_return_at' => $data['expected_return_at']];
            if ($location !== null) {
                $update['location_id'] = (int) $location['id'];
            }
            if ($costCenter !== null) {
                $update['cost_center_id'] = (int) $costCenter['id'];
            }
            $this->assets->update((int) $asset['id'], $update);
            $fresh = $this->assets->find((int) $asset['id']) ?? $asset;

            $summary = 'an ' . $employee['display_name'] . ($location !== null ? ' · ' . $location['full_path'] : ' · Standort offen');
            $this->assetService->addMovementEvent((int) $asset['id'], $movementId, 'checkout', $asset['location_path'] ?? null, $summary, $data['note']);
            $this->assetService->recordDiff((int) $asset['id'], $asset, $fresh, null, $movementId);
            $this->audit->log('checkout', 'movement', $movementId, $asset['inventory_number'] . ' → ' . $employee['display_name'], null, [
                'asset_id' => (int) $asset['id'], 'employee_id' => (int) $employee['id'], 'location_id' => $location['id'] ?? null, 'missing' => $missing, 'source' => $source, 'signed_electronically' => $signedElectronically,
            ]);

            return $this->movements->find($movementId) ?? [];
        });
        $this->maybeSendReceiptEmail($movement);

        return $movement;
    }

    /**
     * Retoure speichern.
     * @param array<string,mixed> $input asset_id|inventory_number, condition_code, has_damage, damage_description, accessories_checked, accessories_note,
     *                                   to_location_id, cost_center_id, target_status_code, movement_date, note, client_transaction_id, asset_version
     * @return array<string,mixed>
     */
    public function returnAsset(array $input, string $source = 'web', bool $signedElectronically = false): array
    {
        $this->currentUser->require('movements.return');
        if ($existing = $this->existingByTransaction($input)) {
            return $existing;
        }
        $asset = $this->assetFromInput($input);
        $this->assertVersion($asset, $input);

        $v = new Validator($input);
        $v->in('condition_code', 'Zustand', array_keys(self::CONDITIONS), true)
            ->bool('has_damage')
            ->text('damage_description', 'Schadensbeschreibung', false, 2000)
            ->bool('accessories_checked')
            ->string('accessories_note', 'Zubehör', false, 500)
            ->id('to_location_id', 'Standort')
            ->id('cost_center_id', 'Kostenstelle')
            ->in('target_status_code', 'Zielstatus', array_keys(self::RETURN_TARGETS))
            ->date('movement_date', 'Rückgabedatum')
            ->text('note', 'Bemerkung', false, 2000)
            ->bool('send_email')
            ->string('client_transaction_id', 'Transaktions-ID', false, 36);
        if (!empty($input['has_damage']) && trim((string) ($input['damage_description'] ?? '')) === '') {
            $v->addError('damage_description', 'Bitte den Schaden kurz beschreiben.');
        }
        $data = $v->validated();

        if ($asset['employee_id'] === null && !in_array($asset['status_code'], ['issued', 'return_expected'], true)) {
            throw new ConflictException('Das Asset ist derzeit nicht ausgegeben (' . $asset['status_name'] . ') – eine Rückgabe ist nicht möglich.');
        }
        $location = $this->optionalLocation($data['to_location_id']);
        $costCenter = $this->optionalCostCenter($data['cost_center_id']);
        $targetCode = $data['target_status_code'] ?? self::defaultTargetStatus((string) $data['condition_code'], (bool) $data['has_damage']);
        $target = $this->statuses->requireByCode($targetCode);
        if ((int) $target['is_final'] === 1) {
            $this->currentUser->require('assets.retire');
        }

        $missing = $location === null ? ['location'] : [];
        $movementDate = $data['movement_date'] ?? date('Y-m-d');

        $movement = $this->movements->transaction(function () use ($asset, $location, $costCenter, $target, $missing, $movementDate, $data, $source, $signedElectronically): array {
            $movementId = $this->movements->create([
                'type' => 'return',
                'status' => $missing === [] ? 'completed' : 'open',
                'asset_id' => (int) $asset['id'],
                'employee_id' => $asset['employee_id'],
                'from_location_id' => $asset['location_id'],
                'to_location_id' => $location['id'] ?? null,
                'cost_center_id' => $costCenter['id'] ?? $asset['cost_center_id'],
                'movement_date' => $movementDate,
                'movement_at' => self::movementAt($movementDate),
                'condition_code' => $data['condition_code'],
                'has_damage' => $data['has_damage'] ? 1 : 0,
                'damage_description' => $data['has_damage'] ? $data['damage_description'] : null,
                'accessories_checked' => $data['accessories_checked'] ? 1 : 0,
                'accessories_note' => $data['accessories_note'],
                'target_status_code' => $target['code'],
                'missing_fields' => json_encode($missing),
                'note' => $data['note'],
                'client_transaction_id' => $data['client_transaction_id'] ?: null,
                'source' => $source,
                'signed_electronically' => $signedElectronically ? 1 : 0,
                'signed_at' => $signedElectronically ? gmdate('Y-m-d H:i:s') : null,
                'notify_email' => $data['send_email'] ? 1 : 0,
                'created_by' => $this->currentUser->id(),
                'created_by_name' => $this->currentUser->displayName(),
                'completed_by' => $missing === [] ? $this->currentUser->id() : null,
                'completed_at' => $missing === [] ? gmdate('Y-m-d H:i:s') : null,
            ]);

            $update = ['employee_id' => null, 'expected_return_at' => null, 'status_id' => (int) $target['id']];
            if ($location !== null) {
                $update['location_id'] = (int) $location['id'];
            }
            if ($costCenter !== null) {
                $update['cost_center_id'] = (int) $costCenter['id'];
            }
            $this->assets->update((int) $asset['id'], $update);
            $fresh = $this->assets->find((int) $asset['id']) ?? $asset;

            $summary = 'Zustand: ' . self::CONDITIONS[$data['condition_code']] . ($data['has_damage'] ? ' (Schaden)' : '') . ' → ' . $target['name'] . ($location !== null ? ' · ' . $location['full_path'] : ' · Standort offen');
            $note = trim(implode("\n", array_filter([$data['has_damage'] ? 'Schaden: ' . $data['damage_description'] : null, $data['note']])));
            $this->assetService->addMovementEvent((int) $asset['id'], $movementId, 'return', $asset['employee_name'] !== null ? 'von ' . $asset['employee_name'] : null, $summary, $note !== '' ? $note : null);
            $this->assetService->recordDiff((int) $asset['id'], $asset, $fresh, null, $movementId);
            $this->audit->log('return', 'movement', $movementId, $asset['inventory_number'] . ' ← ' . ($asset['employee_name'] ?? '–'), null, [
                'asset_id' => (int) $asset['id'], 'condition' => $data['condition_code'], 'has_damage' => (bool) $data['has_damage'], 'target_status' => $target['code'], 'missing' => $missing, 'source' => $source, 'signed_electronically' => $signedElectronically,
            ]);

            return $this->movements->find($movementId) ?? [];
        });
        $this->maybeSendReceiptEmail($movement);

        return $movement;
    }

    /**
     * Nachbearbeitung am Desktop: fehlende Angaben ergänzen, optional abschließen.
     * Änderungen an Mitarbeiter/Standort/Kostenstelle werden auf das Asset übertragen, sofern die Bewegung die aktuellste des Assets ist.
     * @param array<string,mixed> $movement
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $id, array $movement, array $input, bool $complete): array
    {
        $this->currentUser->require('movements.complete');
        if ($movement['status'] === 'cancelled') {
            throw new ConflictException('Ein stornierter Vorgang kann nicht bearbeitet werden.');
        }
        $isCheckout = $movement['type'] === 'checkout';

        $v = new Validator($input);
        $v->id('to_location_id', 'Standort')
            ->id('cost_center_id', 'Kostenstelle')
            ->date('movement_date', $isCheckout ? 'Entnahmedatum' : 'Rückgabedatum', true)
            ->text('note', 'Bemerkung', false, 2000);
        if ($isCheckout) {
            $v->id('employee_id', 'Mitarbeiter', true)->date('expected_return_at', 'Rückgabe erwartet bis');
        } else {
            $v->in('condition_code', 'Zustand', array_keys(self::CONDITIONS), true)
                ->bool('has_damage')->text('damage_description', 'Schadensbeschreibung', false, 2000)
                ->bool('accessories_checked')->string('accessories_note', 'Zubehör', false, 500)
                ->in('target_status_code', 'Zielstatus', array_keys(self::RETURN_TARGETS), true);
            if (!empty($input['has_damage']) && trim((string) ($input['damage_description'] ?? '')) === '') {
                $v->addError('damage_description', 'Bitte den Schaden kurz beschreiben.');
            }
        }
        $data = $v->validated();

        $location = $this->optionalLocation($data['to_location_id']);
        $costCenter = $this->optionalCostCenter($data['cost_center_id']);
        $employee = null;
        if ($isCheckout) {
            $employee = $this->employees->find((int) $data['employee_id']);
            if ($employee === null) {
                throw ValidationException::single('employee_id', 'Der Mitarbeiter existiert nicht.');
            }
        }
        $target = $isCheckout ? null : $this->statuses->requireByCode((string) $data['target_status_code']);
        if ($target !== null && (int) $target['is_final'] === 1 && $target['code'] !== $movement['target_status_code']) {
            $this->currentUser->require('assets.retire');
        }

        $missing = [];
        if ($location === null) {
            $missing[] = 'location';
        }
        if ($isCheckout && $costCenter === null) {
            $missing[] = 'cost_center';
        }
        if ($complete && $missing !== []) {
            $errors = [];
            foreach ($missing as $m) {
                $errors[$m === 'location' ? 'to_location_id' : 'cost_center_id'] = self::MISSING_LABELS[$m] . ' fehlt – der Vorgang kann erst danach abgeschlossen werden.';
            }
            throw new ValidationException($errors);
        }
        $nowComplete = $complete || ($movement['status'] === 'completed');

        return $this->movements->transaction(function () use ($id, $movement, $data, $location, $costCenter, $employee, $target, $missing, $nowComplete, $isCheckout): array {
            $row = [
                'to_location_id' => $location['id'] ?? null,
                'cost_center_id' => $costCenter['id'] ?? null,
                'movement_date' => $data['movement_date'],
                'note' => $data['note'],
                'missing_fields' => json_encode($missing),
                'status' => $nowComplete && $missing === [] ? 'completed' : 'open',
            ];
            if ($data['movement_date'] !== $movement['movement_date']) {
                $row['movement_at'] = self::movementAt($data['movement_date']);
            }
            if ($isCheckout) {
                $row['employee_id'] = (int) $employee['id'];
            } else {
                $row['condition_code'] = $data['condition_code'];
                $row['has_damage'] = $data['has_damage'] ? 1 : 0;
                $row['damage_description'] = $data['has_damage'] ? $data['damage_description'] : null;
                $row['accessories_checked'] = $data['accessories_checked'] ? 1 : 0;
                $row['accessories_note'] = $data['accessories_note'];
                $row['target_status_code'] = $target['code'];
            }
            if ($row['status'] === 'completed' && $movement['status'] !== 'completed') {
                $row['completed_by'] = $this->currentUser->id();
                $row['completed_at'] = gmdate('Y-m-d H:i:s');
            }
            $this->movements->update($id, $row);

            // Asset nur anfassen, wenn dies die aktuellste Bewegung ist (sonst wäre die Realität längst weiter)
            if ($this->isLatestMovement($movement)) {
                $asset = $this->assets->find((int) $movement['asset_id']);
                if ($asset !== null) {
                    $update = [];
                    if ($location !== null && (int) $asset['location_id'] !== (int) $location['id']) {
                        $update['location_id'] = (int) $location['id'];
                    }
                    if ($costCenter !== null && (int) $asset['cost_center_id'] !== (int) $costCenter['id']) {
                        $update['cost_center_id'] = (int) $costCenter['id'];
                    }
                    if ($isCheckout) {
                        if ((int) $asset['employee_id'] !== (int) $employee['id']) {
                            $update['employee_id'] = (int) $employee['id'];
                        }
                        if ((string) $asset['expected_return_at'] !== (string) $data['expected_return_at']) {
                            $update['expected_return_at'] = $data['expected_return_at'];
                        }
                    } elseif ((int) $asset['status_id'] !== (int) $target['id']) {
                        $update['status_id'] = (int) $target['id'];
                    }
                    if ($update !== []) {
                        $this->assets->update((int) $asset['id'], $update);
                        $fresh = $this->assets->find((int) $asset['id']) ?? $asset;
                        $this->assetService->recordDiff((int) $asset['id'], $asset, $fresh, 'Nachbearbeitung ' . ($isCheckout ? 'Entnahme' : 'Retoure'), $id);
                    }
                }
            }
            if ($row['status'] === 'completed' && $movement['status'] !== 'completed') {
                $this->assetService->addMovementEvent((int) $movement['asset_id'], $id, 'movement_completed', null, ($isCheckout ? 'Entnahme' : 'Retoure') . ' abgeschlossen');
            }
            $this->audit->log('update', 'movement', $id, $movement['inventory_number'], $movement, $row);

            return $this->movements->find($id) ?? [];
        });
    }

    /**
     * Storniert einen Vorgang und setzt die dadurch geänderten Assetfelder anhand der Historie zurück.
     * Nur für die aktuellste Bewegung eines Assets möglich.
     * @param array<string,mixed> $movement
     */
    public function cancel(int $id, array $movement, string $reason): array
    {
        $this->currentUser->require('movements.complete');
        if ($movement['status'] === 'cancelled') {
            throw new ConflictException('Der Vorgang ist bereits storniert.');
        }
        if (!$this->isLatestMovement($movement)) {
            throw new ConflictException('Nur der jüngste Vorgang eines Assets kann storniert werden.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'Bitte einen Grund angeben.');
        }

        $this->movements->transaction(function () use ($id, $movement, $reason): void {
            $asset = $this->assets->find((int) $movement['asset_id']);
            if ($asset !== null) {
                $revert = [];
                foreach ($this->history->forMovement($id) as $entry) {
                    $field = $entry['field'];
                    if ($field === null || !array_key_exists($field, AssetService::FIELD_LABELS) || array_key_exists($field, $revert)) {
                        continue;
                    }
                    $revert[$field] = str_ends_with($field, '_id') ? $entry['old_id'] : self::fromDisplay($entry['old_value']);
                }
                if ($revert !== []) {
                    $this->assets->update((int) $asset['id'], $revert);
                    $fresh = $this->assets->find((int) $asset['id']) ?? $asset;
                    $this->assetService->recordDiff((int) $asset['id'], $asset, $fresh, 'Storno: ' . $reason, $id);
                }
            }
            $this->movements->update($id, ['status' => 'cancelled', 'note' => trim(($movement['note'] ?? '') . "\nStorniert: " . $reason)]);
            $this->assetService->addMovementEvent((int) $movement['asset_id'], $id, 'movement_cancelled', null, ($movement['type'] === 'checkout' ? 'Entnahme' : 'Retoure') . ' storniert', $reason);
            $this->audit->log('cancel', 'movement', $id, $movement['inventory_number'], ['status' => $movement['status']], ['status' => 'cancelled', 'reason' => $reason]);
        });

        return $this->movements->find($id) ?? $movement;
    }

    /**
     * Sendet – falls im Workflow per Opt-in-Checkbox angefordert – den Entnahme-/Retourennachweis
     * als PDF per E-Mail an den betroffenen Mitarbeiter. Fehler (kein Mail-Dienst, keine
     * E-Mail-Adresse, SMTP-Fehler) werden nur geloggt und dürfen den Workflow nicht unterbrechen.
     * @param array<string,mixed> $movement
     */
    private function maybeSendReceiptEmail(array $movement): void
    {
        if (empty($movement['notify_email']) || $movement['status'] !== 'completed' || !empty($movement['email_sent_at'])) {
            return;
        }
        $email = trim((string) ($movement['employee_email'] ?? ''));
        if ($email === '' || !$this->mail->enabled()) {
            return;
        }
        try {
            $html = MovementReceiptRenderer::renderDocument($movement, $this->settings->companyName());
            $pdf = $this->pdf->render($html);
            $subject = ($movement['type'] === 'checkout' ? 'Entnahmenachweis' : 'Retourennachweis') . ' ' . $movement['inventory_number'];
            $attachments = [];
            if ($pdf !== null) {
                $this->documents->storeGenerated('movement', (int) $movement['id'], 'movement_receipt', $pdf, $subject . '.pdf', 'Automatisch per E-Mail versendet');
                $attachments[] = ['filename' => $subject . '.pdf', 'content' => $pdf, 'mime_type' => 'application/pdf'];
            }
            $body = '<p>Hallo ' . htmlspecialchars((string) ($movement['employee_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>im Anhang finden Sie Ihren ' . ($movement['type'] === 'checkout' ? 'Entnahmenachweis' : 'Retourennachweis')
                . ' für ' . htmlspecialchars((string) $movement['inventory_number'], ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>Diese E-Mail wurde automatisch von KLINQ erzeugt.</p>';
            $sent = $this->mail->send($email, $subject, $body, $attachments);
            if ($sent) {
                $this->movements->update((int) $movement['id'], ['email_sent_at' => gmdate('Y-m-d H:i:s'), 'email_sent_to' => $email]);
                $this->audit->log('email', 'movement', (int) $movement['id'], $subject, null, ['to' => $email]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Nachweis konnte nicht per E-Mail versendet werden', ['movement_id' => $movement['id'], 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string,mixed> $movement @return array<int,string> Fehlende Angaben als Labels */
    /** Zeitstempel (UTC) für die Bewegung: jetzt bei heutigem Datum, sonst 12:00 Ortszeit des gewählten Tages. */
    private static function movementAt(string $movementDate): string
    {
        $local = $movementDate === date('Y-m-d') ? new \DateTimeImmutable('now') : new \DateTimeImmutable($movementDate . ' 12:00:00');

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function missingLabels(array $movement): array
    {
        $missing = json_decode((string) ($movement['missing_fields'] ?? '[]'), true);

        return array_values(array_map(static fn (string $m): string => self::MISSING_LABELS[$m] ?? $m, is_array($missing) ? $missing : []));
    }

    public static function defaultTargetStatus(string $condition, bool $hasDamage): string
    {
        return match (true) {
            $condition === 'defective' => 'defective',
            $condition === 'damaged' || $hasDamage => 'repair',
            default => 'in_stock',
        };
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed> $input @return array<string,mixed>|null */
    private function existingByTransaction(array $input): ?array
    {
        $tx = trim((string) ($input['client_transaction_id'] ?? ''));
        if ($tx === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9-]{8,36}$/', $tx)) {
            throw ValidationException::single('client_transaction_id', 'Ungültige Transaktions-ID.');
        }

        return $this->movements->findByClientTransaction($tx);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function assetFromInput(array $input): array
    {
        $asset = null;
        if (!empty($input['asset_id'])) {
            $asset = $this->assets->find((int) $input['asset_id']);
        } elseif (!empty($input['inventory_number'])) {
            $asset = $this->resolveAsset((string) $input['inventory_number']);
        }
        if ($asset === null) {
            throw ValidationException::single('inventory_number', 'Asset nicht gefunden.');
        }

        return $asset;
    }

    /** Optimistische Sperre für Offline-Vorgänge: Das Asset darf sich seit dem Scan nicht geändert haben. @param array<string,mixed> $asset @param array<string,mixed> $input */
    private function assertVersion(array $asset, array $input): void
    {
        if (isset($input['asset_version']) && $input['asset_version'] !== '' && (int) $input['asset_version'] !== (int) $asset['version']) {
            throw new ConflictException(
                'Das Asset ' . $asset['inventory_number'] . ' wurde zwischenzeitlich geändert (aktuell: ' . $asset['status_name'] . ($asset['employee_name'] ? ', ' . $asset['employee_name'] : '') . '). Bitte prüfen und erneut erfassen.',
                ['asset_id' => (int) $asset['id'], 'version' => (int) $asset['version'], 'status' => $asset['status_code'], 'employee_name' => $asset['employee_name']]
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function optionalLocation(mixed $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $location = $this->locations->find((int) $id);
        if ($location === null || (int) $location['is_active'] !== 1) {
            throw ValidationException::single('location_id', 'Der Standort existiert nicht oder ist deaktiviert.');
        }

        return $location;
    }

    /** @return array<string,mixed>|null */
    private function optionalCostCenter(mixed $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $cc = $this->costCenters->find((int) $id);
        if ($cc === null || (int) $cc['is_active'] !== 1) {
            throw ValidationException::single('cost_center_id', 'Die Kostenstelle existiert nicht oder ist deaktiviert.');
        }

        return $cc;
    }

    /** @param array<string,mixed> $movement */
    private function isLatestMovement(array $movement): bool
    {
        $latest = $this->movements->forAsset((int) $movement['asset_id'], 1)[0] ?? null;
        if ($latest === null) {
            return true;
        }
        if ((int) $latest['id'] === (int) $movement['id']) {
            return true;
        }
        // Stornierte spätere Bewegungen zählen nicht
        foreach ($this->movements->forAsset((int) $movement['asset_id'], 20) as $m) {
            if ($m['status'] !== 'cancelled') {
                return (int) $m['id'] === (int) $movement['id'];
            }
        }

        return true;
    }

    /** Historie speichert Klartext (dd.mm.yyyy) – für das Zurücksetzen von Datumsfeldern wieder ISO. */
    private static function fromDisplay(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return $value;
    }
}
