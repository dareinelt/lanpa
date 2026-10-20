<?php

declare(strict_types=1);

namespace App\Controllers\Helpdesk;

use App\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ConflictException;
use App\Exceptions\ValidationException;
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
}
