<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class AnnouncementController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.announcements.index', [
            'pageTitle' => 'Mitteilungen',
            'activeNav' => 'announcements',
            'items' => Container::announcements()->allItems(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin.announcements.form', [
            'pageTitle' => 'Mitteilung anlegen',
            'activeNav' => 'announcements',
            'item' => [
                'id' => null,
                'title' => '',
                'message' => '',
                'active' => 0,
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::announcements()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Mitteilung angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Mitteilung wurde angelegt.');

        return $this->redirect('/admin/mitteilungen');
    }

    public function edit(Request $request): Response
    {
        $item = Container::announcements()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Mitteilung nicht gefunden.');

            return $this->redirect('/admin/mitteilungen');
        }

        return $this->adminView('admin.announcements.form', [
            'pageTitle' => 'Mitteilung bearbeiten',
            'activeNav' => 'announcements',
            'item' => $item,
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::announcements()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Mitteilung geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/mitteilungen');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::announcements()->delete($id);
        app_logger()->info('Mitteilung gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Mitteilung wurde gelöscht.');

        return $this->redirect('/admin/mitteilungen');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $item = Container::announcements()->find($id);

        try {
            Container::announcements()->setActive($id, !(bool) ($item['active'] ?? false));
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException) {
            Session::flash('error', 'Mitteilung nicht gefunden.');
        }

        return $this->redirect('/admin/mitteilungen');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'title' => (string) $request->input('title', ''),
            'message' => (string) $request->input('message', ''),
            'active' => $request->has('active'),
        ];
    }

    private function formWithErrors(Request $request, ValidationException $exception, ?int $id): Response
    {
        $payload = $this->payload($request);
        $payload['id'] = $id;
        $payload['active'] = $payload['active'] ? 1 : 0;

        return $this->adminView('admin.announcements.form', [
            'pageTitle' => $id === null ? 'Mitteilung anlegen' : 'Mitteilung bearbeiten',
            'activeNav' => 'announcements',
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
