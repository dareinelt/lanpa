<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
use App\Repositories\ImportRepository;
use App\Security\CurrentUser;
use App\Services\ImportService;
use App\Support\CsvWriter;
use App\Support\Paginator;

/** CSV-Import: Upload, Vorschau, Durchführung, Protokoll, Fehlerreport, Vorlage. */
final class ImportController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly ImportService $service,
        private readonly ImportRepository $imports
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $this->service->cleanupStalePreviews();
        $paginator = new Paginator($this->imports->countRuns(), $request->int('page', 1) ?? 1, 25);

        return $this->render('imports.index', [
            'title' => 'Import',
            'activeNav' => 'imports',
            'runs' => $this->imports->runs($paginator->perPage, $paginator->offset()),
            'paginator' => $paginator,
            'basePath' => '/imports',
            'query' => $request->query(),
            'columns' => ImportService::COLUMNS,
            'maxRows' => ImportService::MAX_ROWS,
            'maxMb' => (int) (ImportService::MAX_BYTES / 1024 / 1024),
        ]);
    }

    public function template(): Response
    {
        return Response::download(ImportService::templateCsv(), CsvWriter::MIME, 'import-vorlage-assets.csv');
    }

    public function upload(Request $request): Response
    {
        try {
            $id = $this->service->upload($request->file('file'), $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/imports#upload');
        }

        return $this->redirect('/imports/' . $id);
    }

    public function show(Request $request): Response
    {
        $run = $this->service->requireRun($request->paramInt('id'));
        $status = $request->queryString('status', '');
        $allowed = array_keys(ImportService::STATUS_LABELS);
        $status = in_array($status, $allowed, true) ? $status : '';
        $paginator = new Paginator($this->imports->countRows((int) $run['id'], $status ?: null), $request->int('page', 1) ?? 1, 100);

        return $this->render('imports.show', [
            'title' => 'Import #' . $run['id'],
            'activeNav' => 'imports',
            'run' => $run,
            'options' => ImportService::options($run),
            'columnsFound' => json_decode((string) ($run['columns_found'] ?? '{}'), true) ?: ['mapped' => [], 'unmapped' => [], 'skipped_rows' => 0],
            'columns' => ImportService::COLUMNS,
            'rows' => $this->imports->rows((int) $run['id'], $status ?: null, $paginator->perPage, $paginator->offset()),
            'importedRows' => $run['status'] === 'completed' ? $this->imports->rows((int) $run['id'], 'imported', 60) : [],
            'statusFilter' => $status,
            'statusLabels' => ImportService::STATUS_LABELS,
            'paginator' => $paginator,
            'basePath' => '/imports/' . $run['id'],
            'query' => $request->query(),
        ]);
    }

    public function commit(Request $request): Response
    {
        $id = $request->paramInt('id');
        try {
            $run = $this->service->commit($id, $request->input('strict') === '1');
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect('/imports/' . $id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/imports/' . $id);
        }
        $skipped = (int) $run['rows_total'] - (int) $run['rows_imported'];
        $this->flash($skipped > 0 ? 'info' : 'success', sprintf('%d Asset(s) importiert%s.', (int) $run['rows_imported'], $skipped > 0 ? ', ' . $skipped . ' Zeile(n) übersprungen' : ''));

        return $this->redirect('/imports/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = $request->paramInt('id');
        try {
            $this->service->cancel($id);
        } catch (ConflictException $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('/imports/' . $id);
        }
        $this->flash('info', 'Import verworfen.');

        return $this->redirect('/imports');
    }

    public function errors(Request $request): Response
    {
        $id = $request->paramInt('id');

        return Response::download($this->service->errorReportCsv($id), CsvWriter::MIME, CsvWriter::filename('import-' . $id . '-fehler'));
    }
}
