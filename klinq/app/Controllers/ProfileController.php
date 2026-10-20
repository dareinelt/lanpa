<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\SessionManager;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Services\UserService;

/** Eigenes Profil: Passwort ändern (lokale Konten). */
final class ProfileController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly UserService $service,
        private readonly SessionManager $session
    ) {
        parent::__construct($view, $currentUser);
    }

    public function passwordForm(Request $request): Response
    {
        $user = $this->findOrFail($this->users->find((int) $this->currentUser->id()));

        return $this->render('profile.password', [
            'title' => 'Passwort ändern',
            'activeNav' => '',
            'account' => $user,
            'passwordMin' => UserService::PASSWORD_MIN_LENGTH,
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->findOrFail($this->users->find((int) $this->currentUser->id()));
        try {
            $this->service->changeOwnPassword(
                $user,
                (string) $request->input('current_password', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', '')
            );
        } catch (ValidationException $e) {
            $_SESSION['_errors'] = $e->errors();

            return $this->redirect('/profile/password');
        }
        // Session-ID erneuern, damit ggf. mitgelesene Sitzungen ungültig werden
        $this->session->regenerate();
        $this->flash('success', 'Ihr Passwort wurde geändert.');

        return $this->redirect('/dashboard');
    }
}
