<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class ImportantLinkController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.important_links.index', [
            'pageTitle' => 'Wichtige Links',
            'activeNav' => 'important_links',
            'items' => Container::importantLinks()->allItems(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin.important_links.form', [
            'pageTitle' => 'Link anlegen',
            'activeNav' => 'important_links',
            'item' => [
                'id' => null,
                'title' => '',
                'url' => '',
                'active' => 1,
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::importantLinks()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Wichtiger Link angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Der Link wurde angelegt.');

        return $this->redirect('/admin/wichtige-links');
    }

    public function edit(Request $request): Response
    {
        $item = Container::importantLinks()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Link nicht gefunden.');

            return $this->redirect('/admin/wichtige-links');
        }

        return $this->adminView('admin.important_links.form', [
            'pageTitle' => 'Link bearbeiten',
            'activeNav' => 'important_links',
            'item' => $item,
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::importantLinks()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Wichtiger Link geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/wichtige-links');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::importantLinks()->delete($id);
        app_logger()->info('Wichtiger Link gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Der Link wurde gelöscht.');

        return $this->redirect('/admin/wichtige-links');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::importantLinks()->toggle($id);
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException) {
            Session::flash('error', 'Link nicht gefunden.');
        }

        return $this->redirect('/admin/wichtige-links');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'title' => (string) $request->input('title', ''),
            'url' => (string) $request->input('url', ''),
            'active' => $request->has('active'),
        ];
    }

    private function formWithErrors(Request $request, ValidationException $exception, ?int $id): Response
    {
        $payload = $this->payload($request);
        $payload['id'] = $id;
        $payload['active'] = $payload['active'] ? 1 : 0;

        return $this->adminView('admin.important_links.form', [
            'pageTitle' => $id === null ? 'Link anlegen' : 'Link bearbeiten',
            'activeNav' => 'important_links',
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
