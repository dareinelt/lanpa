<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class AlarmGroupController extends AdminController
{
    public function create(Request $request): Response
    {
        return $this->adminView('admin.alarm_group', [
            'pageTitle' => 'Alarmierungsgruppe anlegen',
            'activeNav' => 'alarm',
            'item' => [
                'id' => null,
                'group_number' => '',
                'description' => '',
                'sort_order' => Container::alarmGroupRepository()->nextSortOrder(),
                'active' => 1,
            ],
            'errors' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::alarmGroups()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Alarmierungsgruppe angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Alarmierungsgruppe wurde angelegt.');

        return $this->redirect('/admin/alarmierung');
    }

    public function edit(Request $request): Response
    {
        $item = Container::alarmGroups()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Alarmierungsgruppe nicht gefunden.');

            return $this->redirect('/admin/alarmierung');
        }

        return $this->adminView('admin.alarm_group', [
            'pageTitle' => 'Alarmierungsgruppe bearbeiten',
            'activeNav' => 'alarm',
            'item' => $item,
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::alarmGroups()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Alarmierungsgruppe geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/alarmierung');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::alarmGroups()->delete($id);
        app_logger()->info('Alarmierungsgruppe gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Alarmierungsgruppe wurde gelöscht.');

        return $this->redirect('/admin/alarmierung');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::alarmGroups()->toggle($id);
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException) {
            Session::flash('error', 'Alarmierungsgruppe nicht gefunden.');
        }

        return $this->redirect('/admin/alarmierung');
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'group_number' => (string) $request->input('group_number', ''),
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

        return $this->adminView('admin.alarm_group', [
            'pageTitle' => $id === null ? 'Alarmierungsgruppe anlegen' : 'Alarmierungsgruppe bearbeiten',
            'activeNav' => 'alarm',
            'item' => $payload,
            'errors' => $exception->errors(),
        ], 422);
    }
}
