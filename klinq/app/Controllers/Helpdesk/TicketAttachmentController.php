<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ForbiddenException;
use App\Exceptions\ValidationException;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketRepository;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\DocumentService;
use App\Services\Helpdesk\TicketService;

/**
 * Kommentare und Anhänge eines Tickets (Agenten- und Portalansicht).
 * Anhänge werden über den DocumentService (documents-Tabelle) gespeichert und mit dem Ticket verknüpft;
 * der Download prüft die Ticket-Sichtbarkeit statt einer globalen Dokumentberechtigung.
 */
final class TicketAttachmentController extends HelpdeskBaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly TicketService $service,
        private readonly TicketAttachmentRepository $attachments,
        private readonly TicketRepository $tickets,
        private readonly DocumentService $documents,
        private readonly AuditLogService $audit
    ) {
        parent::__construct($view, $currentUser);
    }

    /** Kommentar (öffentlich oder intern) ggf. mit Anhang anlegen. */
    public function comment(Request $request): Response
    {
        $id = $request->paramInt('id');
        $portal = str_starts_with($request->path(), '/portal/');
        $redirect = ($portal ? '/portal/tickets/' : '/helpdesk/tickets/') . $id . '#comments';
        $type = !$portal && $request->string('type') === 'internal' ? 'internal' : 'public';
        $file = $request->file('file');
        $comment = null;

        $response = $this->attempt($request, function () use ($request, $id, $type, $portal, $file, &$comment): void {
            $body = $request->string('body');
            if ($body === '' && $file !== null) {
                $body = 'Anhang: ' . $file['name'];
            }
            $comment = $this->service->addComment($id, $body, $type, $portal ? 'portal' : 'web');
            if ($file !== null) {
                $this->storeFile($id, (int) $comment['id'], $type === 'internal', $file, $request->stringOrNull('note'));
            }
        }, $redirect, false);

        return $response ?? $this->respond($request, $type === 'internal' ? 'Interne Notiz gespeichert.' : 'Kommentar gespeichert.', $redirect, ['comment' => $comment]);
    }

    /** Anhang ohne Kommentar hochladen. */
    public function upload(Request $request): Response
    {
        $id = $request->paramInt('id');
        $portal = str_starts_with($request->path(), '/portal/');
        $redirect = ($portal ? '/portal/tickets/' : '/helpdesk/tickets/') . $id . '#attachments';
        $file = $request->file('file');
        if ($file === null) {
            if ($request->wantsJson()) {
                throw ValidationException::single('file', 'Bitte eine Datei auswählen.');
            }
            $this->flash('error', 'Bitte eine Datei auswählen.');

            return $this->redirect($redirect);
        }
        $internal = !$portal && $request->bool('internal');

        $response = $this->attempt($request, function () use ($id, $internal, $file, $request): void {
            $this->storeFile($id, null, $internal, $file, $request->stringOrNull('note'));
        }, $redirect, false);

        return $response ?? $this->respond($request, 'Anhang „' . $file['name'] . '“ hochgeladen.', $redirect);
    }

    /** Anhang herunterladen/anzeigen – nur bei sichtbarem Ticket. */
    public function download(Request $request): Response
    {
        $doc = $this->findOrFail($this->attachments->find($request->paramInt('document')), 'Anhang nicht gefunden');
        $ticket = $this->service->getVisible((int) $doc['ticket_id']);
        if ((int) $doc['is_internal'] === 1 && !$this->service->isAgent()) {
            throw new ForbiddenException('Dieser Anhang ist intern.');
        }
        unset($ticket);
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
        $doc = $this->findOrFail($this->attachments->find($request->paramInt('document')), 'Anhang nicht gefunden');
        if ((int) $doc['ticket_id'] !== $id) {
            throw new ForbiddenException('Anhang gehört nicht zu diesem Ticket.');
        }
        $ticket = $this->service->getVisible($id);
        $own = (int) ($doc['uploaded_by'] ?? 0) === (int) $this->currentUser->id();
        if (!$this->currentUser->can('helpdesk.update') && !$own) {
            throw new ForbiddenException('Keine Berechtigung zum Löschen dieses Anhangs.');
        }
        $this->documents->delete($doc);
        $this->tickets->addEvent($id, 'attachment_removed', $this->currentUser->id(), $this->currentUser->displayName(), 'attachment', (string) $doc['original_name'], null);
        $this->audit->log('delete', 'attachment', (int) $doc['id'], $ticket['number'] . ' ' . $doc['original_name'], ['file' => $doc['original_name']], null);
        $portal = str_starts_with($request->path(), '/portal/');

        return $this->respond($request, 'Anhang gelöscht.', ($portal ? '/portal/tickets/' : '/helpdesk/tickets/') . $id . '#attachments');
    }

    /** @param array<string,mixed> $file */
    private function storeFile(int $ticketId, ?int $commentId, bool $internal, array $file, ?string $note): void
    {
        $ticket = $this->service->getVisible($ticketId);
        if (!$this->service->isAgent() && !$this->service->isOwnTicket($ticket)) {
            throw new ForbiddenException('Keine Berechtigung für dieses Ticket.');
        }
        $documentId = $this->documents->store('ticket', $ticketId, 'attachment', $file, $note);
        $this->attachments->link($documentId, $ticketId, $commentId, $internal);
        $this->tickets->addEvent($ticketId, 'attachment_added', $this->currentUser->id(), $this->currentUser->displayName(), 'attachment', null, (string) $file['name'], ['document_id' => $documentId, 'internal' => $internal]);
        $this->tickets->update($ticketId, ['updated_by' => $this->currentUser->id()]);
        $this->audit->log('upload', 'attachment', $documentId, $ticket['number'] . ' ' . $file['name'], null, ['file' => $file['name'], 'internal' => $internal]);
    }
}
