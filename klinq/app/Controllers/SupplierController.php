<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\SupplierRepository;
use App\Security\CurrentUser;
use App\Services\SupplierService;

final class SupplierController extends CrudController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly SupplierRepository $suppliers,
        private readonly SupplierService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    protected function basePath(): string { return '/suppliers'; }
    protected function viewPrefix(): string { return 'suppliers'; }
    protected function label(): string { return 'Lieferant'; }
    protected function labelPlural(): string { return 'Lieferanten'; }
    protected function navKey(): string { return 'suppliers'; }
    protected function findRow(int $id): ?array { return $this->suppliers->find($id); }
    protected function searchRows(array $filters, int $limit, int $offset): array { return $this->suppliers->search($filters, $limit, $offset); }
    protected function countRows(array $filters): int { return $this->suppliers->countSearch($filters); }
    protected function createRow(array $input): int { return $this->service->create($input); }
    protected function updateRow(int $id, array $existing, array $input): void { $this->service->update($id, $existing, $input); }
    protected function setRowActive(int $id, array $existing, bool $active): void { $this->service->setActive($id, $existing, $active); }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->suppliers->find($request->paramInt('id')));

        return $this->render('suppliers.show', ['title' => $row['name'], 'activeNav' => 'suppliers', 'row' => $row]);
    }
}
