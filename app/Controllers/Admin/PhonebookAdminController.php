<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class PhonebookAdminController extends AdminController
{
    public function index(Request $request): Response
    {
        $term = (string) $request->query('q', '');

        return $this->adminView('admin.phonebook.index', [
            'pageTitle' => 'Telefonliste',
            'activeNav' => 'phonebook',
            'items' => Container::phonebook()->allEntries($term),
            'term' => $term,
            'pageScript' => 'admin-phonebook.js',
        ]);
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $id = $request->inputInt('id', 0);
        $visible = $request->inputInt('visible', 1) === 1;
        $term = (string) $request->input('q', '');

        $success = $visible ? 'Der Eintrag wird eingeblendet.' : 'Der Eintrag wird ausgeblendet.';
        $error = 'Eintrag nicht gefunden.';

        $ok = true;
        try {
            Container::phonebook()->setVisible($id, $visible);
        } catch (ValidationException) {
            $ok = false;
        }

        if ($this->wantsJson($request)) {
            return Response::json([
                'ok' => $ok,
                'visible' => $ok ? $visible : null,
                'message' => $ok ? $success : $error,
            ], $ok ? 200 : 404);
        }

        Session::flash($ok ? 'success' : 'error', $ok ? $success : $error);

        $query = $term !== '' ? '?q=' . rawurlencode($term) : '';

        return $this->redirect('/admin/telefonliste' . $query);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = (string) ($request->server['HTTP_ACCEPT'] ?? '');

        return str_contains($accept, 'application/json');
    }
}
