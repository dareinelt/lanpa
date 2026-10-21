<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\SessionManager;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Support\ModuleNavigation;
use App\Support\Url;

final class AuthController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AuthService $auth,
        private readonly SessionManager $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuditLogService $audit,
        private readonly UserRepository $users,
        private readonly Config $config
    ) {
        parent::__construct($view, $currentUser);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            return $this->redirect($this->startUrl());
        }

        return $this->render('auth.login', [
            'redirect' => $this->safeRedirect($request->queryString('redirect')),
        ]);
    }

    public function login(Request $request): Response
    {
        $username = $request->string('username');
        $password = (string) $request->input('password', '');
        $redirect = $this->safeRedirect($request->string('redirect'));

        $user = $this->auth->attempt($username, $password);
        if ($user === null) {
            return $this->render('auth.login', [
                'error' => 'Anmeldung fehlgeschlagen. Bitte Benutzername und Passwort prüfen.',
                'username' => $username,
                'redirect' => $redirect,
            ], 401);
        }

        $this->session->regenerate();
        $this->currentUser->login($user);
        unset($_SESSION['_csrf_token']);
        $this->csrf->token();
        $this->audit->log('login', 'user', (int) $user['id'], (string) $user['username']);

        return $this->redirect($redirect ?: $this->startUrl((string) ($user['last_module'] ?? '')));
    }

    /**
     * Einstieg über die Anwendungsbasis: öffnet das zuletzt verwendete Modul.
     */
    public function start(Request $request): Response
    {
        return $this->redirect($this->startUrl());
    }

    public function logout(Request $request): Response
    {
        $id = $this->currentUser->id();
        if ($id !== null) {
            $this->audit->log('logout', 'user', $id, $this->currentUser->username());
        }
        $this->currentUser->logout();
        $this->session->destroy();

        return $this->redirect('/login');
    }

    /**
     * Startseite für den angemeldeten Benutzer: zuletzt geöffnetes Modul, sonst Assetverwaltung.
     *
     * @param string|null $lastModule Bekannter Wert (z. B. aus der Anmeldung); null lädt ihn aus der Datenbank.
     */
    private function startUrl(?string $lastModule = null): string
    {
        if ($lastModule === null) {
            $account = $this->users->find((int) $this->currentUser->id());
            $lastModule = (string) ($account['last_module'] ?? '');
        }

        return ModuleNavigation::startUrl(
            $lastModule,
            fn (string $permission): bool => $this->currentUser->can($permission),
            (bool) $this->config->get('helpdesk.enabled', true)
        );
    }

    private function safeRedirect(string $target): string
    {
        return Url::safeLocalPath($target);
    }
}
