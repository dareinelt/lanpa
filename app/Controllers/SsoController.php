<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Security\SsoAuth;

/**
 * Freiwillige Windows-Anmeldung (NTLM). Die Seite ist ohne Anmeldung nutzbar;
 * nur /sso/anmelden verlangt im auth-Container NTLM.
 *
 *   /sso?ziel=…        merkt sich das Ziel (ohne NTLM) und leitet weiter
 *   /sso/anmelden      NTLM im auth-Container; uebernimmt den erkannten Benutzer
 *   /sso/nicht-erkannt Fehlerseite (401) des auth-Containers fuer Browser ohne
 *                      Domaenenanmeldung: sofort zurueck zum Ziel
 */
final class SsoController extends Controller
{
    public function start(Request $request): Response
    {
        $target = SsoAuth::safeTarget($request->query('ziel'));
        $sso = Container::sso();
        if (!$sso->isEnabled() || $sso->isFake()) {
            return $this->redirect($target);
        }

        Session::put(SsoAuth::RETURN_KEY, $target);
        Session::put(SsoAuth::ATTEMPT_KEY, time());

        return $this->noStore(Response::redirect('/sso/anmelden'));
    }

    public function login(Request $request): Response
    {
        $target = $this->takeTarget();
        $sso = Container::sso();
        $user = $sso->resolveHeader($request);

        if ($user !== null) {
            $sso->remember($user);
        } elseif ($sso->isTrusted($request) && trim((string) ($request->server['HTTP_X_REMOTE_USER'] ?? '')) !== '') {
            app_logger()->info('Windows-Anmeldung ohne passenden Telefonbucheintrag.', [
                'source' => (string) ($request->server['HTTP_X_REMOTE_SOURCE'] ?? ''),
            ]);
        }

        return $this->noStore(Response::redirect($target));
    }

    /**
     * Status 401 bleibt erhalten, damit Domaenen-Clients die NTLM-Aufforderung
     * des auth-Containers (WWW-Authenticate) weiterhin beantworten.
     */
    public function notRecognized(Request $request): Response
    {
        $target = $this->takeTarget();

        return $this->noStore($this->view('pages.sso_not_recognized', [
            'pageTitle' => 'Ohne Windows-Anmeldung',
            'target' => $target,
            'metaRefresh' => $target,
        ], 'layouts.base', 401));
    }

    private function takeTarget(): string
    {
        $target = SsoAuth::safeTarget((string) Session::get(SsoAuth::RETURN_KEY, '/'));
        Session::forget(SsoAuth::RETURN_KEY);

        return $target;
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store');
    }
}
