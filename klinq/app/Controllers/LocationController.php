<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Url;
use App\Exceptions\ValidationException;
use App\Repositories\LocationRepository;
use App\Security\CurrentUser;
use App\Services\LocationService;

final class LocationController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly LocationRepository $locations,
        private readonly LocationService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $showInactive = $request->bool('inactive');
        $rows = $this->locations->all(!$showInactive);

        return $this->render('locations.index', [
            'title' => 'Standorte',
            'activeNav' => 'locations',
            'tree' => LocationService::buildTree($rows),
            'total' => count($rows),
            'showInactive' => $showInactive,
            'types' => LocationRepository::TYPES,
        ]);
    }

    public function show(Request $request): Response
    {
        $row = $this->findOrFail($this->locations->find($request->paramInt('id')));

        return $this->render('locations.show', [
            'title' => $row['name'],
            'activeNav' => 'locations',
            'row' => $row,
            'children' => $this->locations->children((int) $row['id']),
            'types' => LocationRepository::TYPES,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null, $request->int('parent_id'));
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service->create($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/locations/new');
        }
        $this->flash('success', 'Standort wurde angelegt.');
        $return = $request->string('return');

        return $this->redirect(Url::safeLocalPath($return, '/locations/' . $id));
    }

    public function edit(Request $request): Response
    {
        $row = $this->findOrFail($this->locations->find($request->paramInt('id')));

        return $this->form($request, $row, null);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->locations->find($id));
        try {
            $this->service->update($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/locations/' . $id . '/edit');
        }
        $this->flash('success', 'Standort wurde gespeichert.');

        return $this->redirect('/locations/' . $id);
    }

    public function toggleActive(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->locations->find($id));
        $active = (int) $row['is_active'] !== 1;
        $this->service->setActive($id, $row, $active);
        $this->flash('success', 'Standort „' . $row['name'] . '“ wurde ' . ($active ? 'aktiviert' : 'deaktiviert (inklusive Unterstandorte)') . '.');

        return $this->redirect($this->backUrl($request, '/locations/' . $id));
    }

    /** Drag & Drop im Standortbaum: neuer übergeordneter Standort und Reihenfolge (JSON). */
    public function move(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->locations->find($id);
        if ($row === null) {
            return $this->json(['ok' => false, 'error' => 'Standort nicht gefunden.'], 404);
        }
        $order = $request->input('order', []);
        $order = is_array($order)
            ? array_values(array_filter(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $order), static fn (int $v): bool => $v > 0))
            : [];
        $parentId = $request->int('parent_id');
        try {
            $moved = $this->service->move($id, $row, $parentId !== null && $parentId > 0 ? $parentId : null, $order);
        } catch (ValidationException $e) {
            return $this->json(['ok' => false, 'error' => implode(' ', $e->errors()), 'errors' => $e->errors()], 422);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Standort „' . $moved['name'] . '“ wurde verschoben.',
            'location' => [
                'id' => (int) $moved['id'],
                'parent_id' => $moved['parent_id'] !== null ? (int) $moved['parent_id'] : null,
                'full_path' => $moved['full_path'],
            ],
        ]);
    }

    /** JSON-Suche für Auswahlfelder (Picker). */
    public function search(Request $request): Response
    {
        $term = trim($request->queryString('q'));
        $rows = $term === '' ? array_slice($this->locations->all(true), 0, 30) : $this->locations->search($term, 30);

        return $this->json(['items' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'path' => $r['full_path'],
            'type' => LocationRepository::TYPES[$r['type']] ?? $r['type'],
        ], $rows)]);
    }

    private function form(Request $request, ?array $row, ?int $presetParent): Response
    {
        $options = LocationService::flatten($this->locations->all(false));
        if ($row !== null) {
            $exclude = [(int) $row['id'], ...$this->locations->descendantIds((int) $row['id'])];
            $options = array_values(array_filter($options, static fn (array $o): bool => !in_array((int) $o['id'], $exclude, true)));
        }

        return $this->render('locations.form', [
            'title' => $row === null ? 'Standort anlegen' : 'Standort bearbeiten',
            'activeNav' => 'locations',
            'row' => $row,
            'presetParent' => $presetParent,
            'parentOptions' => $options,
            'types' => LocationRepository::TYPES,
            'returnTo' => $request->queryString('return'),
        ]);
    }
}
