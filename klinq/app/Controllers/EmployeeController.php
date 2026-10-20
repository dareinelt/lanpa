<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\LocationRepository;
use App\Security\CurrentUser;
use App\Services\EmployeeService;
use App\Services\HandoverService;
use App\Services\LocationService;

final class EmployeeController extends CrudController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly EmployeeRepository $employees,
        private readonly LocationRepository $locations,
        private readonly CostCenterRepository $costCenters,
        private readonly EmployeeService $service,
        private readonly AssetRepository $assets,
        private readonly HandoverService $handover
    ) {
        parent::__construct($view, $currentUser);
    }

    protected function basePath(): string { return '/employees'; }
    protected function viewPrefix(): string { return 'employees'; }
    protected function label(): string { return 'Mitarbeiter'; }
    protected function labelPlural(): string { return 'Mitarbeiter'; }
    protected function navKey(): string { return 'employees'; }
    protected function findRow(int $id): ?array { return $this->employees->find($id); }
    protected function searchRows(array $filters, int $limit, int $offset): array { return $this->employees->search($filters, $limit, $offset); }
    protected function countRows(array $filters): int { return $this->employees->countSearch($filters); }
    protected function createRow(array $input): int { return $this->service->create($input); }
    protected function updateRow(int $id, array $existing, array $input): void { $this->service->update($id, $existing, $input); }
    protected function setRowActive(int $id, array $existing, bool $active): void { $this->service->setActive($id, $existing, $active); }

    protected function rowLabel(array $row): string { return (string) $row['display_name']; }

    protected function filters(Request $request): array
    {
        return array_merge(parent::filters($request), [
            'department' => $request->queryString('department'),
            'source' => $request->queryString('source'),
        ]);
    }

    protected function indexData(Request $request): array
    {
        return ['departments' => $this->employees->departments()];
    }

    protected function formData(?array $row): array
    {
        return [
            'locationOptions' => LocationService::flatten($this->locations->all(true)),
            'costCenters' => $this->costCenters->activeForSelect(),
        ];
    }

    protected function afterSaveUrl(Request $request, int $id): string
    {
        return '/employees/' . $id;
    }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->employees->find($request->paramInt('id')));

        return $this->render('employees.show', [
            'title' => $row['display_name'],
            'activeNav' => 'employees',
            'row' => $row,
            'assets' => $this->assets->forEmployee((int) $row['id']),
            'handover' => $this->currentUser->can('handover.view') ? $this->handover->statusFor((int) $row['id']) : null,
        ]);
    }

    /** JSON-Suche für Auswahlfelder (Picker); mit phonetischer Autovervollständigung (Kölner Phonetik). */
    public function search(Request $request): Response
    {
        $term = trim($request->queryString('q'));
        $rows = $this->service->searchAutocomplete($term, 30);

        return $this->json(['items' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['display_name'],
            'meta' => implode(' · ', array_filter([$r['personnel_number'], $r['department'], $r['username']])),
        ], $rows)]);
    }
}
