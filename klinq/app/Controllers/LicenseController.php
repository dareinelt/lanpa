<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\CostCenterRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\LicenseService;
use App\Support\Paginator;
use App\Support\Url;

/** Lizenzverwaltung: Liste, Detail, Zuordnung zu Assets, Dokumente. */
final class LicenseController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly LicenseRepository $licenses,
        private readonly LicenseService $service,
        private readonly ManufacturerRepository $manufacturers,
        private readonly SupplierRepository $suppliers,
        private readonly CostCenterRepository $costCenters,
        private readonly PurchaseOrderRepository $orders,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $documentService
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->queryString('q'),
            'manufacturer_id' => $request->queryString('manufacturer_id'),
            'supplier_id' => $request->queryString('supplier_id'),
            'status' => $request->queryString('status', 'active'),
            'expiring' => $request->queryString('expiring'),
            'purchase_order_id' => $request->queryString('purchase_order_id'),
            'asset_id' => $request->queryString('asset_id'),
        ];
        $paginator = new Paginator($this->licenses->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('licenses.index', [
            'title' => 'Lizenzen',
            'activeNav' => 'licenses',
            'rows' => $this->licenses->search($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'counts' => $this->licenses->statusCounts(),
            'totals' => $this->licenses->totals(),
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'suppliers' => $this->suppliers->activeForSelect(),
            'paginator' => $paginator,
            'basePath' => '/licenses',
            'query' => $request->query(),
            'expiryLabels' => LicenseService::EXPIRY_LABELS,
            'expiryColors' => LicenseService::EXPIRY_COLORS,
            'expiringDays' => LicenseRepository::EXPIRING_DAYS,
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = [];
        foreach (['manufacturer_id', 'supplier_id', 'purchase_order_id', 'cost_center_id'] as $key) {
            if ($request->queryString($key) !== '') {
                $prefill[$key] = $request->queryString($key);
            }
        }

        return $this->renderForm(null, $prefill);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service->create($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/licenses/new');
        }
        $this->flash('success', 'Lizenz angelegt.');

        return $this->redirect('/licenses/' . $id);
    }

    public function show(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->licenses->find($id), 'Lizenz nicht gefunden');

        return $this->render('licenses.show', [
            'title' => $this->service->label($row),
            'activeNav' => 'licenses',
            'row' => $row,
            'assignments' => $this->licenses->assignments($id),
            'documents' => $this->documents->forEntity('license', $id),
            'documentTypes' => DocumentService::UPLOAD_TYPES,
            'expiryLabels' => LicenseService::EXPIRY_LABELS,
            'expiryColors' => LicenseService::EXPIRY_COLORS,
            'showKey' => $request->queryString('show_key') === '1',
        ]);
    }

    public function edit(Request $request): Response
    {
        $row = $this->findOrFail($this->licenses->find($request->paramInt('id')), 'Lizenz nicht gefunden');

        return $this->renderForm($row, []);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->licenses->find($id), 'Lizenz nicht gefunden');
        try {
            $this->service->update($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/licenses/' . $id . '/edit');
        }
        $this->flash('success', 'Lizenz gespeichert.');

        return $this->redirect('/licenses/' . $id);
    }

    public function toggle(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->licenses->find($id), 'Lizenz nicht gefunden');
        $active = $request->string('active') === '1';
        $this->service->setActive($id, $row, $active);
        $this->flash('success', $active ? 'Lizenz aktiviert.' : 'Lizenz deaktiviert.');

        return $this->redirect('/licenses/' . $id);
    }

    // ------------------------------------------------------------------ Zuordnung

    public function assign(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->licenses->find($id), 'Lizenz nicht gefunden');
        try {
            $this->service->assign($row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/licenses/' . $id . '#assign');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/licenses/' . $id . '#assign');
        }
        $this->flash('success', 'Lizenz dem Asset zugeordnet.');

        return $this->redirect('/licenses/' . $id . '#assignments');
    }

    public function release(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->licenses->find($id), 'Lizenz nicht gefunden');
        try {
            $this->service->release($row, $request->paramInt('assignment'));
            $this->flash('success', 'Zuordnung aufgehoben – die Einheit ist wieder verfügbar.');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());
        }
        $back = $request->string('back');

        return $this->redirect(Url::safeLocalPath($back, '/licenses/' . $id . '#assignments'));
    }

    // ------------------------------------------------------------------ Dokumente

    public function uploadDocument(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->licenses->find($id), 'Lizenz nicht gefunden');
        $this->currentUser->require('documents.manage');
        $type = $request->string('document_type', 'license');
        if (!isset(DocumentService::UPLOAD_TYPES[$type])) {
            $type = 'license';
        }
        $file = $request->file('file');
        if ($file === null) {
            $this->flash('error', 'Bitte eine Datei auswählen.');

            return $this->redirect('/licenses/' . $id . '#documents');
        }
        try {
            $this->documentService->store('license', $id, $type, $file, $request->stringOrNull('note'));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/licenses/' . $id . '#documents');
        }
        $this->flash('success', 'Dokument „' . $file['name'] . '“ zur Lizenz ' . $this->service->label($row) . ' hochgeladen.');

        return $this->redirect('/licenses/' . $id . '#documents');
    }

    // ------------------------------------------------------------------ intern

    /** @param array<string,mixed>|null $row @param array<string,mixed> $prefill */
    private function renderForm(?array $row, array $prefill): Response
    {
        return $this->render('licenses.form', [
            'title' => $row === null ? 'Neue Lizenz' : 'Lizenz bearbeiten',
            'activeNav' => 'licenses',
            'row' => $row,
            'prefill' => $prefill,
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'suppliers' => $this->suppliers->activeForSelect(),
            'costCenters' => $this->costCenters->activeForSelect(),
            'orders' => $this->orders->forSelect(),
            'licenseTypes' => LicenseService::LICENSE_TYPES,
        ]);
    }
}
