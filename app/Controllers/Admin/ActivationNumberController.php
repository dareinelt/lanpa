<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Support\Validator;

final class ActivationNumberController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->render([], [], 200);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $errors = [];
        $values = [];

        $template = Validator::cleanText((string) $request->input('sms_code_template', ''), 255);
        $values['sms_code_template'] = $template;

        $timeout = $request->inputInt('sms_code_timeout', 120);
        if ($timeout < 30 || $timeout > 600) {
            $errors['sms_code_timeout'] = 'Bitte einen Wert zwischen 30 und 600 Sekunden angeben.';
        }
        $values['sms_code_timeout'] = (string) $timeout;

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die SMS-Code-Einstellungen.');

            return $this->render($errors, $values, 422);
        }

        Container::settings()->update($values);
        app_logger()->info('SMS-Code-Einstellungen geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Die SMS-Code-Einstellungen wurden gespeichert.');

        return $this->redirect('/admin/aktivierungs-rufnummern');
    }

    public function create(Request $request): Response
    {
        return $this->adminView('admin.activation.form', [
            'pageTitle' => 'Aktivierungs-Rufnummer anlegen',
            'activeNav' => 'activation',
            'item' => [
                'id' => null,
                'phone' => '',
                'alarm_group_id' => null,
                'sort_order' => Container::activationNumberRepository()->nextSortOrder(),
                'active' => 1,
            ],
            'errors' => [],
            'alarmGroups' => Container::alarmGroups()->activeItems(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);

        try {
            $id = Container::activationNumbers()->create($this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, null);
        }

        app_logger()->info('Aktivierungs-Rufnummer angelegt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Aktivierungs-Rufnummer wurde angelegt.');

        return $this->redirect('/admin/aktivierungs-rufnummern');
    }

    public function edit(Request $request): Response
    {
        $item = Container::activationNumbers()->find($request->queryInt('id', 0));
        if ($item === null) {
            Session::flash('error', 'Aktivierungs-Rufnummer nicht gefunden.');

            return $this->redirect('/admin/aktivierungs-rufnummern');
        }

        return $this->adminView('admin.activation.form', [
            'pageTitle' => 'Aktivierungs-Rufnummer bearbeiten',
            'activeNav' => 'activation',
            'item' => $item,
            'errors' => [],
            'alarmGroups' => Container::alarmGroups()->activeItems(),
        ]);
    }

    public function updateItem(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::activationNumbers()->update($id, $this->payload($request));
        } catch (ValidationException $exception) {
            return $this->formWithErrors($request, $exception, $id);
        }

        app_logger()->info('Aktivierungs-Rufnummer geändert.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Änderungen wurden gespeichert.');

        return $this->redirect('/admin/aktivierungs-rufnummern');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        Container::activationNumbers()->delete($id);
        app_logger()->info('Aktivierungs-Rufnummer gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Aktivierungs-Rufnummer wurde gelöscht.');

        return $this->redirect('/admin/aktivierungs-rufnummern');
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::activationNumbers()->toggle($id);
            Session::flash('success', 'Der Status wurde geändert.');
        } catch (ValidationException) {
            Session::flash('error', 'Aktivierungs-Rufnummer nicht gefunden.');
        }

        return $this->redirect('/admin/aktivierungs-rufnummern');
    }

    public function move(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $direction = (string) $request->input('direction', '');

        if (!Container::activationNumbers()->move($id, $direction)) {
            Session::flash('error', 'Die Reihenfolge konnte nicht geändert werden.');
        } else {
            Session::flash('success', 'Die Reihenfolge wurde aktualisiert.');
        }

        return $this->redirect('/admin/aktivierungs-rufnummern');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $settings = Container::settings();
        $current = [
            'sms_code_template' => $settings->get('sms_code_template'),
            'sms_code_timeout' => (string) $settings->int('sms_code_timeout', 120),
        ];

        return $this->adminView('admin.activation.index', [
            'pageTitle' => 'Aktivierungs-Rufnummern',
            'activeNav' => 'activation',
            'values' => array_merge($current, $values),
            'errors' => $errors,
            'items' => Container::activationNumbers()->allItems(),
        ], $status);
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'phone' => (string) $request->input('phone', ''),
            'alarm_group_id' => $request->inputInt('alarm_group_id', 0),
            'sort_order' => $request->inputInt('sort_order', 0),
            'active' => $request->has('active'),
        ];
    }

    private function formWithErrors(Request $request, ValidationException $exception, ?int $id): Response
    {
        $payload = $this->payload($request);
        $payload['id'] = $id;
        $payload['active'] = $payload['active'] ? 1 : 0;

        return $this->adminView('admin.activation.form', [
            'pageTitle' => $id === null ? 'Aktivierungs-Rufnummer anlegen' : 'Aktivierungs-Rufnummer bearbeiten',
            'activeNav' => 'activation',
            'item' => $payload,
            'errors' => $exception->errors(),
            'alarmGroups' => Container::alarmGroups()->activeItems(),
        ], 422);
    }
}
