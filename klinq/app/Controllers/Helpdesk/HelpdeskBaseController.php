<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\Helpdesk\TicketService;
use App\Support\MailFrame;
use App\Support\Url;

/**
 * Gemeinsame Hilfen für die Help-Desk-Controller (Formular-Fehlerbehandlung, Rücksprung, Zeitzone).
 */
abstract class HelpdeskBaseController extends BaseController
{
    /**
     * Führt eine Aktion aus und übersetzt Validierungs-/Konfliktfehler in Flash + Redirect.
     * Bei JSON-Anfragen werden Fehler zentral von Application behandelt (Exception durchreichen).
     */
    protected function attempt(Request $request, callable $action, string $errorRedirect, bool $keepInput = true): ?Response
    {
        try {
            $action();

            return null;
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            if ($keepInput) {
                $this->withOldInput($request, $e->errors());
            } else {
                $this->flash('error', implode(' ', $e->errors()));
            }

            return $this->redirect($errorRedirect);
        } catch (ConflictException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }
            $this->flash('error', $e->getMessage());

            return $this->redirect($errorRedirect);
        }
    }

    protected function safeBack(Request $request, string $fallback): string
    {
        $back = $request->string('back');
        if ($back !== '') {
            return Url::safeLocalPath($back, $fallback);
        }

        return $this->backUrl($request, $fallback);
    }

    /** Alte Formulareingabe (nach Validierungsfehler), sonst Standardwert. */
    protected function oldInput(string $key, mixed $default = null): mixed
    {
        $old = $_SESSION['_old_input'] ?? [];

        return array_key_exists($key, $old) && $old[$key] !== '' ? $old[$key] : $default;
    }

    /** Antwort je nach Anfrageart: JSON oder Redirect mit Flash. */
    protected function respond(Request $request, string $message, string $redirect, array $json = []): Response
    {
        if ($request->wantsJson()) {
            return $this->json(['ok' => true, 'message' => $message] + $json);
        }
        if ($message !== '') {
            $this->flash('success', $message);
        }

        return $this->redirect($redirect);
    }

    /**
     * HTML einer per E-Mail eingegangenen Nachricht als eigenes Dokument für den Anzeigerahmen.
     *
     * @param list<array<string,mixed>> $rows Für den Benutzer sichtbare Einträge (Kommentare, Journal)
     * @param string $column Spalte mit dem Mail-HTML
     * @param int|null $rowId Eintrag; null = Datensatz selbst (Beschreibung)
     * @param array<string,mixed> $record Ticket oder Aufgabe
     */
    protected function mailFrame(array $record, array $rows, string $column, ?int $rowId = null): Response
    {
        $html = null;
        if ($rowId === null) {
            $html = $record['description_html'] ?? null;
        } else {
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $rowId) {
                    $html = $row[$column] ?? null;
                    break;
                }
            }
        }
        if (!is_string($html) || trim($html) === '') {
            throw new NotFoundException('Keine HTML-Fassung vorhanden.');
        }

        return MailFrame::response($html);
    }

    /**
     * Mail-HTML eines Tickets (Beschreibung oder Kommentar) mit denselben Sichtbarkeitsregeln
     * wie die Ticketansicht: interne Kommentare nur für Agenten.
     */
    protected function ticketMailFrame(TicketService $service, Request $request): Response
    {
        $ticket = $service->getVisible($request->paramInt('id'));
        $commentId = $request->param('comment') !== null ? $request->paramInt('comment') : null;

        return $this->mailFrame($ticket, $commentId !== null ? $service->comments($ticket) : [], 'body_html', $commentId);
    }
}
