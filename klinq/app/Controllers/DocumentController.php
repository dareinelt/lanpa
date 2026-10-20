<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\DocumentRepository;
use App\Security\CurrentUser;
use App\Services\DocumentService;

/** Auslieferung und Löschung von Dokumenten – immer mit Rechteprüfung je Objekttyp. */
final class DocumentController extends BaseController
{
    /** Objekttyp → Recht, das zum Ansehen bzw. Löschen nötig ist. */
    private const PERMISSIONS = [
        'asset' => ['assets.view', 'assets.manage'],
        'movement' => ['movements.view', 'movements.complete'],
        'purchase_order' => ['orders.view', 'orders.manage'],
        'license' => ['licenses.view', 'licenses.manage'],
        'supplier' => ['suppliers.view', 'suppliers.manage'],
        'import' => ['imports.manage', 'imports.manage'],
        'handover' => ['handover.view', 'handover.manage'],
    ];

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly DocumentRepository $documents,
        private readonly DocumentService $service
    ) {
        parent::__construct($view, $currentUser);
    }

    public function show(Request $request): Response
    {
        $doc = $this->findOrFail($this->documents->find($request->paramInt('id')), 'Dokument nicht gefunden');
        $this->currentUser->require(self::PERMISSIONS[$doc['entity_type']][0] ?? 'documents.view');
        $path = $this->service->path($doc);
        if (!is_file($path)) {
            return $this->render('errors.error', ['status' => 404, 'message' => 'Die Datei ist auf dem Server nicht mehr vorhanden.'], 404);
        }
        $inline = $this->service->isImage($doc) || $doc['mime_type'] === 'application/pdf';

        return Response::file($path, (string) $doc['mime_type'], (string) $doc['original_name'], $inline && $request->queryString('download') !== '1');
    }

    public function delete(Request $request): Response
    {
        $doc = $this->findOrFail($this->documents->find($request->paramInt('id')), 'Dokument nicht gefunden');
        $this->currentUser->require(self::PERMISSIONS[$doc['entity_type']][1] ?? 'documents.manage');
        $this->service->delete($doc);
        if ($request->wantsJson()) {
            return $this->json(['ok' => true]);
        }
        $this->flash('success', 'Dokument „' . $doc['original_name'] . '“ wurde gelöscht.');

        return $this->redirect($this->backUrl($request, '/dashboard'));
    }
}
