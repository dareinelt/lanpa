<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\SessionManager;
use App\Core\View;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Support\Url;

final class AuthController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly AuthService $auth,
        private readonly SessionManager $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AuditLogService $audit
    ) {
        parent::__construct($view, $currentUser);
    }

    public function showLogin(Request $request): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            return $this->redirect('/dashboard');
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

        return $this->redirect($redirect ?: '/dashboard');
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

    private function safeRedirect(string $target): string
    {
        return Url::safeLocalPath($target);
    }
}
