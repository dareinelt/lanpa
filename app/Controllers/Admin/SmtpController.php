<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;

final class SmtpController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->adminView('admin.smtp', [
            'pageTitle' => 'E-Mail (SMTP)', 'activeNav' => 'smtp', 'config' => Container::smtp()->config(),
            'hasPassword' => Container::settings()->get('smtp_password') !== '', 'messages' => Container::mailQueue()->recent(),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function save(Request $request): Response
    {
        $this->requireValidCsrf($request);
        try {
            Container::smtp()->save($request->post);
            Session::flash('success', 'SMTP-Konfiguration gespeichert.');
            app_logger()->info('SMTP-Konfiguration geändert.', ['admin' => Container::auth()->username()]);
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));
        }

        return $this->redirect('/admin/smtp');
    }

    public function test(Request $request): Response
    {
        $this->requireValidCsrf($request);
        try {
            Container::mailQueue()->enqueueTest((string) $request->input('recipient', ''));
            Session::flash('success', 'Testnachricht eingeplant. Bitte den Versandstatus und das Testpostfach prüfen.');
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));
        }

        return $this->redirect('/admin/smtp');
    }
}
