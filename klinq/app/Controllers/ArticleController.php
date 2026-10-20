<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\ManufacturerRepository;
use App\Security\CurrentUser;
use App\Services\ArticleService;
use App\Services\DuplicateWarningException;

final class ArticleController extends CrudController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly ArticleRepository $articles,
        private readonly ManufacturerRepository $manufacturers,
        private readonly AssetTypeRepository $assetTypes,
        private readonly ArticleService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    protected function basePath(): string { return '/articles'; }
    protected function viewPrefix(): string { return 'articles'; }
    protected function label(): string { return 'Artikel'; }
    protected function labelPlural(): string { return 'Artikel'; }
    protected function navKey(): string { return 'articles'; }
    protected function findRow(int $id): ?array { return $this->articles->find($id); }
    protected function searchRows(array $filters, int $limit, int $offset): array { return $this->articles->search($filters, $limit, $offset); }
    protected function countRows(array $filters): int { return $this->articles->countSearch($filters); }
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

    /** Live-Dublettenprüfung für das Artikelformular (JSON). */
    public function checkDuplicates(Request $request): Response
    {
        $name = trim($request->queryString('name'));
        if (mb_strlen($name) < 2) {
            return $this->json(['duplicates' => []]);
        }
        $manufacturerId = $request->int('manufacturer_id') ?? 0;
        $number = trim($request->queryString('article_number'));

        return $this->json(['duplicates' => array_map(static fn (array $d): array => [
            'id' => (int) $d['id'],
            'name' => $d['name'],
            'article_number' => $d['article_number'],
            'manufacturer_name' => $d['manufacturer_name'] ?? '',
            'is_active' => (bool) $d['is_active'],
            'reason' => $d['reason'],
            'score' => $d['score'],
        ], $this->service->findDuplicates($name, $manufacturerId > 0 ? $manufacturerId : null, $number !== '' ? $number : null, $request->int('exclude') ?: null))]);
    }

    /** Autovervollständigung für den Artikel-Picker im Asset-Formular (JSON). */
    public function search(Request $request): Response
    {
        $type = $request->int('asset_type_id') ?? 0;
        $rows = $this->articles->searchForPicker(trim($request->queryString('q')), $type > 0 ? $type : null);

        return $this->json(['items' => array_map(static fn (array $a): array => [
            'id' => (int) $a['id'],
            'label' => $a['manufacturer_name'] . ' ' . $a['name'],
            'name' => $a['name'],
            'meta' => implode(' · ', array_filter([$a['article_number'], $a['asset_type_name'], $a['category_name']])),
            'manufacturer_id' => (int) $a['manufacturer_id'],
            'manufacturer_name' => $a['manufacturer_name'],
            'asset_type_id' => (int) $a['asset_type_id'],
            'asset_type_name' => $a['asset_type_name'],
            'asset_category_id' => $a['asset_category_id'] !== null ? (int) $a['asset_category_id'] : null,
            'category_name' => $a['category_name'],
            'article_number' => $a['article_number'],
            'is_handover_relevant' => (bool) $a['is_handover_relevant'],
            'is_consumable' => (bool) $a['is_consumable'],
        ], $rows)]);
    }

    protected function filters(Request $request): array
    {
        return array_merge(parent::filters($request), [
            'manufacturer_id' => $request->queryString('manufacturer_id'),
            'asset_type_id' => $request->queryString('asset_type_id'),
            'handover' => $request->queryString('handover'),
        ]);
    }

    protected function indexData(Request $request): array
    {
        return [
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'assetTypes' => $this->assetTypes->all(true),
        ];
    }

    protected function formData(?array $row): array
    {
        return [
            'manufacturers' => $this->manufacturers->activeForSelect(),
            'assetTypes' => $this->assetTypes->all(true),
            'categories' => $this->assetTypes->categories(null, true),
        ];
    }
}
