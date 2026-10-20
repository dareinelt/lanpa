<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Security\CsrfTokenManager;

/**
 * Prüft bei allen zustandsändernden Requests ein CSRF-Token
 * (Formularfeld "_csrf" oder Header "X-CSRF-Token").
 */
final class CsrfMiddleware
{
    public function __construct(private readonly CsrfTokenManager $csrf) {}

    public function __invoke(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $token = $request->header('X-CSRF-Token') ?? (string) $request->input('_csrf', '');
            if (!$this->csrf->validate($token)) {
                // 403 statt 419: Apache kennt 419 nicht und würde die Statuszeile als 500 ausliefern
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'CSRF-Token ungültig oder Sitzung abgelaufen.', 'code' => 'csrf'], 403);
                }

                return Response::html('<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Sitzung abgelaufen</title></head><body><h1>403 – Sitzung abgelaufen oder ungültiges Formular-Token</h1><p>Bitte Seite neu laden und erneut versuchen.</p></body></html>', 403);
            }
        }

        return $next($request);
    }
}
