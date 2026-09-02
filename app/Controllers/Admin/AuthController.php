<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\Csrf;
use App\Security\Session;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if (Container::auth()->check()) {
            return $this->redirect('/admin');
        }

        return $this->renderLogin();
    }

    public function login(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $auth = Container::auth();
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

        if ($auth->isLockedOut()) {
            app_logger()->warning('Login gesperrt (zu viele Fehlversuche).');

            return $this->renderLogin(
                sprintf('Zu viele Fehlversuche. Bitte in %d Sekunden erneut versuchen.', $auth->lockedForSeconds()),
                $username,
                429
            );
        }

        if ($username === '' || $password === '') {
            return $this->renderLogin('Bitte Benutzername und Passwort angeben.', $username, 422);
        }

        if (!$auth->attempt($username, $password)) {
            // Passwoerter werden niemals geloggt.
            app_logger()->warning('Fehlgeschlagener Admin-Login.', ['username' => $username]);

            return $this->renderLogin('Anmeldung fehlgeschlagen.', $username, 401);
        }

        app_logger()->info('Admin-Login erfolgreich.', ['username' => $username]);
        Session::flash('success', 'Willkommen im Administrationsbereich.');

        return $this->redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $this->requireValidCsrf($request);
        Container::auth()->logout();
        Session::flash('success', 'Sie wurden abgemeldet.');

        return $this->redirect('/admin/login');
    }

    private function renderLogin(?string $error = null, string $username = '', int $status = 200): Response
    {
        $html = View::render('admin.login', [
            'appName' => Container::settings()->get('site_title'),
            'themeCss' => Container::theme()->css(),
            'flashes' => Session::takeFlash(),
            'csrfToken' => Csrf::token(),
            'error' => $error,
            'username' => $username,
            'assetVersion' => $this->assetVersion(),
        ], 'layouts.auth');

        return Response::html($html, $status);
    }
}
