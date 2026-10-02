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
use App\Security\SsoAuth;

final class AuthController extends Controller
{
    public const WINDOWS_LOGIN_PATH = '/admin/login/windows';

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

    /**
     * Anmeldung per Windows-Anmeldung (SSO) fuer Mitglieder der unter
     * Benutzer → "Administratoren aus AD-Gruppen" eingetragenen Gruppen.
     * Ohne erkannte Anmeldung einmal ueber den Anmeldepunkt /sso.
     */
    public function windowsLogin(Request $request): Response
    {
        $sso = Container::sso();
        if (!$sso->isEnabled()) {
            return $this->renderLogin('Die Windows-Anmeldung ist nicht eingerichtet.', '', 404);
        }

        $user = $sso->resolve($request);
        if ($user === null) {
            if ($request->query('versucht') !== '1' && !$sso->isFake()) {
                return Response::redirect(SsoAuth::loginUrl(self::WINDOWS_LOGIN_PATH . '?versucht=1'))
                    ->withHeader('Cache-Control', 'no-store');
            }

            return $this->renderLogin('Ihre Windows-Anmeldung wurde nicht erkannt. Bitte melden Sie sich an einem Domänen-Rechner an oder verwenden Sie ein Administrationskonto.', '', 401);
        }

        $role = Container::adminGroups()->intranetRole($user['groups']);
        if ($role === null) {
            app_logger()->warning('Windows-Anmeldung am Adminbereich ohne berechtigte AD-Gruppe.', ['user' => $user['office_uid']]);

            return $this->renderLogin(
                sprintf('Ihr Konto „%s“ ist in keiner AD-Gruppe mit Zugriff auf den Adminbereich.', $user['display_name']),
                '',
                403
            );
        }

        Container::auth()->loginDirectory($user, $role);
        app_logger()->info('Admin-Login per Windows-Anmeldung (AD-Gruppe).', ['user' => $user['office_uid']]);
        Session::flash('success', 'Willkommen im Administrationsbereich.');

        return $this->redirect('/admin')->withHeader('Cache-Control', 'no-store');
    }

    private function renderLogin(?string $error = null, string $username = '', int $status = 200): Response
    {
        $sso = Container::sso();
        $windows = null;
        if ($sso->isEnabled()) {
            $windows = ['user' => null];
            try {
                $user = $sso->resolve(Request::fromGlobals());
                if ($user !== null && Container::adminGroups()->intranetRole($user['groups']) !== null) {
                    $windows['user'] = $user['display_name'];
                }
            } catch (\Throwable) {
                // Ohne Gruppentabelle (Migration ausstehend) nur die Schaltflaeche.
            }
        }

        $html = View::render('admin.login', [
            'appName' => Container::settings()->get('site_title'),
            'themeCss' => Container::theme()->css(),
            'flashes' => Session::takeFlash(),
            'csrfToken' => Csrf::token(),
            'error' => $error,
            'username' => $username,
            'windows' => $windows,
            'windowsLoginPath' => self::WINDOWS_LOGIN_PATH,
            'assetVersion' => $this->assetVersion(),
        ], 'layouts.auth');

        return Response::html($html, $status);
    }
}
