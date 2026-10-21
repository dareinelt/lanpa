<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
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
use App\Support\ModuleNavigation;
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
    /** Ziel, das das Intranet ohne ausdruecklichen Wunsch uebergibt. */
    private const DEFAULT_TARGET = '/dashboard';

    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly LanpaSsoToken $tokens,
        private readonly LanpaUserService $users,
        private readonly LanpaIdentityService $identity,
        private readonly SessionManager $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuditLogService $audit,
        private readonly Logger $logger,
        private readonly Config $config
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

        // Ohne ausdrueckliches Ziel (bzw. mit dem Standardziel /dashboard) entscheidet das
        // zuletzt geoeffnete Modul des Benutzers.
        $requested = Url::safeLocalPath($request->queryString('next'));
        $target = $requested === '' || $requested === self::DEFAULT_TARGET
            ? $this->startUrl($user)
            : $requested;

        return $this->redirect($target)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $user */
    private function startUrl(array $user): string
    {
        return ModuleNavigation::startUrl(
            (string) ($user['last_module'] ?? ''),
            fn (string $permission): bool => $this->currentUser->can($permission),
            (bool) $this->config->get('helpdesk.enabled', true)
        );
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
