<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Support\Validator;

final class SnmpController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $errors = [];
        $values = [];

        $community = trim((string) $request->input('snmp_community', ''));
        if ($community !== '' && preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $community) !== 1) {
            $errors['snmp_community'] = 'Der Community-String darf nur Buchstaben, Ziffern sowie . _ @ - enthalten (max. 64 Zeichen).';
        }
        $values['snmp_community'] = $community;

        $sysLocation = Validator::cleanText((string) $request->input('snmp_sys_location', ''), 255);
        $values['snmp_sys_location'] = $sysLocation;

        $sysContact = Validator::cleanText((string) $request->input('snmp_sys_contact', ''), 255);
        $values['snmp_sys_contact'] = $sysContact;

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die SNMP-Einstellungen.');

            return $this->render($errors, $values, 422);
        }

        Container::settings()->update($values);
        app_logger()->info('SNMP-Einstellungen geändert.', [
            'admin' => Container::auth()->username(),
        ]);
        Session::flash('success', 'Die SNMP-Einstellungen wurden gespeichert.');

        return $this->redirect('/admin/snmp');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $settings = Container::settings();
        $current = [
            'snmp_community' => $settings->get('snmp_community'),
            'snmp_sys_location' => $settings->get('snmp_sys_location'),
            'snmp_sys_contact' => $settings->get('snmp_sys_contact'),
        ];

        return $this->adminView('admin.snmp', [
            'pageTitle' => 'SNMP-Überwachung',
            'activeNav' => 'snmp',
            'values' => array_merge($current, $values),
            'errors' => $errors,
        ], $status);
    }
}
