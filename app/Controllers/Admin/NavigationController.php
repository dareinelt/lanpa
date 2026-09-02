<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class NavigationController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.navigation.index', [
            'pageTitle' => 'Navigation',
            'activeNav' => 'navigation',
            'items' => Container::navigation()->allItems(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin.navigation.form', [
            'pageTitle' => 'Element anlegen',
            'activeNav' => 'navigation',
            'item' => [
                'id' => null,
                'title' => '',
                'url' => '',
                'type' => 'external',
                'icon' => '',
                'short_description' => '',
                'description' => '',
                'sort_order' => Container::navigationRepository()->nextSortOrder(),
                'active' => 1,
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::navigation()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Navigationselement angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Element wurde angelegt.');

        return $this->redirect('/admin/navigation');
    }

    public function edit(Request $request): Response
    {
        $item = Container::navigation()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Element nicht gefunden.');

            return $this->redirect('/admin/navigation');
        }

        return $this->adminView('admin.navigation.form', [
            'pageTitle' => 'Element bearbeiten',
            'activeNav' => 'navigation',
            'item' => $item,
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::navigation()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Navigationselement geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/navigation');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::navigation()->delete($id);
        app_logger()->info('Navigationselement gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Element wurde gelöscht.');

        return $this->redirect('/admin/navigation');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::navigation()->toggle($id);
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException) {
            Session::flash('error', 'Element nicht gefunden.');
        }

        return $this->redirect('/admin/navigation');
    }

    public function move(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $direction = (string) $request->input('direction', '');

        if (!Container::navigation()->move($id, $direction)) {
            Session::flash('error', 'Die Reihenfolge konnte nicht geändert werden.');
        } else {
            Session::flash('success', 'Die Reihenfolge wurde aktualisiert.');
        }

        return $this->redirect('/admin/navigation');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'title' => (string) $request->input('title', ''),
            'url' => (string) $request->input('url', ''),
            'type' => (string) $request->input('type', 'external'),
            'icon' => (string) $request->input('icon', ''),
            'short_description' => (string) $request->input('short_description', ''),
            'description' => (string) $request->input('description', ''),
            'sort_order' => $request->inputInt('sort_order', 0),
            'active' => $request->has('active'),
        ];
    }

    private function formWithErrors(Request $request, ValidationException $exception, ?int $id): Response
    {
        $payload = $this->payload($request);
        $payload['id'] = $id;
        $payload['active'] = $payload['active'] ? 1 : 0;

        return $this->adminView('admin.navigation.form', [
            'pageTitle' => $id === null ? 'Element anlegen' : 'Element bearbeiten',
            'activeNav' => 'navigation',
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
