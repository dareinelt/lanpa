<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetHistoryRepository;
use App\Repositories\AssetRepository;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LicenseRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\MovementRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\AssetService;
use App\Services\InventoryNumberService;
use App\Services\LocationService;
use App\Support\Paginator;

final class AssetController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AssetRepository $assets,
        private readonly AssetTypeRepository $types,
        private readonly AssetStatusRepository $statuses,
        private readonly AssetHistoryRepository $history,
        private readonly ManufacturerRepository $manufacturers,
        private readonly ArticleRepository $articles,
        private readonly SupplierRepository $suppliers,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly EmployeeRepository $employees,
        private readonly MovementRepository $movements,
        private readonly LicenseRepository $licenses,
        private readonly AssetService $service,
        private readonly InventoryNumberService $numbers,
        private readonly ?TicketRepository $tickets = null,
        private readonly bool $helpdeskEnabled = true
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $sort = $request->queryString('sort', 'inventory_number');
        $dir = $request->queryString('dir', 'asc');
        $paginator = new Paginator($this->assets->countSearch($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render('assets.index', [
            'title' => 'Assets',
            'activeNav' => 'assets',
            'rows' => $this->assets->search($filters, $paginator->perPage, $paginator->offset(), $sort, $dir),
            'filters' => $filters,
            'paginator' => $paginator,
            'basePath' => '/assets',
            'query' => $request->query(),
            'types' => $this->types->all(true),
            'statuses' => $this->statuses->all(true),
            'statusCounts' => $this->assets->countsByStatus(),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'filterContext' => $this->filterContext($filters),
        ]);
    }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->assets->find($request->paramInt('id')), 'Asset nicht gefunden');

        return $this->render('assets.show', [
            'title' => $row['inventory_number'],
            'activeNav' => 'assets',
            'row' => $row,
            'history' => $this->history->forAsset((int) $row['id']),
            'children' => $this->assets->children((int) $row['id']),
            'movements' => $this->movements->forAsset((int) $row['id'], 10),
            'licenses' => $this->licenses->forAsset((int) $row['id']),
            'tickets' => $this->tickets !== null && $this->helpdeskEnabled && $this->currentUser->can('helpdesk.view') ? $this->tickets->forAsset((int) $row['id'], 10) : null,
            'statuses' => $this->statuses->all(true),
            'fieldLabels' => AssetService::FIELD_LABELS,
        ]);
    }

    public function create(Request $request): Response
    {
        $prefill = [];
        foreach (['asset_type_id', 'article_id', 'employee_id', 'location_id', 'cost_center_id', 'supplier_id', 'purchase_order_id'] as $key) {
            if ($request->queryString($key) !== '') {
                $prefill[$key] = $request->queryString($key);
            }
        }

        return $this->renderForm($request, null, $prefill);
    }

    public function store(Request $request): Response
    {
        try {
            $result = $this->service->create($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/assets/new');
        }
        $this->flash('success', 'Asset ' . $result['inventory_number'] . ' wurde angelegt.');
        if ($request->string('save_and_new') === '1') {
            return $this->redirect('/assets/new?' . http_build_query(array_filter([
                'asset_type_id' => $request->string('asset_type_id'),
                'article_id' => $request->string('article_id'),
                'supplier_id' => $request->string('supplier_id'),
                'location_id' => $request->string('location_id'),
                'cost_center_id' => $request->string('cost_center_id'),
            ])));
        }

        return $this->redirect('/assets/' . $result['id']);
    }

    public function edit(Request $request): Response
    {
        $row = $this->findOrFail($this->assets->find($request->paramInt('id')), 'Asset nicht gefunden');

        return $this->renderForm($request, $row);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->assets->find($id), 'Asset nicht gefunden');
        try {
            $this->service->update($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/assets/' . $id . '/edit');
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/assets/' . $id . '/edit');
        }
        $this->flash('success', 'Asset ' . $row['inventory_number'] . ' wurde gespeichert.');

        return $this->redirect('/assets/' . $id);
    }

    public function changeStatus(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->assets->find($id), 'Asset nicht gefunden');
        try {
            $this->service->changeStatus($id, $row, $request->string('status'), $request->stringOrNull('note'));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/assets/' . $id);
        }
        $this->flash('success', 'Status von ' . $row['inventory_number'] . ' wurde geändert.');

        return $this->redirect('/assets/' . $id);
    }

    public function addNote(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->assets->find($id), 'Asset nicht gefunden');
        try {
            $this->service->addNote($id, $row, $request->string('note'));
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/assets/' . $id);
        }
        $this->flash('success', 'Kommentar wurde zur Historie hinzugefügt.');

        return $this->redirect('/assets/' . $id . '#history');
    }

    /** JSON-Suche für Auswahlfelder und Scanner (Inventarnummer, Seriennummer, Bezeichnung). */
    public function search(Request $request): Response
    {
        $rows = $this->assets->quickSearch($request->queryString('q'), 20);

        return $this->json(['items' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'inventory_number' => $r['inventory_number'],
            'name' => $r['name'] ?? $r['article_name'] ?? '',
            'label' => trim($r['inventory_number'] . ' · ' . ($r['name'] ?? $r['article_name'] ?? ''), ' ·'),
            'meta' => implode(' · ', array_filter([$r['asset_type_name'], $r['status_name'], $r['employee_name'] ?? null, $r['location_path'] ?? null])),
            'type' => $r['asset_type_name'],
            'status' => $r['status_name'],
            'status_code' => $r['status_code'],
            'employee' => $r['employee_name'],
            'location' => $r['location_path'],
            'url' => '/assets/' . (int) $r['id'],
        ], $rows)]);
    }

    /** Zeigt die zuletzt vergebene Inventarnummer für ein Präfix, ohne die Sequenz zu verändern. */
    public function lastInventoryNumber(Request $request): Response
    {
        $prefix = trim($request->queryString('prefix'));
        $last = null;
        $next = null;
        if ($prefix !== '') {
            try {
                $last = $this->numbers->last($prefix);
                $next = $this->numbers->peekNext($prefix);
            } catch (\InvalidArgumentException $e) {
                $last = null;
                $next = null;
            }
        }

        return $this->json(['last_number' => $last, 'next_number' => $next]);
    }

    /** Live-Dublettenprüfung im Formular (Seriennummer / MAC / IMEI). */
    public function check(Request $request): Response
    {
        $field = $request->queryString('field');
        $value = trim($request->queryString('value'));
        $exclude = $request->int('exclude');
        $typeId = $request->int('asset_type_id');
        $hits = [];
        $normalized = $value;
        if ($value !== '') {
            switch ($field) {
                case 'serial_number':
                    $normalized = AssetService::normalizeSerial($value) ?? '';
                    if ($normalized !== '') {
                        if ($typeId && ($dup = $this->assets->findBySerial($typeId, $normalized, $exclude))) {
                            $hits[] = ['level' => 'error', 'asset' => $dup, 'message' => 'Seriennummer im Assettyp bereits vergeben'];
                        }
                        foreach ($typeId ? $this->assets->findSerialElsewhere($typeId, $normalized, $exclude) : [] as $dup) {
                            $hits[] = ['level' => 'warning', 'asset' => $dup, 'message' => 'Gleiche Seriennummer bei ' . $dup['asset_type_name']];
                        }
                    }
                    break;
                case 'mac_address':
                    $normalized = AssetService::normalizeMac($value) ?? '';
                    if ($normalized !== '' && ($dup = $this->assets->findByUniqueField('mac_address', $normalized, $exclude))) {
                        $hits[] = ['level' => 'error', 'asset' => $dup, 'message' => 'MAC-Adresse bereits vergeben'];
                    }
                    break;
                case 'imei':
                    $normalized = AssetService::normalizeImei($value) ?? '';
                    if ($normalized !== '' && ($dup = $this->assets->findByUniqueField('imei', $normalized, $exclude))) {
                        $hits[] = ['level' => 'error', 'asset' => $dup, 'message' => 'IMEI bereits vergeben'];
                    }
                    break;
            }
        }

        return $this->json(['normalized' => $normalized, 'hits' => $hits]);
    }

    /** @return array<string,mixed> */
    /** Listenfilter aus der Query (auch für den Etikettendruck der gefilterten Menge). @return array<string,mixed> */
    public function filters(Request $request): array
    {
        $filters = [
            'q' => trim($request->queryString('q')),
            'status' => $request->query()['status'] ?? 'active',
            'warranty' => $request->queryString('warranty'),
        ];
        foreach (['asset_type_id', 'asset_category_id', 'manufacturer_id', 'article_id', 'supplier_id', 'location_id', 'cost_center_id', 'employee_id', 'purchase_order_id', 'parent_asset_id'] as $key) {
            $filters[$key] = $request->int($key);
        }
        if ($filters['location_id'] && $request->queryString('sub', '1') === '1') {
            $filters['location_ids'] = $this->locations->descendantIds($filters['location_id']);
        }

        return $filters;
    }

    /** Klartext für aktive Kontextfilter (z. B. „Mitarbeiter: Max Mustermann“). @return array<string,string> */
    private function filterContext(array $filters): array
    {
        $ctx = [];
        if (!empty($filters['employee_id']) && ($e = $this->employees->find((int) $filters['employee_id']))) {
            $ctx['employee_id'] = 'Mitarbeiter: ' . $e['display_name'];
        }
        if (!empty($filters['article_id']) && ($a = $this->articles->find((int) $filters['article_id']))) {
            $ctx['article_id'] = 'Artikel: ' . $a['manufacturer_name'] . ' ' . $a['name'];
        }
        if (!empty($filters['supplier_id']) && ($s = $this->suppliers->find((int) $filters['supplier_id']))) {
            $ctx['supplier_id'] = 'Lieferant: ' . $s['name'];
        }
        if (!empty($filters['parent_asset_id']) && ($p = $this->assets->find((int) $filters['parent_asset_id']))) {
            $ctx['parent_asset_id'] = 'Zugehörig zu: ' . $p['inventory_number'];
        }

        return $ctx;
    }

    /** @param array<string,mixed> $prefill */
    private function renderForm(Request $request, ?array $row, array $prefill = []): Response
    {
        if ($row === null && $prefill !== [] && empty($_SESSION['_old_input'])) {
            $_SESSION['_old_input'] = $prefill;
        }

        return $this->render('assets.form', [
            'title' => $row === null ? 'Asset anlegen' : 'Asset ' . $row['inventory_number'] . ' bearbeiten',
            'activeNav' => 'assets',
            'row' => $row,
            'types' => $this->types->all(true),
            'categories' => $this->types->categories(null, true),
            'statuses' => $this->statuses->all(true),
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'articles' => $this->articles->activeForSelect(),
            'suppliers' => $this->suppliers->activeForSelect(),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'employees' => $this->employees->activeForSelect(),
            'canRetire' => $this->currentUser->can('assets.retire'),
        ]);
    }
}
