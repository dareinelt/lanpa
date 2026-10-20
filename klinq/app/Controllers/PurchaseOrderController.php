<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\LocationRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\LocationService;
use App\Services\PurchaseOrderService;
use App\Support\Paginator;

/** Einkauf: Bestellungen, Positionen, Wareneingang, Dokumente. */
final class PurchaseOrderController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly PurchaseOrderRepository $orders,
        private readonly PurchaseOrderService $service,
        private readonly SupplierRepository $suppliers,
        private readonly CostCenterRepository $costCenters,
        private readonly ArticleRepository $articles,
        private readonly AssetTypeRepository $types,
        private readonly LocationRepository $locations,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $documentService
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->queryString('q'),
            'supplier_id' => $request->queryString('supplier_id'),
            'cost_center_id' => $request->queryString('cost_center_id'),
            'status' => $request->queryString('status', 'open'),
            'date_from' => $request->queryString('date_from'),
            'date_to' => $request->queryString('date_to'),
        ];
        $paginator = new Paginator($this->orders->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('orders.index', [
            'title' => 'Bestellungen',
            'activeNav' => 'orders',
            'rows' => $this->orders->search($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'counts' => $this->orders->statusCounts(),
            'suppliers' => $this->suppliers->activeForSelect(),
            'costCenters' => $this->costCenters->activeForSelect(),
            'paginator' => $paginator,
            'basePath' => '/orders',
            'query' => $request->query(),
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = ['supplier_id' => $request->queryString('supplier_id')];

        return $this->renderForm(null, $prefill);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service->create($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/orders/new');
        }
        $this->flash('success', 'Bestellung angelegt. Bitte Positionen hinzufügen.');

        return $this->redirect('/orders/' . $id);
    }

    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $editItem = $request->int('edit_item');
        $items = $this->orders->items($id);
        $editing = null;
        foreach ($items as $item) {
            if ($editItem !== null && (int) $item['id'] === $editItem) {
                $editing = $item;
            }
        }

        return $this->render('orders.show', [
            'title' => 'Bestellung ' . $row['order_number'],
            'activeNav' => 'orders',
            'row' => $row,
            'items' => $items,
            'editing' => $editing,
            'receipts' => $this->orders->receipts($id),
            'assets' => $this->orders->assetsFor($id),
            'documents' => $this->documents->forEntity('purchase_order', $id),
            'articles' => $this->articles->activeForSelect(),
            'types' => $this->types->all(true),
            'documentTypes' => DocumentService::UPLOAD_TYPES,
            'statuses' => PurchaseOrderService::STATUSES,
            'statusColors' => PurchaseOrderService::STATUS_COLORS,
        ]);
    }

    public function edit(Request $request): Response
    {
        $row = $this->findOrFail($this->orders->find($request->paramInt('id')), 'Bestellung nicht gefunden');

        return $this->renderForm($row, []);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        try {
            $this->service->update($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/orders/' . $id . '/edit');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/orders/' . $id);
        }
        $this->flash('success', 'Bestellung gespeichert.');

        return $this->redirect('/orders/' . $id);
    }

    /** Statuswechsel: order | cancel | close | reopen */
    public function status(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $action = $request->string('action');
        try {
            match ($action) {
                'order' => $this->service->markOrdered($id, $row),
                'cancel' => $this->service->cancel($id, $row, $request->string('reason')),
                'close' => $this->service->close($id, $row),
                'reopen' => $this->service->reopen($id, $row),
                default => throw new ConflictException('Unbekannte Aktion.'),
            };
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/orders/' . $id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/orders/' . $id);
        }
        $this->flash('success', match ($action) {
            'order' => 'Die Bestellung ist jetzt als bestellt markiert.',
            'cancel' => 'Die Bestellung wurde storniert.',
            'close' => 'Die Bestellung wurde abgeschlossen.',
            default => 'Die Bestellung wurde wieder geöffnet.',
        });

        return $this->redirect('/orders/' . $id);
    }

    // ------------------------------------------------------------------ Positionen

    public function storeItem(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        try {
            $this->service->addItem($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/orders/' . $id . '#items');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/orders/' . $id);
        }
        $this->flash('success', 'Position hinzugefügt.');

        return $this->redirect('/orders/' . $id . '#items');
    }

    public function updateItem(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $item = $this->findOrFail($this->service->findItemOf($id, $request->paramInt('item')), 'Position nicht gefunden');
        try {
            $this->service->updateItem($row, $item, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/orders/' . $id . '?edit_item=' . $item['id'] . '#items');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/orders/' . $id);
        }
        $this->flash('success', 'Position gespeichert.');

        return $this->redirect('/orders/' . $id . '#items');
    }

    public function deleteItem(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $item = $this->findOrFail($this->service->findItemOf($id, $request->paramInt('item')), 'Position nicht gefunden');
        try {
            $this->service->deleteItem($row, $item);
            $this->flash('success', 'Position gelöscht.');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/orders/' . $id . '#items');
    }

    // ------------------------------------------------------------------ Wareneingang

    public function receiveForm(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $items = array_values(array_filter($this->orders->items($id), static fn (array $i): bool => (int) $i['quantity_open'] > 0));
        if ($items === [] && !in_array($row['status'], ['ordered', 'partially_delivered'], true)) {
            $this->flash('error', 'Für diese Bestellung kann kein Wareneingang gebucht werden.');

            return $this->redirect('/orders/' . $id);
        }

        return $this->render('orders.receive', [
            'title' => 'Wareneingang ' . $row['order_number'],
            'activeNav' => 'orders',
            'row' => $row,
            'items' => $items,
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'defaultLocationId' => $this->defaultStockLocation(),
        ]);
    }

    public function receive(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        try {
            $result = $this->service->receive($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/orders/' . $id . '/receive');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/orders/' . $id);
        }
        $this->flash('success', sprintf('Wareneingang gebucht: %d Stück, %d Assets angelegt.', $result['quantity'], count($result['asset_ids'])));

        return $this->redirect('/orders/' . $id . '/receipts/' . $result['receipt_id']);
    }

    public function receipt(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $receipt = $this->findOrFail($this->orders->findReceipt($request->paramInt('receipt')), 'Wareneingang nicht gefunden');
        if ((int) $receipt['purchase_order_id'] !== $id) {
            return $this->redirect('/orders/' . $id);
        }
        $assets = $this->orders->assetsFor($id, (int) $receipt['id']);

        return $this->render('orders.receipt', [
            'title' => 'Wareneingang ' . date('d.m.Y', strtotime((string) $receipt['received_at'])) . ' · ' . $row['order_number'],
            'activeNav' => 'orders',
            'row' => $row,
            'receipt' => $receipt,
            'lines' => $this->orders->receiptItems((int) $receipt['id']),
            'assets' => $assets,
            'assetIds' => array_map(static fn (array $a): int => (int) $a['id'], $assets),
        ]);
    }

    // ------------------------------------------------------------------ Dokumente

    public function uploadDocument(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->orders->find($id), 'Bestellung nicht gefunden');
        $this->currentUser->require('documents.manage');
        $type = $request->string('document_type', 'other');
        if (!isset(DocumentService::UPLOAD_TYPES[$type])) {
            $type = 'other';
        }
        $file = $request->file('file');
        if ($file === null) {
            $this->flash('error', 'Bitte eine Datei auswählen.');

            return $this->redirect('/orders/' . $id . '#documents');
        }
        try {
            $this->documentService->store('purchase_order', $id, $type, $file, $request->stringOrNull('note'));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/orders/' . $id . '#documents');
        }
        $this->flash('success', 'Dokument „' . $file['name'] . '“ zu Bestellung ' . $row['order_number'] . ' hochgeladen.');

        return $this->redirect('/orders/' . $id . '#documents');
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed>|null $row @param array<string,string> $prefill */
    private function renderForm(?array $row, array $prefill): Response
    {
        if ($row !== null && !in_array($row['status'], ['draft', 'ordered'], true)) {
            $this->flash('error', 'Die Bestellung kann im aktuellen Status nicht bearbeitet werden.');

            return $this->redirect('/orders/' . $row['id']);
        }
        if ($row === null && !isset($_SESSION['_old_input'])) {
            $row = array_merge(['id' => null, 'order_date' => date('Y-m-d'), 'ordered_by_display' => $this->currentUser->displayName()], array_filter($prefill));
            $row['is_new'] = true;
        }

        return $this->render('orders.form', [
            'title' => $row === null || !empty($row['is_new']) ? 'Neue Bestellung' : 'Bestellung ' . $row['order_number'] . ' bearbeiten',
            'activeNav' => 'orders',
            'row' => $row,
            'suppliers' => $this->suppliers->activeForSelect(),
            'costCenters' => $this->costCenters->activeForSelect(),
            'nextNumber' => $this->orders->nextOrderNumber(),
        ]);
    }

    private function defaultStockLocation(): ?int
    {
        foreach ($this->locations->all(true) as $l) {
            if (($l['type'] ?? '') === 'warehouse' || stripos((string) $l['name'], 'lager') !== false) {
                return (int) $l['id'];
            }
        }

        return null;
    }
}
