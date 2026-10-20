<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Repositories\CostCenterRepository;
use App\Repositories\LocationRepository;
use App\Security\CurrentUser;
use App\Services\CostCenterService;
use App\Services\LocationService;

final class CostCenterController extends CrudController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly CostCenterRepository $costCenters,
        private readonly LocationRepository $locations,
        private readonly CostCenterService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    protected function basePath(): string { return '/cost-centers'; }
    protected function viewPrefix(): string { return 'cost_centers'; }
    protected function label(): string { return 'Kostenstelle'; }
    protected function labelPlural(): string { return 'Kostenstellen'; }
    protected function navKey(): string { return 'costcenters'; }
    protected function findRow(int $id): ?array { return $this->costCenters->find($id); }
    protected function searchRows(array $filters, int $limit, int $offset): array { return $this->costCenters->search($filters, $limit, $offset); }
    protected function countRows(array $filters): int { return $this->costCenters->countSearch($filters); }
    protected function createRow(array $input): int { return $this->service->create($input); }
    protected function updateRow(int $id, array $existing, array $input): void { $this->service->update($id, $existing, $input); }
    protected function setRowActive(int $id, array $existing, bool $active): void { $this->service->setActive($id, $existing, $active); }

    protected function rowLabel(array $row): string
    {
        return $row['number'] . ' ' . $row['description'];
    }

    protected function formData(?array $row): array
    {
        return ['locationOptions' => LocationService::flatten($this->locations->all(true))];
    }
}
