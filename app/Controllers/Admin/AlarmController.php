<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Support\Validator;

final class AlarmController extends AdminController
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

        $host = trim((string) $request->input('alarm_host', ''));
        if ($host !== '' && !Validator::isGatewayHost($host)) {
            $errors['alarm_host'] = 'Bitte eine gültige Zieladresse (Hostname oder IP, optional mit Port) angeben.';
        }
        $values['alarm_host'] = $host;

        $username = Validator::cleanText((string) $request->input('alarm_username', ''), 255);
        if ($username !== '' && preg_match('/^[A-Za-z0-9._@-]{1,255}$/', $username) !== 1) {
            $errors['alarm_username'] = 'Ungültiger Benutzername.';
        }
        $values['alarm_username'] = $username;

        // Passwort nur speichern, wenn eines eingegeben wurde ("leer = unverändert").
        $password = (string) $request->input('alarm_password', '');
        if ($password !== '') {
            if (mb_strlen($password) > 255 || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
                $errors['alarm_password'] = 'Ungültiges Passwort.';
            } else {
                $values['alarm_password'] = $password;
            }
        }

        // Einzelversand: standardmäßig die bisherigen Einstellungen verwenden.
        // Nur bei aktivem Opt-In werden abweichende Werte gespeichert.
        $singleCustom = $request->has('alarm_single_custom');
        $values['alarm_single_custom'] = $singleCustom ? '1' : '0';

        $singleHost = trim((string) $request->input('alarm_single_host', ''));
        $values['alarm_single_host'] = $singleHost;

        $singleUsername = Validator::cleanText((string) $request->input('alarm_single_username', ''), 255);
        $values['alarm_single_username'] = $singleUsername;

        $singlePassword = (string) $request->input('alarm_single_password', '');

        if ($singleCustom) {
            if ($singleHost !== '' && !Validator::isGatewayHost($singleHost)) {
                $errors['alarm_single_host'] = 'Bitte eine gültige Zieladresse (Hostname oder IP, optional mit Port) angeben.';
            }

            if ($singleUsername !== '' && preg_match('/^[A-Za-z0-9._@-]{1,255}$/', $singleUsername) !== 1) {
                $errors['alarm_single_username'] = 'Ungültiger Benutzername.';
            }

            if ($singlePassword !== '') {
                if (mb_strlen($singlePassword) > 255 || preg_match('/[\x00-\x1F\x7F]/', $singlePassword) === 1) {
                    $errors['alarm_single_password'] = 'Ungültiges Passwort.';
                } else {
                    $values['alarm_single_password'] = $singlePassword;
                }
            }
        } else {
            // Ohne Opt-In keine abweichenden Werte hinterlegen.
            $values['alarm_single_host'] = '';
            $values['alarm_single_username'] = '';
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die Alarmierungseinstellungen.');

            return $this->render($errors, $values, 422);
        }

        Container::settings()->update($values);
        app_logger()->info('Alarmierungseinstellungen geändert.', [
            'admin' => Container::auth()->username(),
            'host' => $values['alarm_host'],
            'single_custom' => $values['alarm_single_custom'],
        ]);
        Session::flash('success', 'Die Alarmierungseinstellungen wurden gespeichert.');

        return $this->redirect('/admin/alarmierung');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $settings = Container::settings();
        $current = [
            'alarm_host' => $settings->get('alarm_host'),
            'alarm_username' => $settings->get('alarm_username'),
            'alarm_password' => '',
            'alarm_single_custom' => $settings->get('alarm_single_custom'),
            'alarm_single_host' => $settings->get('alarm_single_host'),
            'alarm_single_username' => $settings->get('alarm_single_username'),
            'alarm_single_password' => '',
        ];

        return $this->adminView('admin.alarm', [
            'pageTitle' => 'Alarmierung',
            'activeNav' => 'alarm',
            'pageScript' => 'alarm.js',
            'values' => array_merge($current, $values),
            'errors' => $errors,
            'hasPassword' => $settings->get('alarm_password') !== '' || (string) Config::get('alarm.password', '') !== '',
            'hasEnvPassword' => (string) Config::get('alarm.password', '') !== '',
            'hasSinglePassword' => $settings->get('alarm_single_password') !== '',
            'groups' => Container::alarmGroups()->allItems(),
            'history' => Container::alarmLogRepository()->recent(50),
        ], $status);
    }
}
