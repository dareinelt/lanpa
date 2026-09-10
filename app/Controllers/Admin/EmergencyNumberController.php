<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class EmergencyNumberController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.emergency.index', [
            'pageTitle' => 'Notfallnummern',
            'activeNav' => 'emergency',
            'items' => Container::emergencyNumbers()->allItems(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin.emergency.form', [
            'pageTitle' => 'Notfallnummer anlegen',
            'activeNav' => 'emergency',
            'item' => [
                'id' => null,
                'label' => '',
                'phone' => '',
                'description' => '',
                'sort_order' => Container::emergencyNumberRepository()->nextSortOrder(),
                'active' => 1,
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::emergencyNumbers()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Notfallnummer angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Notfallnummer wurde angelegt.');

        return $this->redirect('/admin/notfallnummern');
    }

    public function edit(Request $request): Response
    {
        $item = Container::emergencyNumbers()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Notfallnummer nicht gefunden.');

            return $this->redirect('/admin/notfallnummern');
        }

        return $this->adminView('admin.emergency.form', [
            'pageTitle' => 'Notfallnummer bearbeiten',
            'activeNav' => 'emergency',
            'item' => $item,
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::emergencyNumbers()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Notfallnummer geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/notfallnummern');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::emergencyNumbers()->delete($id);
        app_logger()->info('Notfallnummer gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Notfallnummer wurde gelöscht.');

        return $this->redirect('/admin/notfallnummern');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::emergencyNumbers()->toggle($id);
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException) {
            Session::flash('error', 'Notfallnummer nicht gefunden.');
        }

        return $this->redirect('/admin/notfallnummern');
    }

    public function move(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $direction = (string) $request->input('direction', '');

        if (!Container::emergencyNumbers()->move($id, $direction)) {
            Session::flash('error', 'Die Reihenfolge konnte nicht geändert werden.');
        } else {
            Session::flash('success', 'Die Reihenfolge wurde aktualisiert.');
        }

        return $this->redirect('/admin/notfallnummern');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'label' => (string) $request->input('label', ''),
            'phone' => (string) $request->input('phone', ''),
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

        return $this->adminView('admin.emergency.form', [
            'pageTitle' => $id === null ? 'Notfallnummer anlegen' : 'Notfallnummer bearbeiten',
            'activeNav' => 'emergency',
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
