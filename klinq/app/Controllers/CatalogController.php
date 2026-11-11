<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\AssetTypeRepository;
use App\Security\CurrentUser;
use App\Services\AssetCatalogService;

/**
 * „Auswertung & System → Kataloge“: bearbeitbare Kataloge der Assetverwaltung.
 * Ein Katalog („kind“) pro Tab: Assettypen und Kategorien.
 */
final class CatalogController extends BaseController
{
    /** Katalog => [Tab-Label, Bezeichnung eines Eintrags] */
    public const KINDS = [
        'asset-types' => ['Assettypen', 'Assettyp'],
        'asset-categories' => ['Kategorien', 'Kategorie'],
    ];

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AssetTypeRepository $types,
        private readonly AssetCatalogService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $typeFilter = $request->int('asset_type_id');

        return $this->render('catalogs.index', [
            'title' => 'Kataloge',
            'activeNav' => 'catalogs',
            'areaLabel' => 'Auswertung & System',
            'kind' => $kind,
            'kinds' => self::KINDS,
            'rows' => $kind === 'asset-types' ? $this->types->allWithUsage() : $this->types->categoriesWithUsage($typeFilter),
            'types' => $this->types->all(),
            'typeFilter' => $typeFilter,
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $prefill = $kind === 'asset-categories' && $request->int('asset_type_id') !== null
            ? ['asset_type_id' => $request->int('asset_type_id')]
            : null;

        return $this->renderForm($kind, null, $prefill);
    }

    public function edit(Request $request): Response
    {
        $kind = $this->kindFrom($request);

        return $this->renderForm($kind, $this->findOrFail($this->findRow($kind, $request->paramInt('id'))));
    }

    public function store(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        try {
            $this->save($kind, null, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/catalogs/' . $kind . '/new');
        }
        $this->flash('success', self::KINDS[$kind][1] . ' wurde angelegt.');

        return $this->redirect($this->listUrl($kind));
    }

    public function update(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $id = $request->paramInt('id');
        $this->findOrFail($this->findRow($kind, $id));
        try {
            $this->save($kind, $id, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/catalogs/' . $kind . '/' . $id . '/edit');
        }
        $this->flash('success', self::KINDS[$kind][1] . ' wurde gespeichert.');

        return $this->redirect($this->listUrl($kind));
    }

    public function toggleActive(Request $request): Response
    {
        $kind = $this->kindFrom($request);
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->findRow($kind, $id));
        $active = (int) $row['is_active'] !== 1;
        if ($kind === 'asset-types') {
            $this->service->setTypeActive($id, $row, $active);
        } else {
            $this->service->setCategoryActive($id, $row, $active);
        }
        $this->flash('success', self::KINDS[$kind][1] . ' „' . $row['name'] . '“ wurde ' . ($active ? 'aktiviert' : 'deaktiviert') . '.');

        return $this->redirect($this->backUrl($request, $this->listUrl($kind)));
    }

    // ------------------------------------------------------------------ intern

    /** Katalog aus dem Pfad (/catalogs/{kind}/…) oder der Query (?tab=) lesen. */
    private function kindFrom(Request $request): string
    {
        $segments = explode('/', trim($request->path(), '/'));
        $kind = $segments[1] ?? $request->queryString('tab');

        return isset(self::KINDS[$kind]) ? $kind : 'asset-types';
    }

    private function listUrl(string $kind): string
    {
        return '/catalogs?tab=' . $kind;
    }

    /** @return array<string,mixed>|null */
    private function findRow(string $kind, int $id): ?array
    {
        return match ($kind) {
            'asset-types' => $this->types->find($id),
            'asset-categories' => $this->types->findCategory($id),
            default => throw new NotFoundException('Unbekannter Katalog.'),
        };
    }

    /** @param array<string,mixed> $input */
    private function save(string $kind, ?int $id, array $input): int
    {
        return match ($kind) {
            'asset-types' => $this->service->saveType($id, $input),
            'asset-categories' => $this->service->saveCategory($id, $input),
            default => throw new NotFoundException('Unbekannter Katalog.'),
        };
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,mixed>|null $prefill
     */
    private function renderForm(string $kind, ?array $row, ?array $prefill = null): Response
    {
        $label = self::KINDS[$kind][1];

        return $this->render('catalogs.form', [
            'title' => $label . ($row === null ? ' anlegen' : ' bearbeiten'),
            'activeNav' => 'catalogs',
            'areaLabel' => 'Auswertung & System',
            'kind' => $kind,
            'kinds' => self::KINDS,
            'row' => $row,
            'prefill' => $prefill ?? [],
            'icons' => AssetCatalogService::ICONS,
            'types' => $this->types->selectable($kind === 'asset-categories' && $row !== null ? (int) $row['asset_type_id'] : null),
            'assetCount' => $kind === 'asset-types' && $row !== null ? $this->types->assetCount((int) $row['id']) : 0,
            'categoryUsage' => $kind === 'asset-categories' && $row !== null ? $this->types->categoryUsage((int) $row['id']) : 0,
        ]);
    }
}
