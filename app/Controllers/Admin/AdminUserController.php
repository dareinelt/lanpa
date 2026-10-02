<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Repositories\AdminUserRepository;
use App\Security\Session;
use App\Services\AdminGroupService;

final class AdminUserController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->renderIndex();
    }

    /**
     * Traegt eine AD-Gruppe fuer Intranet- oder Nextcloud-Administratoren ein.
     */
    public function storeGroup(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $target = (string) $request->input('target', '');
        $group = (string) $request->input('group_name', '');

        try {
            Container::adminGroups()->addRule($target, $group, (string) Container::auth()->username());
        } catch (ValidationException $exception) {
            Session::flash('error', 'Bitte prüfen Sie die Angaben zur AD-Gruppe.');

            return $this->renderIndex($exception->errors(), [$target . '_group' => $group], 422);
        }

        app_logger()->info('AD-Gruppe für Administratoren eingetragen.', [
            'target' => $target,
            'group' => $group,
            'admin' => Container::auth()->username(),
        ]);
        $message = 'Die AD-Gruppe „' . trim($group) . '“ wurde eingetragen.';
        if ($target === AdminGroupService::TARGET_NEXTCLOUD) {
            $this->flashNextcloud($message);
        } else {
            Session::flash('success', $message);
        }

        return $this->redirect('/admin/benutzer#ad-' . $target);
    }

    public function deleteGroup(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $rule = Container::adminGroups()->deleteRule($request->inputInt('id', 0));
        if ($rule === null) {
            Session::flash('error', 'Die AD-Gruppe wurde nicht gefunden.');

            return $this->redirect('/admin/benutzer');
        }

        app_logger()->info('AD-Gruppe für Administratoren entfernt.', [
            'target' => $rule['target'],
            'group' => $rule['group_name'],
            'admin' => Container::auth()->username(),
        ]);
        $message = 'Die AD-Gruppe „' . $rule['group_name'] . '“ wurde entfernt.';
        if ($rule['target'] === AdminGroupService::TARGET_NEXTCLOUD) {
            $this->flashNextcloud($message);
        } else {
            Session::flash('success', $message);
        }

        // Wer sich selbst den Zugriff entzogen hat, landet beim naechsten
        // Aufruf wieder auf der Anmeldeseite.
        return $this->redirect('/admin/benutzer#ad-' . $rule['target']);
    }

    public function pushNextcloud(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $service = Container::nextcloudAdmins();
        if (!$service->officeEnabled()) {
            Session::flash('error', 'Office ist nicht aktiviert (OFFICE_ENABLED) – es gibt kein Nextcloud, an das übertragen werden kann.');

            return $this->redirect('/admin/benutzer#ad-nextcloud');
        }

        $result = $service->pushToNextcloud();
        Session::flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('/admin/benutzer#ad-nextcloud');
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
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function renderIndex(array $errors = [], array $values = [], int $status = 200): Response
    {
        $groups = Container::adminGroups();
        $nextcloud = Container::nextcloudAdmins();
        try {
            $directory = [
                'intranet_rules' => $groups->rules(AdminGroupService::TARGET_INTRANET),
                'intranet_members' => $groups->members(AdminGroupService::TARGET_INTRANET),
                'nextcloud_rules' => $groups->rules(AdminGroupService::TARGET_NEXTCLOUD),
                'nextcloud_members' => $groups->members(AdminGroupService::TARGET_NEXTCLOUD),
                'nextcloud_last_push' => $nextcloud->lastPush(),
                'available' => true,
            ];
        } catch (\PDOException) {
            // Migration 021 noch nicht ausgefuehrt.
            $directory = ['available' => false];
        }

        return $this->adminView('admin.users.index', [
            'pageTitle' => 'Benutzer',
            'activeNav' => 'users',
            'items' => Container::adminUsers()->allItems(),
            'currentUserId' => Container::auth()->id(),
            'directory' => $directory,
            'ssoEnabled' => Container::sso()->isEnabled(),
            'officeEnabled' => $nextcloud->officeEnabled(),
            'errors' => $errors,
            'values' => $values,
            'pageScript' => 'admin-group-autocomplete.js',
        ], $status);
    }

    /**
     * Speichern und – bei aktivem Office – sofort an Nextcloud uebertragen.
     */
    private function flashNextcloud(string $message): void
    {
        $result = Container::nextcloudAdmins()->pushIfEnabled();
        if ($result === null) {
            Session::flash('success', $message);
        } elseif ($result['ok']) {
            Session::flash('success', $message . ' ' . $result['message']);
        } else {
            Session::flash('success', $message);
            Session::flash('error', 'Übertragung an Nextcloud fehlgeschlagen: ' . $result['message'] . ' Die Gesundheitsprüfung wiederholt den Abgleich automatisch.');
        }
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
