<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Security\CurrentUser;
use App\Services\DocumentService;
use App\Services\Helpdesk\KnowledgeArticleAttachmentService;
use App\Services\Helpdesk\KnowledgeBaseService;

/**
 * Anhänge eines Wissensartikels (Agenten- und Portalansicht).
 * Der Download prüft die Sichtbarkeit des Artikels statt einer globalen Dokumentberechtigung.
 */
final class KnowledgeArticleAttachmentController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly KnowledgeBaseService $knowledge,
        private readonly KnowledgeArticleAttachmentService $attachments,
        private readonly DocumentService $documents
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Anhang ohne Kommentar hochladen (nur Redakteure). */
    public function upload(Request $request): Response
    {
        $id = $request->paramInt('id');
        $redirect = '/helpdesk/knowledge/' . $id . '#attachments';
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

    /** Anhang herunterladen/anzeigen – nur bei sichtbarem Artikel. */
    public function download(Request $request): Response
    {
        $id = $request->paramInt('id');
        $this->knowledge->getVisible($id);
        $document = $this->attachments->findForArticle($id, $request->paramInt('document'));
        $path = $this->documents->path($document);
        if (!is_file($path)) {
            return $this->render('errors.error', ['status' => 404, 'message' => 'Die Datei ist auf dem Server nicht mehr vorhanden.'], 404);
        }
        $inline = $this->documents->isImage($document) || $document['mime_type'] === 'application/pdf';

        return Response::file($path, (string) $document['mime_type'], (string) $document['original_name'], $inline && $request->queryString('download') !== '1');
    }

    public function delete(Request $request): Response
    {
        $id = $request->paramInt('id');
        $document = $this->attachments->findForArticle($id, $request->paramInt('document'));
        $this->attachments->delete($document);

        return $this->respond($request, 'Anhang gelöscht.', '/helpdesk/knowledge/' . $id . '#attachments');
    }
}
