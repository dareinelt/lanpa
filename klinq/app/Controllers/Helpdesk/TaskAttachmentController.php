<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\Helpdesk\TaskAttachmentService;
use App\Services\Helpdesk\TaskService;

/**
 * Anhänge einer Aufgabe: hochladen, herunterladen und löschen. Die Ablage läuft über den
 * DocumentService (documents-Tabelle), die Zuordnung über `task_attachments`. Beim Umwandeln eines
 * Tickets übernimmt der TaskService die Ticketanhänge als eigene Kopien.
 */
final class TaskAttachmentController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TaskService $service,
        private readonly TaskAttachmentService $attachments,
        private readonly DocumentService $documents
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Anhang ohne Kommentar hochladen. */
    public function upload(Request $request): Response
    {
        $id = $request->paramInt('id');
        $redirect = '/helpdesk/aufgaben/' . $id . '#anhaenge';
        $file = $request->file('file');
        if ($file === null) {
            if ($request->wantsJson()) {
                throw ValidationException::single('file', 'Bitte eine Datei auswählen.');
            }
            $this->flash('error', 'Bitte eine Datei auswählen.');

            return $this->redirect($redirect);
        }

        $response = $this->attempt($request, function () use ($id, $file, $request): void {
            $this->attachments->store($id, $file, $request->stringOrNull('note'));
        }, $redirect, false);

        return $response ?? $this->respond($request, 'Anhang „' . $file['name'] . '“ hochgeladen.', $redirect);
    }

    /** Anhang herunterladen/anzeigen – nur mit Zugriff auf die Aufgabe. */
    public function download(Request $request): Response
    {
        $id = $request->paramInt('id');
        $doc = $this->findOrFail($this->attachments->find($request->paramInt('document')), 'Anhang nicht gefunden');
        if ((int) $doc['task_id'] !== $id) {
            throw new ForbiddenException('Anhang gehört nicht zu dieser Aufgabe.');
        }
        $this->service->get($id);
        $path = $this->documents->path($doc);
        if (!is_file($path)) {
            return $this->render('errors.error', ['status' => 404, 'message' => 'Die Datei ist auf dem Server nicht mehr vorhanden.'], 404);
        }
        $inline = $this->documents->isImage($doc) || $doc['mime_type'] === 'application/pdf';

        return Response::file($path, (string) $doc['mime_type'], (string) $doc['original_name'], $inline && $request->queryString('download') !== '1');
    }

    public function delete(Request $request): Response
    {
        $id = $request->paramInt('id');
        $documentId = $request->paramInt('document');
        $redirect = '/helpdesk/aufgaben/' . $id . '#anhaenge';

        $response = $this->attempt($request, function () use ($id, $documentId): void {
            $this->attachments->delete($id, $documentId);
        }, $redirect, false);

        return $response ?? $this->respond($request, 'Anhang gelöscht.', $redirect);
    }
}
