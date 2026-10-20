<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\LocationRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Support\Validator;

/**
 * Bestellungen mit Positionen, Statusübergängen und Wareneingang (auch in Teil-Lieferungen).
 *
 * Statusfluss: draft → ordered → partially_delivered → delivered → closed; Storno aus draft/ordered
 * (solange nichts geliefert wurde). Der Lieferstatus ergibt sich aus den Positionsmengen.
 * Beim Wareneingang entsteht je gelieferter Einheit ein Asset (Inventarnummer, Historie „Wareneingang“),
 * sofern die Position Assets erzeugt.
 */
final class PurchaseOrderService
{
    public const STATUSES = [
        'draft' => 'Entwurf', 'ordered' => 'Bestellt', 'partially_delivered' => 'Teilweise geliefert',
        'delivered' => 'Vollständig geliefert', 'cancelled' => 'Storniert', 'closed' => 'Abgeschlossen',
    ];
    public const STATUS_COLORS = [
        'draft' => 'neutral', 'ordered' => 'info', 'partially_delivered' => 'warning',
        'delivered' => 'success', 'cancelled' => 'danger', 'closed' => 'neutral',
    ];
    /** Status, in denen Kopf und Positionen bearbeitet werden dürfen */
    private const EDITABLE = ['draft', 'ordered'];
    /** Status, in denen Wareneingänge gebucht werden dürfen */
    private const RECEIVABLE = ['ordered', 'partially_delivered'];

    public function __construct(
        private readonly PurchaseOrderRepository $orders,
        private readonly SupplierRepository $suppliers,
        private readonly CostCenterRepository $costCenters,
        private readonly ArticleRepository $articles,
        private readonly AssetTypeRepository $types,
        private readonly LocationRepository $locations,
        private readonly AssetRepository $assets,
        private readonly AssetService $assetService,
        private readonly AuditLogService $audit,
        private readonly CurrentUser $currentUser
    ) {}

    // ------------------------------------------------------------------ Kopf

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $this->currentUser->require('orders.manage');
        $data = $this->validateHeader($input, null);
        $data['status'] = 'draft';
        $data['created_by'] = $this->currentUser->id();
        if ($data['ordered_by_name'] === null || $data['ordered_by_name'] === $this->currentUser->displayName()) {
            $data['ordered_by_user_id'] = $this->currentUser->id();
            $data['ordered_by_name'] = $this->currentUser->displayName();
        } else {
            $data['ordered_by_user_id'] = null;
        }

        return $this->orders->transaction(function () use ($data): int {
            if ($data['order_number'] === null) {
                $data['order_number'] = $this->orders->nextOrderNumber();
            }
            $id = $this->orders->create($data);
            $this->audit->log('create', 'purchase_order', $id, (string) $data['order_number'], null, $data);

            return $id;
        });
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $input */
    public function update(int $id, array $order, array $input): void
    {
        $this->currentUser->require('orders.manage');
        $this->assertEditable($order);
        $data = $this->validateHeader($input, $order);
        if ($data['order_number'] === null) {
            unset($data['order_number']);
        }
        $this->orders->update($id, $data);
        $this->audit->log('update', 'purchase_order', $id, (string) $order['order_number'], $order, $data);
    }

    /** Entwurf → Bestellt (mindestens eine Position nötig). @param array<string,mixed> $order */
    public function markOrdered(int $id, array $order): void
    {
        $this->currentUser->require('orders.manage');
        if ($order['status'] !== 'draft') {
            throw new ConflictException('Nur Entwürfe können als bestellt markiert werden.');
        }
        if ((int) $order['item_count'] === 0) {
            throw ValidationException::single('items', 'Die Bestellung hat noch keine Positionen.');
        }
        $data = ['status' => 'ordered'];
        if (empty($order['order_date'])) {
            $data['order_date'] = date('Y-m-d');
        }
        $this->orders->update($id, $data);
        $this->audit->log('status', 'purchase_order', $id, (string) $order['order_number'], ['status' => 'draft'], ['status' => 'ordered']);
    }

    /** Storno – nur solange nichts geliefert wurde. @param array<string,mixed> $order */
    public function cancel(int $id, array $order, string $reason): void
    {
        $this->currentUser->require('orders.manage');
        if (!in_array($order['status'], ['draft', 'ordered'], true)) {
            throw new ConflictException('Nur Entwürfe und bestellte, noch nicht gelieferte Bestellungen können storniert werden.');
        }
        if ((int) $order['quantity_received'] > 0) {
            throw new ConflictException('Zu dieser Bestellung wurde bereits Ware geliefert.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::single('reason', 'Bitte einen Grund angeben.');
        }
        $this->orders->update($id, ['status' => 'cancelled', 'note' => trim(($order['note'] ?? '') . "\nStorniert: " . $reason)]);
        $this->audit->log('cancel', 'purchase_order', $id, (string) $order['order_number'], ['status' => $order['status']], ['status' => 'cancelled', 'reason' => $reason]);
    }

    /** Vollständig geliefert → Abgeschlossen (z. B. nach Rechnungsprüfung). @param array<string,mixed> $order */
    public function close(int $id, array $order): void
    {
        $this->currentUser->require('orders.manage');
        if ($order['status'] !== 'delivered') {
            throw new ConflictException('Nur vollständig gelieferte Bestellungen können abgeschlossen werden.');
        }
        $this->orders->update($id, ['status' => 'closed']);
        $this->audit->log('status', 'purchase_order', $id, (string) $order['order_number'], ['status' => 'delivered'], ['status' => 'closed']);
    }

    /** Abgeschlossen → Vollständig geliefert (wieder öffnen). @param array<string,mixed> $order */
    public function reopen(int $id, array $order): void
    {
        $this->currentUser->require('orders.manage');
        if ($order['status'] !== 'closed') {
            throw new ConflictException('Nur abgeschlossene Bestellungen können wieder geöffnet werden.');
        }
        $this->orders->update($id, ['status' => 'delivered']);
        $this->audit->log('status', 'purchase_order', $id, (string) $order['order_number'], ['status' => 'closed'], ['status' => 'delivered']);
    }

    // ------------------------------------------------------------------ Positionen

    /** @param array<string,mixed> $order @param array<string,mixed> $input */
    public function addItem(int $orderId, array $order, array $input): int
    {
        $this->currentUser->require('orders.manage');
        $this->assertEditable($order);
        $data = $this->validateItem($input, null);
        $data['purchase_order_id'] = $orderId;
        $data['position'] = $this->orders->nextPosition($orderId);
        $id = $this->orders->createItem($data);
        $this->audit->log('create', 'purchase_order_item', $id, $order['order_number'] . ' / ' . $data['description'], null, $data);

        return $id;
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $item @param array<string,mixed> $input */
    public function updateItem(array $order, array $item, array $input): void
    {
        $this->currentUser->require('orders.manage');
        $this->assertEditable($order);
        $data = $this->validateItem($input, $item);
        $this->orders->updateItem((int) $item['id'], $data);
        $this->audit->log('update', 'purchase_order_item', (int) $item['id'], $order['order_number'] . ' / ' . $data['description'], $item, $data);
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $item */
    public function deleteItem(array $order, array $item): void
    {
        $this->currentUser->require('orders.manage');
        $this->assertEditable($order);
        if ((int) $item['quantity_received'] > 0) {
            throw new ConflictException('Zu dieser Position wurde bereits Ware geliefert; sie kann nicht gelöscht werden.');
        }
        $this->orders->deleteItem((int) $item['id']);
        $this->audit->log('delete', 'purchase_order_item', (int) $item['id'], $order['order_number'] . ' / ' . $item['description'], $item, null);
    }

    // ------------------------------------------------------------------ Wareneingang

    /**
     * Bucht eine (Teil-)Lieferung: erhöht gelieferte Mengen, legt je Einheit ein Asset an
     * und setzt den Bestellstatus. Alles in einer Transaktion – bei Fehlern wird nichts gebucht.
     *
     * @param array<string,mixed> $order Detail-Datensatz (find)
     * @param array<string,mixed> $input received_at, delivery_note_number, location_id, note,
     *        items[<itemId>][quantity], items[<itemId>][serials][], items[<itemId>][note]
     * @return array{receipt_id:int, asset_ids:array<int,int>, quantity:int}
     */
    public function receive(int $orderId, array $order, array $input): array
    {
        $this->currentUser->require('orders.receive');
        if (!in_array($order['status'], self::RECEIVABLE, true)) {
            throw new ConflictException('Für diese Bestellung kann kein Wareneingang gebucht werden (Status: ' . (self::STATUSES[$order['status']] ?? $order['status']) . ').');
        }
        $v = (new Validator($input))
            ->date('received_at', 'Lieferdatum', true)
            ->string('delivery_note_number', 'Lieferscheinnummer', false, 100)
            ->id('location_id', 'Lagerort')
            ->text('note', 'Bemerkung', false, 2000);
        $head = $v->validated();
        if ($head['received_at'] > date('Y-m-d')) {
            throw ValidationException::single('received_at', 'Das Lieferdatum darf nicht in der Zukunft liegen.');
        }
        $location = null;
        if ($head['location_id'] !== null) {
            $location = $this->locations->find((int) $head['location_id']);
            if ($location === null || (int) $location['is_active'] !== 1) {
                throw ValidationException::single('location_id', 'Der Lagerort existiert nicht oder ist deaktiviert.');
            }
        }

        // Positionen prüfen
        $items = [];
        foreach ($this->orders->items($orderId) as $item) {
            $items[(int) $item['id']] = $item;
        }
        $lines = [];
        $errors = [];
        $seenSerials = [];
        foreach ((array) ($input['items'] ?? []) as $itemId => $line) {
            $itemId = (int) $itemId;
            $item = $items[$itemId] ?? null;
            if ($item === null || !is_array($line)) {
                continue;
            }
            $qtyRaw = trim((string) ($line['quantity'] ?? ''));
            $qty = $qtyRaw === '' ? 0 : (int) $qtyRaw;
            if ($qtyRaw !== '' && (!ctype_digit($qtyRaw) || $qty < 0)) {
                $errors["items.{$itemId}.quantity"] = 'Bitte eine ganze Zahl eingeben.';
                continue;
            }
            if ($qty === 0) {
                continue;
            }
            if ($qty > (int) $item['quantity_open']) {
                $errors["items.{$itemId}.quantity"] = 'Es sind nur noch ' . $item['quantity_open'] . ' Stück offen.';
                continue;
            }
            $serials = [];
            if ((int) $item['creates_assets'] === 1) {
                foreach (array_values((array) ($line['serials'] ?? [])) as $i => $serial) {
                    $serial = trim((string) $serial);
                    if ($i >= $qty) {
                        break;
                    }
                    $serials[$i] = $serial === '' ? null : $serial;
                    if ($serial !== '') {
                        $norm = AssetService::normalizeSerial($serial);
                        $typeId = (int) $item['effective_asset_type_id'];
                        if ($norm !== null && isset($seenSerials[$typeId . ':' . $norm])) {
                            $errors["items.{$itemId}.serials.{$i}"] = 'Diese Seriennummer wurde in dieser Lieferung mehrfach eingegeben.';
                        }
                        $seenSerials[$typeId . ':' . $norm] = true;
                    }
                }
                if ((int) $item['effective_asset_type_id'] === 0) {
                    $errors["items.{$itemId}.quantity"] = 'Für diese Position ist kein Assettyp hinterlegt; bitte zuerst die Position bearbeiten.';
                }
            }
            $lines[] = ['item' => $item, 'quantity' => $qty, 'serials' => $serials, 'note' => trim((string) ($line['note'] ?? '')) ?: null];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($lines === []) {
            throw ValidationException::single('items', 'Bitte mindestens eine gelieferte Menge eingeben.');
        }

        return $this->orders->transaction(function () use ($orderId, $order, $head, $location, $lines): array {
            $receiptId = $this->orders->createReceipt([
                'purchase_order_id' => $orderId,
                'received_at' => $head['received_at'],
                'delivery_note_number' => $head['delivery_note_number'],
                'location_id' => $location !== null ? (int) $location['id'] : null,
                'received_by' => $this->currentUser->id(),
                'note' => $head['note'],
            ]);
            $assetIds = [];
            $total = 0;
            foreach ($lines as $line) {
                $item = $line['item'];
                $this->orders->createReceiptItem([
                    'goods_receipt_id' => $receiptId,
                    'purchase_order_item_id' => (int) $item['id'],
                    'quantity' => $line['quantity'],
                    'note' => $line['note'],
                ]);
                $this->orders->addReceived((int) $item['id'], $line['quantity']);
                $total += $line['quantity'];
                if ($item['article_id'] !== null && (int) ($item['is_consumable'] ?? 0) === 1) {
                    $this->articles->addStock((int) $item['article_id'], $line['quantity']);
                }
                if ((int) $item['creates_assets'] !== 1 || (int) ($item['is_consumable'] ?? 0) === 1) {
                    continue;
                }
                for ($i = 0; $i < $line['quantity']; $i++) {
                    $assetIds[] = $this->createAssetFromItem($order, $item, $receiptId, $head, $location, $line['serials'][$i] ?? null, $i);
                }
            }
            $newStatus = $this->deliveryStatus($orderId);
            if ($newStatus !== $order['status']) {
                $this->orders->update($orderId, ['status' => $newStatus]);
            }
            $this->audit->log('receive', 'purchase_order', $orderId, (string) $order['order_number'], ['status' => $order['status']], [
                'status' => $newStatus, 'receipt_id' => $receiptId, 'quantity' => $total, 'assets' => $assetIds,
            ]);

            return ['receipt_id' => $receiptId, 'asset_ids' => $assetIds, 'quantity' => $total];
        });
    }

    /** @return array<string,mixed>|null */
    public function findItemOf(int $orderId, int $itemId): ?array
    {
        $item = $this->orders->findItem($itemId);

        return $item !== null && (int) $item['purchase_order_id'] === $orderId ? $item : null;
    }

    // ------------------------------------------------------------------ intern

    /**
     * @param array<string,mixed> $order @param array<string,mixed> $item @param array<string,mixed> $head @param array<string,mixed>|null $location
     */
    private function createAssetFromItem(array $order, array $item, int $receiptId, array $head, ?array $location, ?string $serial, int $index): int
    {
        $data = [
            'asset_type_id' => (string) $item['effective_asset_type_id'],
            'article_id' => $item['article_id'] !== null ? (string) $item['article_id'] : '',
            'name' => $item['article_id'] === null ? (string) $item['description'] : '',
            'serial_number' => $serial ?? '',
            'purchase_date' => (string) $head['received_at'],
            'supplier_id' => (string) $order['supplier_id'],
            'purchase_order_id' => (string) $order['id'],
            'purchase_order_item_id' => (string) $item['id'],
            'goods_receipt_id' => (string) $receiptId,
            'purchase_price' => $item['unit_price'] !== null ? (string) $item['unit_price'] : '',
            'location_id' => $location !== null ? (string) $location['id'] : '',
            'cost_center_id' => $order['cost_center_id'] !== null ? (string) $order['cost_center_id'] : '',
        ];
        try {
            $result = $this->assetService->create($data);
        } catch (ValidationException $e) {
            // Fehler auf das Seriennummernfeld der Position abbilden, damit das Formular sie anzeigen kann
            $mapped = [];
            foreach ($e->errors() as $field => $message) {
                $mapped[$field === 'serial_number' ? "items.{$item['id']}.serials.{$index}" : "items.{$item['id']}.quantity"] = 'Position ' . $item['position'] . ': ' . $message;
            }
            throw new ValidationException($mapped);
        }
        $this->assetService->addEvent(
            $result['id'],
            'goods_receipt',
            'Bestellung ' . $order['order_number'] . ($head['delivery_note_number'] ? ', Lieferschein ' . $head['delivery_note_number'] : ''),
            'Wareneingang vom ' . date('d.m.Y', strtotime((string) $head['received_at'])),
            $receiptId
        );

        return $result['id'];
    }

    /** Ermittelt den Lieferstatus aus den Positionsmengen. */
    private function deliveryStatus(int $orderId): string
    {
        $total = 0;
        $received = 0;
        foreach ($this->orders->items($orderId) as $item) {
            $total += (int) $item['quantity'];
            $received += (int) $item['quantity_received'];
        }

        return match (true) {
            $total > 0 && $received >= $total => 'delivered',
            $received > 0 => 'partially_delivered',
            default => 'ordered',
        };
    }

    /** @param array<string,mixed> $order */
    private function assertEditable(array $order): void
    {
        if (!in_array($order['status'], self::EDITABLE, true)) {
            throw new ConflictException('Die Bestellung ist im Status „' . (self::STATUSES[$order['status']] ?? $order['status']) . '“ und kann nicht mehr bearbeitet werden.');
        }
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing @return array<string,mixed> */
    private function validateHeader(array $input, ?array $existing): array
    {
        $data = (new Validator($input))
            ->string('order_number', 'Bestellnummer', false, 60)
            ->id('supplier_id', 'Lieferant', true)
            ->date('order_date', 'Bestelldatum')
            ->string('ordered_by_name', 'Besteller', false, 200)
            ->date('expected_delivery_date', 'Erwartetes Lieferdatum')
            ->id('cost_center_id', 'Kostenstelle')
            ->text('note', 'Bemerkung', false, 5000)
            ->validated();
        $excludeId = $existing !== null ? (int) $existing['id'] : null;

        if ($data['order_number'] !== null) {
            $data['order_number'] = strtoupper(preg_replace('/\s+/', '', $data['order_number']) ?? '');
            if ($this->orders->findByNumber($data['order_number'], $excludeId) !== null) {
                throw ValidationException::single('order_number', 'Diese Bestellnummer ist bereits vergeben.');
            }
        }
        $supplier = $this->suppliers->find((int) $data['supplier_id']);
        if ($supplier === null || ((int) $supplier['is_active'] !== 1 && ($existing === null || (int) $existing['supplier_id'] !== (int) $supplier['id']))) {
            throw ValidationException::single('supplier_id', 'Der Lieferant existiert nicht oder ist deaktiviert.');
        }
        if ($data['cost_center_id'] !== null) {
            $cc = $this->costCenters->find((int) $data['cost_center_id']);
            if ($cc === null || (int) $cc['is_active'] !== 1) {
                throw ValidationException::single('cost_center_id', 'Die Kostenstelle existiert nicht oder ist deaktiviert.');
            }
        }
        if ($data['order_date'] !== null && $data['expected_delivery_date'] !== null && $data['expected_delivery_date'] < $data['order_date']) {
            throw ValidationException::single('expected_delivery_date', 'Das Lieferdatum darf nicht vor dem Bestelldatum liegen.');
        }
        if ($data['ordered_by_name'] !== null && $existing !== null) {
            $data['ordered_by_user_id'] = $data['ordered_by_name'] === $existing['ordered_by_display'] ? $existing['ordered_by_user_id'] : null;
        }

        return $data;
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing @return array<string,mixed> */
    private function validateItem(array $input, ?array $existing): array
    {
        $data = (new Validator($input))
            ->id('article_id', 'Artikel')
            ->id('asset_type_id', 'Assettyp')
            ->string('description', 'Bezeichnung', false, 255)
            ->int('quantity', 'Menge', true, 1, 10000)
            ->decimal('unit_price', 'Einzelpreis', false, 0.0)
            ->bool('creates_assets')
            ->string('note', 'Bemerkung', false, 255)
            ->validated();

        if ($data['article_id'] !== null) {
            $article = $this->articles->find((int) $data['article_id']);
            if ($article === null) {
                throw ValidationException::single('article_id', 'Artikel nicht gefunden.');
            }
            $data['asset_type_id'] = (int) $article['asset_type_id'];
            $data['description'] ??= trim($article['manufacturer_name'] . ' ' . $article['name']);
            if ((int) ($article['is_consumable'] ?? 0) === 1) {
                $data['creates_assets'] = 0;
            }
        }
        if ($data['description'] === null) {
            throw ValidationException::single('description', 'Bitte eine Bezeichnung angeben oder einen Artikel wählen.');
        }
        if ($data['asset_type_id'] !== null && $this->types->find((int) $data['asset_type_id']) === null) {
            throw ValidationException::single('asset_type_id', 'Assettyp nicht gefunden.');
        }
        if ((int) $data['creates_assets'] === 1 && $data['article_id'] === null) {
            throw ValidationException::single('article_id', 'Positionen, die Assets erzeugen, benötigen einen Stammartikel – bitte einen Artikel wählen oder zuerst anlegen.');
        }
        if ((int) $data['creates_assets'] === 1 && $data['asset_type_id'] === null) {
            throw ValidationException::single('asset_type_id', 'Positionen, die Assets erzeugen, benötigen einen Assettyp (oder einen Artikel).');
        }
        if ($existing !== null && (int) $data['quantity'] < (int) $existing['quantity_received']) {
            throw ValidationException::single('quantity', 'Es wurden bereits ' . $existing['quantity_received'] . ' Stück geliefert; die Menge kann nicht darunter liegen.');
        }

        return $data;
    }
}
