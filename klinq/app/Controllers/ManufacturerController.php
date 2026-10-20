<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\ManufacturerRepository;
use App\Security\CurrentUser;
use App\Services\DuplicateWarningException;
use App\Services\ManufacturerService;

final class ManufacturerController extends CrudController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly ManufacturerRepository $manufacturers,
        private readonly ManufacturerService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    protected function basePath(): string { return '/manufacturers'; }
    protected function viewPrefix(): string { return 'manufacturers'; }
    protected function label(): string { return 'Hersteller'; }
    protected function labelPlural(): string { return 'Hersteller'; }
    protected function navKey(): string { return 'manufacturers'; }
    protected function findRow(int $id): ?array { return $this->manufacturers->find($id); }
    protected function searchRows(array $filters, int $limit, int $offset): array { return $this->manufacturers->search($filters, $limit, $offset); }
    protected function countRows(array $filters): int { return $this->manufacturers->countSearch($filters); }
    protected function createRow(array $input): int { return $this->service->create($input, !empty($input['ignore_duplicates'])); }
    protected function updateRow(int $id, array $existing, array $input): void { $this->service->update($id, $existing, $input, !empty($input['ignore_duplicates'])); }
    protected function setRowActive(int $id, array $existing, bool $active): void { $this->service->setActive($id, $existing, $active); }

    public function store(Request $request): Response
    {
        try {
            return parent::store($request);
        } catch (DuplicateWarningException $e) {
            $this->withOldInput($request, []);

            return $this->renderForm($request, null, ['duplicates' => $e->duplicates()]);
        }
    }

    public function update(Request $request): Response
    {
        try {
            return parent::update($request);
        } catch (DuplicateWarningException $e) {
            $this->withOldInput($request, []);

            return $this->renderForm($request, $this->findOrFail($this->findRow($request->paramInt('id'))), ['duplicates' => $e->duplicates()]);
        }
    }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->manufacturers->find($request->paramInt('id')));

        return $this->render('manufacturers.show', [
            'title' => $row['name'],
            'activeNav' => 'manufacturers',
            'row' => $row,
        ]);
    }

    /** Live-Dublettenprüfung für das Formular (JSON). */
    public function checkDuplicates(Request $request): Response
    {
        $name = trim($request->queryString('name'));
        $exclude = $request->int('exclude');
        if (mb_strlen($name) < 2) {
            return $this->json(['duplicates' => []]);
        }

        return $this->json(['duplicates' => array_map(static fn (array $d): array => [
            'id' => (int) $d['id'],
            'name' => $d['name'],
            'short_name' => $d['short_name'],
            'is_active' => (bool) $d['is_active'],
            'reason' => $d['reason'],
            'score' => $d['score'],
        ], $this->service->findDuplicates($name, $exclude))]);
    }
}
