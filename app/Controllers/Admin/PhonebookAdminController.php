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
        $filters = $this->filtersFrom($request);

        return $this->adminView('admin.phonebook.index', [
            'pageTitle' => 'Telefonliste',
            'activeNav' => 'phonebook',
            'items' => Container::phonebook()->allEntries($term, $filters),
            'term' => $term,
            'filters' => $filters,
            'pageScript' => 'admin-phonebook.js',
        ]);
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $id = $request->inputInt('id', 0);
        $visible = $request->inputInt('visible', 1) === 1;
        $term = (string) $request->input('q', '');
        $filters = $this->filtersFrom($request, true);

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

        $query = $this->buildQuery($term, $filters);

        return $this->redirect('/admin/telefonliste' . $query);
    }

    /**
     * Liest die Bool-Filter aus der Anfrage (GET oder POST).
     *
     * @return array{has_email:bool,is_active:bool,has_phone:bool,is_visible:bool}
     */
    private function filtersFrom(Request $request, bool $fromInput = false): array
    {
        $read = $fromInput ? 'input' : 'query';

        return [
            'has_email' => $request->$read('has_email') === '1',
            'is_active' => $request->$read('is_active') === '1',
            'has_phone' => $request->$read('has_phone') === '1',
            'is_visible' => $request->$read('is_visible') === '1',
        ];
    }

    /**
     * @param array{has_email:bool,is_active:bool,has_phone:bool,is_visible:bool} $filters
     */
    private function buildQuery(string $term, array $filters): string
    {
        $parts = [];

        if ($term !== '') {
            $parts[] = 'q=' . rawurlencode($term);
        }
        if ($filters['has_email']) {
            $parts[] = 'has_email=1';
        }
        if ($filters['is_active']) {
            $parts[] = 'is_active=1';
        }
        if ($filters['has_phone']) {
            $parts[] = 'has_phone=1';
        }
        if ($filters['is_visible']) {
            $parts[] = 'is_visible=1';
        }

        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    private function wantsJson(Request $request): bool
    {
        $accept = (string) ($request->server['HTTP_ACCEPT'] ?? '');

        return str_contains($accept, 'application/json');
    }
}
