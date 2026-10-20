<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Services\UserService;

/** Administration → Benutzer: lokale Konten, Rollen, Aktiv/Inaktiv, Passwort zurücksetzen. */
final class UserController extends BaseController
{
    public function __construct(
        View $view,
        CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly UserService $service,
        private readonly Permissions $permissions
    ) {
        parent::__construct($view, $currentUser);
    }

    public function index(Request $request): Response
    {
        $status = $request->queryString('status') ?: 'active';
        $filters = [
            'q' => trim($request->queryString('q')),
            'role' => $request->queryString('role'),
            'status' => in_array($status, ['active', 'inactive'], true) ? $status : '',
        ];

        return $this->render('users.index', [
            'title' => 'Benutzer',
            'activeNav' => 'admin',
            'areaLabel' => 'Administration',
            'rows' => $this->users->search($filters),
            'filters' => $filters,
            'roleLabels' => $this->permissions->roleLabels(),
            'groupLabels' => $this->permissions->groupLabels(),
            'basePath' => '/admin/users',
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->renderForm(null);
    }

    public function store(Request $request): Response
    {
        try {
            $id = $this->service->create($request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/admin/users/new');
        }
        $this->flash('success', 'Benutzer wurde angelegt.');

        return $this->redirect('/admin/users/' . $id . '/edit');
    }

    public function edit(Request $request): Response
    {
        return $this->renderForm($this->findOrFail($this->users->find($request->paramInt('id'))));
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->users->find($id));
        try {
            $this->service->update($id, $row, $request->all());
        } catch (ValidationException $e) {
            $this->withOldInput($request, $e->errors());

            return $this->redirect('/admin/users/' . $id . '/edit');
        }
        $this->flash('success', 'Benutzer wurde gespeichert.');

        return $this->redirect('/admin/users');
    }

    public function toggleActive(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->users->find($id));
        $active = (int) $row['is_active'] !== 1;
        try {
            $this->service->setActive($id, $row, $active);
        } catch (ValidationException $e) {
            $this->flash('error', implode(' ', $e->errors()));

            return $this->redirect($this->backUrl($request, '/admin/users'));
        }
        $this->flash('success', 'Benutzer „' . $row['username'] . '“ wurde ' . ($active ? 'aktiviert' : 'deaktiviert') . '.');

        return $this->redirect($this->backUrl($request, '/admin/users'));
    }

    public function resetPassword(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->findOrFail($this->users->find($id));
        try {
            $this->service->resetPassword($id, $row, (string) $request->input('password', ''), (string) $request->input('password_confirmation', ''));
        } catch (ValidationException $e) {
            $_SESSION['_errors'] = $e->errors();

            return $this->redirect('/admin/users/' . $id . '/edit');
        }
        $this->flash('success', 'Passwort für „' . $row['username'] . '“ wurde neu gesetzt.');

        return $this->redirect('/admin/users/' . $id . '/edit');
    }

    /** @param array<string,mixed>|null $row */
    private function renderForm(?array $row): Response
    {
        return $this->render('users.form', [
            'title' => $row === null ? 'Benutzer anlegen' : 'Benutzer bearbeiten',
            'activeNav' => 'admin',
            'areaLabel' => 'Administration',
            'row' => $row,
            'roleLabels' => $this->permissions->roleLabels(),
            'groups' => $this->users->permissionGroups(),
            'selectedGroups' => $row === null ? [] : $this->users->groupIdsForUser((int) $row['id']),
            'isSelf' => $row !== null && (int) $row['id'] === $this->currentUser->id(),
            'passwordMin' => UserService::PASSWORD_MIN_LENGTH,
        ]);
    }
}
