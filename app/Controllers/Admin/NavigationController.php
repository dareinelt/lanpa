<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Support\Validator;

final class NavigationController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.navigation.index', [
            'pageTitle' => 'Navigation',
            'activeNav' => 'navigation',
            'items' => Container::navigation()->allItems(),
            'navTreeMode' => Container::settings()->navTreeMode(),
            'pageScript' => 'navigation.js',
        ]);
    }

    public function create(Request $request): Response
    {
        $type = (string) $request->query('type', 'external');
        if (!Validator::isNavigationType($type)) {
            $type = 'external';
        }

        $parentId = $request->queryInt('parent_id', 0);
        if ($type !== 'subpage' && $type !== 'page') {
            $parentId = 0;
        }

        return $this->adminView('admin.navigation.form', [
            'pageTitle' => 'Element anlegen',
            'activeNav' => 'navigation',
            'pageScript' => 'editor.js',
            'subpages' => Container::navigation()->subpages(),
            'item' => [
                'id' => null,
                'title' => '',
                'url' => '',
                'type' => $type,
                'parent_id' => $parentId > 0 ? $parentId : null,
                'icon' => '',
                'short_description' => '',
                'description' => '',
                'content' => '',
                'sort_order' => Container::navigationRepository()->nextSortOrder($parentId > 0 ? $parentId : null),
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
            'pageScript' => 'editor.js',
            'subpages' => Container::navigation()->subpages(),
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

    public function updateViewMode(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $enabled = $request->has('nav_tree_mode');
        Container::settings()->update(['nav_tree_mode' => $enabled ? '1' : '0']);
        app_logger()->info('Navigationsansicht geändert.', [
            'admin' => Container::auth()->username(),
            'nav_tree_mode' => $enabled ? '1' : '0',
        ]);
        Session::flash('success', $enabled ? 'Die Baumansicht ist jetzt aktiv.' : 'Die Listenansicht ist jetzt aktiv.');

        return $this->redirect('/admin/navigation');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        $bgOpacity = $request->input('background_opacity', '');
        return [
            'title' => (string) $request->input('title', ''),
            'url' => (string) $request->input('url', ''),
            'type' => (string) $request->input('type', 'external'),
            'parent_id' => $request->inputInt('parent_id', 0),
            'icon' => (string) $request->input('icon', ''),
            'background_color' => (string) $request->input('background_color', ''),
            'background_opacity' => $bgOpacity !== '' ? (int) $bgOpacity : null,
            'short_description' => (string) $request->input('short_description', ''),
            'description' => (string) $request->input('description', ''),
            'content' => (string) $request->input('content', ''),
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
            'pageScript' => 'editor.js',
            'subpages' => Container::navigation()->subpages(),
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
