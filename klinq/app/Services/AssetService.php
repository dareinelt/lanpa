<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Security\CurrentUser;
use App\Support\Validator;

/**
 * Anlegen, Bearbeiten und Statuswechsel von Assets inkl. Inventarnummernvergabe
 * und lückenloser Historie (asset_history).
 */
final class AssetService
{
    /** Feld → [Bezeichnung, Spalte mit Klartext im Detail-Datensatz] */
    public const FIELD_LABELS = [
        'asset_type_id' => ['Assettyp', 'asset_type_name'],
        'asset_category_id' => ['Kategorie', 'category_name'],
        'manufacturer_id' => ['Hersteller', 'manufacturer_name'],
        'article_id' => ['Artikel', 'article_name'],
        'name' => ['Bezeichnung', 'name'],
        'serial_number' => ['Seriennummer', 'serial_number'],
        'mac_address' => ['MAC-Adresse', 'mac_address'],
        'imei' => ['IMEI', 'imei'],
        'purchase_date' => ['Kaufdatum', 'purchase_date'],
        'supplier_id' => ['Lieferant', 'supplier_name'],
        'purchase_order_id' => ['Bestellung', 'order_number'],
        'purchase_price' => ['Anschaffungskosten', 'purchase_price'],
        'warranty_until' => ['Garantieende', 'warranty_until'],
        'location_id' => ['Standort', 'location_path'],
        'cost_center_id' => ['Kostenstelle', 'cost_center_number'],
        'employee_id' => ['Mitarbeiter', 'employee_name'],
        'expected_return_at' => ['Rückgabe erwartet bis', 'expected_return_at'],
        'status_id' => ['Status', 'status_name'],
        'parent_asset_id' => ['Übergeordnetes Asset', 'parent_inventory_number'],
        'is_legacy' => ['Altbestand', 'is_legacy'],
        'note' => ['Bemerkung', 'note'],
    ];

    /** Feld → Ereignistyp in der Historie */
    private const EVENT_TYPES = [
        'status_id' => 'status_changed',
        'employee_id' => 'assignment_changed',
        'location_id' => 'location_changed',
        'cost_center_id' => 'cost_center_changed',
    ];

    public function __construct(
        private readonly AssetRepository $assets,
        private readonly AssetTypeRepository $types,
        private readonly AssetStatusRepository $statuses,
        private readonly ArticleRepository $articles,
        private readonly AssetHistoryRepository $history,
        private readonly InventoryNumberService $numbers,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    /**
     * @param array<string,mixed> $input
     * @return array{id:int, inventory_number:string}
     */
    public function create(array $input): array
    {
        $data = $this->validate($input, null);
        $type = $this->types->find((int) $data['asset_type_id']) ?? throw ValidationException::single('asset_type_id', 'Assettyp nicht gefunden.');
        $legacy = (int) ($data['is_legacy'] ?? 0) === 1;
        $manualNumber = $data['inventory_number'] ?? null;
        unset($data['inventory_number']);

        return $this->assets->transaction(function () use ($data, $type, $legacy, $manualNumber): array {
            $number = $manualNumber ?? $this->numbers->next((string) $type['inventory_prefix'], $legacy);
            $data['inventory_number'] = $number;
            $data['created_by'] = $this->currentUser->id();
            $id = $this->assets->create($data);
            $this->history->add($id, $this->entry('created', null, null, $number, note: $legacy ? 'Nachinventarisierung Altbestand' : null));
            $row = $this->assets->find($id) ?? [];
            foreach (['employee_id', 'location_id', 'status_id'] as $field) {
                if (!empty($row[$field])) {
                    $this->history->add($id, $this->entry(self::EVENT_TYPES[$field] ?? 'field_changed', $field, null, (string) $row[self::FIELD_LABELS[$field][1]], newId: (int) $row[$field]));
                }
            }
            $this->audit->log('create', 'asset', $id, $number, null, $data);

            return ['id' => $id, 'inventory_number' => $number];
        });
    }

    /**
     * @param array<string,mixed> $existing Detail-Datensatz (AssetRepository::find)
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $existing);
        unset($data['inventory_number'], $data['is_legacy']);
        $expectedVersion = isset($input['version']) && $input['version'] !== '' ? (int) $input['version'] : (int) $existing['version'];

        $this->assets->transaction(function () use ($id, $existing, $data, $expectedVersion): void {
            if (!$this->assets->updateVersioned($id, $expectedVersion, $data)) {
                throw new ConflictException('Das Asset wurde zwischenzeitlich von einer anderen Person geändert. Bitte Seite neu laden und Änderungen erneut vornehmen.');
            }
            $fresh = $this->assets->find($id) ?? $existing;
            $this->recordDiff($id, $existing, $fresh);
            $this->audit->log('update', 'asset', $id, (string) $existing['inventory_number'], $existing, $data);
        });
    }

    /**
     * Schneller Statuswechsel (z. B. „defekt“, „ausmustern“) mit Historieneintrag.
     * @param array<string,mixed> $existing
     */
    public function changeStatus(int $id, array $existing, string $statusCode, ?string $note = null): void
    {
        $status = $this->statuses->findByCode($statusCode) ?? throw ValidationException::single('status', 'Unbekannter Status.');
        if ((int) $status['is_active'] !== 1) {
            throw ValidationException::single('status', 'Dieser Status ist deaktiviert.');
        }
        if ((int) $status['is_final'] === 1) {
            $this->currentUser->require('assets.retire');
        }
        if ((int) $existing['status_id'] === (int) $status['id']) {
            return;
        }
        $data = ['status_id' => (int) $status['id']];
        // Endstatus: Zuordnung zum Mitarbeiter endet, Rückgabefrist entfällt
        if ((int) $status['is_final'] === 1) {
            $data['employee_id'] = null;
            $data['expected_return_at'] = null;
        }
        if ((int) $status['is_available'] === 1) {
            $data['employee_id'] = null;
            $data['expected_return_at'] = null;
        }
        $this->assets->transaction(function () use ($id, $existing, $data, $note): void {
            $this->assets->update($id, $data);
            $fresh = $this->assets->find($id) ?? $existing;
            $this->recordDiff($id, $existing, $fresh, $note !== null && trim($note) !== '' ? trim($note) : null);
            $this->audit->log('status', 'asset', $id, (string) $existing['inventory_number'], ['status_id' => $existing['status_id']], $data);
        });
    }

    /** Fügt einen freien Kommentar zur Historie hinzu. */
    public function addNote(int $id, array $existing, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::single('note', 'Bitte einen Text eingeben.');
        }
        $this->history->add($id, $this->entry('note', null, null, null, note: $note));
    }

    /**
     * Prüft Eingaben für ein neues Asset ohne zu schreiben (Importvorschau). Liefert die bereinigten Daten.
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function validateNew(array $input): array
    {
        return $this->validate($input, null);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validate(array $input, ?array $existing): array
    {
        $excludeId = $existing !== null ? (int) $existing['id'] : null;
        $v = (new Validator($input))
            ->id('asset_type_id', 'Assettyp', $existing === null)
            ->id('asset_category_id', 'Kategorie')
            ->id('manufacturer_id', 'Hersteller')
            ->id('article_id', 'Artikel')
            ->string('name', 'Bezeichnung', false, 200)
            ->string('serial_number', 'Seriennummer', false, 120)
            ->string('mac_address', 'MAC-Adresse', false, 40)
            ->string('imei', 'IMEI', false, 30)
            ->date('purchase_date', 'Kaufdatum')
            ->id('supplier_id', 'Lieferant')
            ->id('purchase_order_id', 'Bestellung')
            ->id('purchase_order_item_id', 'Bestellposition')
            ->id('goods_receipt_id', 'Wareneingang')
            ->decimal('purchase_price', 'Anschaffungskosten', false, 0.0)
            ->date('warranty_until', 'Garantieende')
            ->id('location_id', 'Standort')
            ->id('cost_center_id', 'Kostenstelle')
            ->id('employee_id', 'Mitarbeiter')
            ->date('expected_return_at', 'Rückgabe erwartet bis')
            ->id('status_id', 'Status')
            ->id('parent_asset_id', 'Übergeordnetes Asset')
            ->text('note', 'Bemerkung', false, 5000);
        if ($existing === null) {
            $v->bool('is_legacy')->string('inventory_number', 'Inventarnummer', false, 20);
        }
        $data = $v->validated();

        // Bei Bearbeitung darf der Assettyp nicht gewechselt werden (Präfix der Inventarnummer)
        if ($existing !== null) {
            $data['asset_type_id'] = (int) $existing['asset_type_id'];
        }

        // Artikel ist Pflicht (Stammdaten): Typ und Hersteller werden vom Artikel abgeleitet, Kategorie wenn leer
        if ($data['article_id'] === null) {
            throw ValidationException::single('article_id', 'Bitte einen Artikel aus den Stammdaten wählen. Fehlt der Artikel, muss er zuerst unter „Artikel“ angelegt werden.');
        }
        $article = $this->articles->find((int) $data['article_id']);
        if ($article === null) {
            throw ValidationException::single('article_id', 'Artikel nicht gefunden. Bitte einen vorhandenen Artikel aus den Stammdaten wählen.');
        }
        $keepsArticle = $existing !== null && (int) ($existing['article_id'] ?? 0) === (int) $article['id'];
        if ((int) $article['is_active'] !== 1 && !$keepsArticle) {
            throw ValidationException::single('article_id', 'Der Artikel „' . $article['name'] . '“ ist inaktiv und kann nicht mehr verwendet werden.');
        }
        $data['asset_type_id'] ??= (int) $article['asset_type_id'];
        if ((int) $article['asset_type_id'] !== (int) $data['asset_type_id']) {
            throw ValidationException::single('article_id', 'Der Artikel gehört zum Assettyp „' . $article['asset_type_name'] . '“ und passt nicht zum gewählten Assettyp.');
        }
        $data['manufacturer_id'] = (int) $article['manufacturer_id'];
        $data['asset_category_id'] ??= $article['asset_category_id'] !== null ? (int) $article['asset_category_id'] : null;
        if ($data['asset_type_id'] === null) {
            throw ValidationException::single('asset_type_id', 'Assettyp ist erforderlich.');
        }
        $type = $this->types->find((int) $data['asset_type_id']);
        if ($type === null) {
            throw ValidationException::single('asset_type_id', 'Assettyp nicht gefunden.');
        }
        if ($data['asset_category_id'] !== null) {
            $category = $this->types->findCategory((int) $data['asset_category_id']);
            if ($category === null || (int) $category['asset_type_id'] !== (int) $data['asset_type_id']) {
                throw ValidationException::single('asset_category_id', 'Die Kategorie gehört nicht zum gewählten Assettyp.');
            }
        }

        // Kennungen normalisieren und auf Dubletten prüfen
        $data['serial_number_normalized'] = $data['serial_number'] !== null ? self::normalizeSerial($data['serial_number']) : null;
        if ($data['serial_number_normalized'] !== null) {
            $dup = $this->assets->findBySerial((int) $data['asset_type_id'], $data['serial_number_normalized'], $excludeId);
            if ($dup !== null) {
                throw ValidationException::single('serial_number', 'Diese Seriennummer ist innerhalb des Assettyps bereits vergeben (' . $dup['inventory_number'] . ').');
            }
        }
        if ($data['mac_address'] !== null) {
            $mac = self::normalizeMac($data['mac_address']);
            if ($mac === null) {
                throw ValidationException::single('mac_address', 'Die MAC-Adresse muss aus 12 Hexadezimalzeichen bestehen (z. B. 00:1A:2B:3C:4D:5E).');
            }
            $data['mac_address'] = $mac;
            $dup = $this->assets->findByUniqueField('mac_address', $mac, $excludeId);
            if ($dup !== null) {
                throw ValidationException::single('mac_address', 'Diese MAC-Adresse ist bereits bei ' . $dup['inventory_number'] . ' hinterlegt.');
            }
        }
        if ($data['imei'] !== null) {
            $imei = self::normalizeImei($data['imei']);
            if ($imei === null) {
                throw ValidationException::single('imei', 'Die IMEI muss aus 14–16 Ziffern bestehen.');
            }
            $data['imei'] = $imei;
            $dup = $this->assets->findByUniqueField('imei', $imei, $excludeId);
            if ($dup !== null) {
                throw ValidationException::single('imei', 'Diese IMEI ist bereits bei ' . $dup['inventory_number'] . ' hinterlegt.');
            }
        }
        if ($data['purchase_date'] !== null && $data['warranty_until'] !== null && $data['warranty_until'] < $data['purchase_date']) {
            throw ValidationException::single('warranty_until', 'Das Garantieende darf nicht vor dem Kaufdatum liegen.');
        }
        if ($data['parent_asset_id'] !== null) {
            if ($excludeId !== null && (int) $data['parent_asset_id'] === $excludeId) {
                throw ValidationException::single('parent_asset_id', 'Ein Asset kann nicht sich selbst übergeordnet sein.');
            }
            if ($this->assets->find((int) $data['parent_asset_id']) === null) {
                throw ValidationException::single('parent_asset_id', 'Übergeordnetes Asset nicht gefunden.');
            }
        }

        // Manuelle Inventarnummer (nur Altbestand/Import)
        if ($existing === null) {
            if ($data['inventory_number'] !== null) {
                $number = strtoupper(preg_replace('/\s+/', '', $data['inventory_number']) ?? '');
                if (!InventoryNumberService::isValid($number) || !str_starts_with($number, (string) $type['inventory_prefix'])) {
                    throw ValidationException::single('inventory_number', 'Die Inventarnummer muss dem Format ' . $type['inventory_prefix'] . 'JJNNN entsprechen (z. B. ' . $type['inventory_prefix'] . '88001).');
                }
                if ($this->assets->findByUniqueField('inventory_number', $number) !== null) {
                    throw ValidationException::single('inventory_number', 'Diese Inventarnummer ist bereits vergeben.');
                }
                $data['inventory_number'] = $number;
            } else {
                unset($data['inventory_number']);
            }
        }

        // Status: Standard „Lagerbestand“; mit Mitarbeiter automatisch „ausgegeben“
        $status = $data['status_id'] !== null ? $this->statuses->find((int) $data['status_id']) : null;
        if ($data['status_id'] !== null && $status === null) {
            throw ValidationException::single('status_id', 'Status nicht gefunden.');
        }
        if ($status === null) {
            $status = $existing !== null ? $this->statuses->find((int) $existing['status_id']) : $this->statuses->requireByCode('in_stock');
        }
        if ($data['employee_id'] !== null && (int) $status['is_available'] === 1) {
            $status = $this->statuses->requireByCode('issued');
        }
        if ($data['employee_id'] === null && $status['code'] === 'issued') {
            $status = $this->statuses->requireByCode('in_stock');
        }
        if ((int) $status['is_final'] === 1 && ($existing === null || (int) $existing['status_id'] !== (int) $status['id'])) {
            $this->currentUser->require('assets.retire');
        }
        $data['status_id'] = (int) $status['id'];
        if ($data['employee_id'] === null) {
            $data['expected_return_at'] = null;
        }

        return $data;
    }

    /**
     * Schreibt je geändertem Feld einen Historieneintrag mit Klartext-Werten.
     * @param array<string,mixed> $old
     * @param array<string,mixed> $new
     */
    public function recordDiff(int $id, array $old, array $new, ?string $note = null, ?int $movementId = null): void
    {
        foreach (self::FIELD_LABELS as $field => [, $labelColumn]) {
            if (!array_key_exists($field, $new)) {
                continue;
            }
            $oldRaw = $old[$field] ?? null;
            $newRaw = $new[$field] ?? null;
            if ((string) $oldRaw === (string) $newRaw) {
                continue;
            }
            $isId = str_ends_with($field, '_id');
            $this->history->add($id, $this->entry(
                self::EVENT_TYPES[$field] ?? 'field_changed',
                $field,
                self::stringify($old[$labelColumn] ?? null),
                self::stringify($new[$labelColumn] ?? null),
                $isId && $oldRaw !== null ? (int) $oldRaw : null,
                $isId && $newRaw !== null ? (int) $newRaw : null,
                $note,
                $movementId
            ));
        }
    }

    /** Historieneintrag mit Bezug zu einer Bewegung (Entnahme/Retoure). */
    public function addMovementEvent(int $assetId, int $movementId, string $eventType, ?string $old, ?string $new, ?string $note = null): void
    {
        $this->history->add($assetId, $this->entry($eventType, null, $old, $new, null, null, $note, $movementId));
    }

    /** Freier Historieneintrag ohne Feldbezug (z. B. Wareneingang, Etikettendruck). */
    public function addEvent(int $assetId, string $eventType, ?string $new = null, ?string $note = null, ?int $newId = null): void
    {
        $this->history->add($assetId, $this->entry($eventType, null, null, $new, null, $newId, $note));
    }

    /** @return array<string,mixed> */
    private function entry(string $type, ?string $field, ?string $old, ?string $new, ?int $oldId = null, ?int $newId = null, ?string $note = null, ?int $movementId = null): array
    {
        return [
            'event_type' => $type,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'old_id' => $oldId,
            'new_id' => $newId,
            'movement_id' => $movementId,
            'user_id' => $this->currentUser->id(),
            'actor_name' => $this->currentUser->displayName(),
            'note' => $note,
        ];
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value) && str_contains((string) $value, '.')) {
            return number_format((float) $value, 2, ',', '.');
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return $m[3] . '.' . $m[2] . '.' . $m[1];
        }

        return (string) $value;
    }

    /** Seriennummer für Dublettenvergleich: Großbuchstaben, ohne Leerzeichen/Bindestriche. */
    public static function normalizeSerial(string $serial): ?string
    {
        $n = strtoupper(preg_replace('/[\s\-_.\/]/', '', $serial) ?? '');

        return $n === '' ? null : $n;
    }

    /** MAC-Adresse als AA:BB:CC:DD:EE:FF; null wenn ungültig. */
    public static function normalizeMac(string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac) ?? '');
        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    /** IMEI: 14–16 Ziffern (IMEI 15, IMEISV 16, ohne Prüfziffer 14). */
    public static function normalizeImei(string $imei): ?string
    {
        $digits = preg_replace('/\D/', '', $imei) ?? '';
        $len = strlen($digits);

        return $len >= 14 && $len <= 16 ? $digits : null;
    }
}
