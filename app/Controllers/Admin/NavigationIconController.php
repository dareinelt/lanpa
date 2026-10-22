<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

/**
 * Pflege des Icon-Katalogs fuer Navigationskacheln.
 */
final class NavigationIconController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->renderIndex();
    }

    public function store(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $alias = (string) $request->input('alias', '');

        try {
            $id = Container::navigationIcons()->create($alias, $this->uploadedFile($request));
        } catch (ValidationException $exception) {
            return $this->renderIndex($exception->errors(), $alias, 422);
        }

        app_logger()->info('Kachel-Icon angelegt.', [
            'id' => $id,
            'alias' => $alias,
            'admin' => Container::auth()->username(),
        ]);
        Session::flash('success', 'Das Icon wurde angelegt.');

        return $this->redirect('/admin/navigation/icons');
    }

    public function replace(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::navigationIcons()->replaceFile($id, $this->uploadedFile($request));
        } catch (ValidationException $exception) {
            return $this->redirectWithError($exception);
        }

        app_logger()->info('Kachel-Icon ersetzt.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Icon wurde ersetzt.');

        return $this->redirect('/admin/navigation/icons');
    }

    public function rename(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);
        $alias = (string) $request->input('alias', '');

        try {
            Container::navigationIcons()->rename($id, $alias);
        } catch (ValidationException $exception) {
            return $this->redirectWithError($exception);
        }

        app_logger()->info('Kachel-Icon umbenannt.', [
            'id' => $id,
            'alias' => strtolower(trim($alias)),
            'admin' => Container::auth()->username(),
        ]);
        Session::flash('success', 'Der Alias wurde geändert.');

        return $this->redirect('/admin/navigation/icons');
    }

    public function delete(Request $request): Response
    {
        $this->requireValidCsrf($request);
        $id = $request->inputInt('id', 0);

        try {
            Container::navigationIcons()->delete($id);
        } catch (ValidationException $exception) {
            return $this->redirectWithError($exception);
        }

        app_logger()->info('Kachel-Icon gelöscht.', ['id' => $id, 'admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Icon wurde gelöscht.');

        return $this->redirect('/admin/navigation/icons');
    }

    /**
     * @param array<string,string> $errors
     */
    private function renderIndex(array $errors = [], string $alias = '', int $status = 200): Response
    {
        return $this->adminView('admin.navigation.icons', [
            'pageTitle' => 'Icon-Katalog',
            'activeNav' => 'navigation_icons',
            'icons' => Container::navigationIcons()->all(),
            'errors' => $errors,
            'alias' => $alias,
            'maxIconKb' => (int) (Config::get('app.max_nav_icon_bytes', 256 * 1024) / 1024),
        ], $status);
    }

    private function redirectWithError(ValidationException $exception): Response
    {
        $errors = $exception->errors();
        $first = reset($errors);
        Session::flash('error', is_string($first) && $first !== '' ? $first : 'Bitte prüfen Sie die Eingaben.');

        return $this->redirect('/admin/navigation/icons');
    }

    /**
     * @return array<string,mixed>
     */
    private function uploadedFile(Request $request): array
    {
        $file = $request->files['svg'] ?? null;

        return is_array($file) ? $file : [];
    }
}
