<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AssetStatusRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Repositories\ManufacturerRepository;
use App\Repositories\ReportRepository;
use App\Security\CurrentUser;
use App\Services\DashboardService;
use App\Services\LocationService;
use App\Services\MovementService;
use App\Services\ReportService;
use App\Support\CsvWriter;
use App\Support\Paginator;

/** Berichte: Übersicht, Inventarliste, Bestand, Entnahmen, Retouren – jeweils mit CSV-Export. */
final class ReportController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly ReportService $service,
        private readonly ReportRepository $reports,
        private readonly DashboardService $dashboard,
        private readonly AssetTypeRepository $types,
        private readonly AssetStatusRepository $statuses,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly ManufacturerRepository $manufacturers,
        private readonly EmployeeRepository $employees
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $from = date('Y-m-d', strtotime('-' . ReportService::DEFAULT_PERIOD_DAYS . ' days'));
        $to = date('Y-m-d');

        return $this->render('reports.index', [
            'title' => 'Berichte',
            'activeNav' => 'reports',
            'reports' => ReportService::REPORTS,
            'stats' => $this->dashboard->stats(),
            'byStatus' => $this->reports->stockByStatus(),
            'byType' => $this->reports->stockByType(),
            'byMonth' => $this->reports->movementsByMonth($from, $to),
            'periodFrom' => $from,
            'periodTo' => $to,
        ]);
    }

    public function inventory(Request $request): Response
    {
        $filters = [
            'q' => $request->queryString('q'),
            'asset_type_id' => $request->queryString('asset_type_id'),
            'status' => $request->queryString('status', 'active'),
            'location_id' => $request->queryString('location_id'),
            'cost_center_id' => $request->queryString('cost_center_id'),
            'manufacturer_id' => $request->queryString('manufacturer_id'),
            'employee_id' => $request->queryString('employee_id'),
            'warranty' => $request->queryString('warranty'),
            'legacy' => $request->queryString('legacy'),
            'missing' => $request->queryString('missing'),
        ];
        if ($request->queryString('format') === 'csv') {
            return Response::download($this->service->inventoryCsv($filters), CsvWriter::MIME, CsvWriter::filename('inventarliste'));
        }
        $sort = $request->queryString('sort', 'inventory_number');
        $dir = $request->queryString('dir', 'asc');
        $paginator = new Paginator($this->service->inventoryCount($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 100) ?? 100);

        return $this->render('reports.inventory', [
            'title' => 'Inventarliste',
            'activeNav' => 'reports',
            'rows' => $this->service->inventory($filters, $paginator->perPage, $paginator->offset(), $sort, $dir),
            'filters' => $filters,
            'paginator' => $paginator,
            'basePath' => '/reports/inventory',
            'query' => $request->query(),
            'types' => $this->types->all(true),
            'statuses' => $this->statuses->all(true),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'employees' => $this->employees->activeForSelect(),
            'canExport' => $this->currentUser->can('reports.export'),
        ]);
    }

    public function stock(Request $request): Response
    {
        if ($request->queryString('format') === 'csv') {
            $section = $request->queryString('section', 'type');

            return Response::download($this->service->stockCsv($section), CsvWriter::MIME, CsvWriter::filename('bestand-' . $section));
        }

        return $this->render('reports.stock', [
            'title' => 'Bestandsbericht',
            'activeNav' => 'reports',
            'stock' => $this->service->stock(),
            'canExport' => $this->currentUser->can('reports.export'),
        ]);
    }

    public function checkouts(Request $request): Response
    {
        return $this->movements($request, 'checkout');
    }

    public function returns(Request $request): Response
    {
        return $this->movements($request, 'return');
    }

    private function movements(Request $request, string $type): Response
    {
        $filters = $this->service->movementFilters($type, $request->query());
        $isReturn = $type === 'return';
        if ($request->queryString('format') === 'csv') {
            return Response::download($this->service->movementsCsv($filters), CsvWriter::MIME, CsvWriter::filename($isReturn ? 'retouren' : 'entnahmen'));
        }
        $paginator = new Paginator($this->service->movementsCount($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 100) ?? 100);

        return $this->render('reports.movements', [
            'title' => $isReturn ? 'Retouren' : 'Entnahmen',
            'activeNav' => 'reports',
            'type' => $type,
            'rows' => $this->service->movements($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'summary' => $this->service->movementSummary($filters),
            'paginator' => $paginator,
            'basePath' => $isReturn ? '/reports/returns' : '/reports/checkouts',
            'query' => $request->query(),
            'types' => $this->types->all(true),
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
            'employees' => $this->employees->activeForSelect(),
            'sources' => MovementService::SOURCES,
            'conditions' => MovementService::CONDITIONS,
            'statusLabels' => ReportService::STATUS_LABELS,
            'canExport' => $this->currentUser->can('reports.export'),
        ]);
    }
}
