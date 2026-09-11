<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Repositories\AdminUserRepository;
use App\Security\Session;

final class AdminUserController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.users.index', [
            'pageTitle' => 'Benutzer',
            'activeNav' => 'users',
            'items' => Container::adminUsers()->allItems(),
            'currentUserId' => Container::auth()->id(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin.users.form', [
            'pageTitle' => 'Benutzer anlegen',
            'activeNav' => 'users',
            'item' => [
                'id' => null,
                'username' => '',
                'role' => AdminUserRepository::ROLE_ADMIN,
                'active' => 1,
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::adminUsers()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Benutzer angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Der Benutzer wurde angelegt.');

        return $this->redirect('/admin/benutzer');
    }

    public function edit(Request $request): Response
    {
        $item = Container::adminUsers()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Benutzer nicht gefunden.');

            return $this->redirect('/admin/benutzer');
        }

        return $this->adminView('admin.users.form', [
            'pageTitle' => 'Benutzer bearbeiten',
            'activeNav' => 'users',
            'item' => $item,
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::adminUsers()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Benutzer geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/benutzer');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::adminUsers()->delete($id);
            app_logger()->info('Benutzer gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
            Session::flash('success', 'Der Benutzer wurde gelöscht.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            Session::flash('error', $errors === [] ? 'Löschen nicht möglich.' : (string) reset($errors));
        }

        return $this->redirect('/admin/benutzer');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::adminUsers()->toggle($id);
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            Session::flash('error', $errors === [] ? 'Status konnte nicht geändert werden.' : (string) reset($errors));
        }

        return $this->redirect('/admin/benutzer');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'username' => (string) $request->input('username', ''),
            'role' => (string) $request->input('role', ''),
            'password' => (string) $request->input('password', ''),
            'active' => $request->has('active'),
        ];
    }

    private function formWithErrors(Request $request, ValidationException $exception, ?int $id): Response
    {
        $payload = $this->payload($request);
        $payload['id'] = $id;
        $payload['active'] = $payload['active'] ? 1 : 0;
        unset($payload['password']);

        return $this->adminView('admin.users.form', [
            'pageTitle' => $id === null ? 'Benutzer anlegen' : 'Benutzer bearbeiten',
            'activeNav' => 'users',
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
