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
        ]);
    }

    public function toggle(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $id = $request->inputInt('id', 0);
        $visible = $request->inputInt('visible', 1) === 1;
        $term = (string) $request->input('q', '');

        try {
            Container::phonebook()->setVisible($id, $visible);
            Session::flash('success', $visible ? 'Der Eintrag wird eingeblendet.' : 'Der Eintrag wird ausgeblendet.');
        } catch (ValidationException) {
            Session::flash('error', 'Eintrag nicht gefunden.');
        }

        $query = $term !== '' ? '?q=' . rawurlencode($term) : '';

        return $this->redirect('/admin/telefonliste' . $query);
    }
}
