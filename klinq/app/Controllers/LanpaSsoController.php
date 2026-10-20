<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionManager;
use App\Core\View;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Security\LanpaSsoToken;
use App\Services\AuditLogService;
use App\Services\Lanpa\LanpaIdentityService;
use App\Services\Lanpa\LanpaUserService;
use App\Support\Url;

/**
 * Anmeldung ueber das Intranet (LANPA).
 *
 * Das Intranet stellt nach der Windows-Anmeldung am auth-Container einen
 * kurzlebigen, signierten Einstiegsschein aus und leitet auf /sso/lanpa. Hier
 * wird der Schein geprueft, der Benutzer mit Rolle und Berechtigungsgruppen
 * angelegt bzw. abgeglichen und die Sitzung erzeugt. Eine eigene
 * Kerberos-Aushandlung entfällt damit vollstaendig.
 */
final class LanpaSsoController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly LanpaSsoToken $tokens,
        private readonly LanpaUserService $users,
        private readonly LanpaIdentityService $identity,
        private readonly SessionManager $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuditLogService $audit,
        private readonly Logger $logger
    ) {
        parent::__construct($view, $currentUser);
    }

    public function enter(Request $request): Response
    {
        $claims = $this->tokens->verify($request->queryString('token'));
        if ($claims === null) {
            return $this->rejected('Der Anmeldeschein ist ungültig oder abgelaufen.', 401);
        }

        $user = $this->users->provision($claims);
        if ($user === null) {
            return $this->rejected('Für Ihr Konto liegt keine gültige Rolle vor.', 403);
        }

        $this->session->regenerate();
        $this->currentUser->login($user);
        unset($_SESSION['_csrf_token']);
        $this->csrf->token();
        $this->identity->remember($claims);
        $this->audit->log('login', 'user', (int) $user['id'], (string) $user['username']);

        $this->logger->info('Anmeldung ueber das Intranet.', [
            'benutzer' => (string) $user['username'],
            'rolle' => (string) $user['role'],
        ]);

        $target = Url::safeLocalPath($request->queryString('next'), '/dashboard');

        return $this->redirect($target)->withHeader('Cache-Control', 'no-store');
    }

    private function rejected(string $message, int $status): Response
    {
        $this->logger->warning('LANPA-SSO: Einstieg abgelehnt.', ['grund' => $message]);

        return $this->render('sso.lanpa-failed', [
            'title' => 'Anmeldung nicht möglich',
            'message' => $message,
        ], $status)->withHeader('Cache-Control', 'no-store');
    }
}
