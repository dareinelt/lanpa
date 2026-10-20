<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Support\Paginator;
use App\Support\Url;

/**
 * Gemeinsamer Ablauf für Stammdaten: Liste mit Filter/Paginierung, Anlegen,
 * Bearbeiten, Aktivieren/Deaktivieren. Konkrete Controller liefern Datenzugriff und Labels.
 */
abstract class CrudController extends BaseController
{
    abstract protected function basePath(): string;
    abstract protected function viewPrefix(): string;
    abstract protected function label(): string;
    abstract protected function labelPlural(): string;
    abstract protected function navKey(): string;

    /** @return array<string,mixed>|null */
    abstract protected function findRow(int $id): ?array;

    /** @return array<int,array<string,mixed>> */
    abstract protected function searchRows(array $filters, int $limit, int $offset): array;
    abstract protected function countRows(array $filters): int;
    abstract protected function createRow(array $input): int;
    abstract protected function updateRow(int $id, array $existing, array $input): void;
    abstract protected function setRowActive(int $id, array $existing, bool $active): void;

    /** @return array<string,mixed> */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim($request->queryString('q')),
            'active' => $request->queryString('active', '1'),
        ];
    }

    /** Zusätzliche Daten für Formulare (Auswahllisten). @return array<string,mixed> */
    protected function formData(?array $row): array
    {
        return [];
    }

    /** Zusätzliche Daten für die Liste. @return array<string,mixed> */
    protected function indexData(Request $request): array
    {
        return [];
    }

    protected function rowLabel(array $row): string
    {
        return (string) ($row['name'] ?? $row['display_name'] ?? $row['number'] ?? $row['id']);
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $paginator = new Paginator($this->countRows($filters), $request->int('page', 1) ?? 1, $request->int('per_page', 50) ?? 50);

        return $this->render($this->viewPrefix() . '.index', array_merge([
            'title' => $this->labelPlural(),
            'activeNav' => $this->navKey(),
            'rows' => $this->searchRows($filters, $paginator->perPage, $paginator->offset()),
            'filters' => $filters,
            'paginator' => $paginator,
            'basePath' => $this->basePath(),
            'query' => $request->query(),
        ], $this->indexData($request)));
    }

    public function create(Request $request): Response
    {
        return $this->renderForm($request, null);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->createRow($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect($this->basePath() . '/new' . $this->preserveQuery($request));
        }
        $this->flash('success', $this->label() . ' wurde angelegt.');

        return $this->redirect($this->afterSaveUrl($request, $id));
    }

    public function edit(Request $request): Response
    {
        $row = $this->findOrFail($this->findRow($request->paramInt('id')));

        return $this->renderForm($request, $row);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->findRow($id));
        try {
            $this->updateRow($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect($this->basePath() . '/' . $id . '/edit');
        }
        $this->flash('success', $this->label() . ' wurde gespeichert.');

        return $this->redirect($this->afterSaveUrl($request, $id));
    }

    public function toggleActive(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->findRow($id));
        $active = !((int) $row['is_active'] === 1);
        $this->setRowActive($id, $row, $active);
        $this->flash('success', $this->label() . ' „' . $this->rowLabel($row) . '“ wurde ' . ($active ? 'aktiviert' : 'deaktiviert') . '.');

        return $this->redirect($this->backUrl($request, $this->basePath()));
    }

    protected function renderForm(Request $request, ?array $row, array $extra = []): Response
    {
        return $this->render($this->viewPrefix() . '.form', array_merge([
            'title' => $row === null ? $this->label() . ' anlegen' : $this->label() . ' bearbeiten',
            'activeNav' => $this->navKey(),
            'row' => $row,
            'basePath' => $this->basePath(),
            'label' => $this->label(),
            'labelPlural' => $this->labelPlural(),
            'returnTo' => $this->safeReturn($request->queryString('return')),
        ], $this->formData($row), $extra));
    }

    protected function afterSaveUrl(Request $request, int $id): string
    {
        $return = $this->safeReturn($request->string('return'));

        return $return !== '' ? $return : $this->basePath();
    }

    protected function preserveQuery(Request $request): string
    {
        $return = $this->safeReturn($request->string('return'));

        return $return !== '' ? '?return=' . rawurlencode($return) : '';
    }

    protected function safeReturn(string $url): string
    {
        return Url::safeLocalPath($url);
    }
}
