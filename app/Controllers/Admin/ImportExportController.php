<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class ImportExportController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.backup', [
            'pageTitle' => 'Sicherung',
            'activeNav' => 'backup',
        ]);
    }

    public function export(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $contents = Container::backup()->create();
        $filename = 'lanpa-sicherung-' . gmdate('Y-m-d') . '.zip';

        app_logger()->info('Sicherung erstellt.', ['admin' => Container::auth()->username()]);

        return Response::download($contents, $filename, 'application/zip');
    }

    public function import(Request $request): Response
    {
        $this->requireValidCsrf($request);

        /** @var array<string,mixed> $file */
        $file = $request->files['archive'] ?? ['error' => UPLOAD_ERR_NO_FILE];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Session::flash('error', 'Bitte eine gültige Sicherungsdatei auswählen.');

            return $this->redirect('/admin/sicherung');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            Session::flash('error', 'Die Sicherungsdatei konnte nicht gelesen werden.');

            return $this->redirect('/admin/sicherung');
        }

        $contents = file_get_contents($tmpName);
        if ($contents === false) {
            Session::flash('error', 'Die Sicherungsdatei konnte nicht gelesen werden.');

            return $this->redirect('/admin/sicherung');
        }

        try {
            Container::import()->import($contents);
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect('/admin/sicherung');
        }

        app_logger()->info('Sicherung eingespielt.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Die Sicherung wurde erfolgreich eingespielt.');

        return $this->redirect('/admin/sicherung');
    }
}
