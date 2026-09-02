<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Services\LdapClient;
use App\Support\Validator;

final class LdapController extends AdminController
{
    private const ATTRIBUTE_KEYS = [
        'ldap_attr_display_name' => 'display_name',
        'ldap_attr_first_name' => 'first_name',
        'ldap_attr_last_name' => 'last_name',
        'ldap_attr_phone' => 'phone',
        'ldap_attr_mobile' => 'mobile',
        'ldap_attr_email' => 'email',
        'ldap_attr_department' => 'department',
        'ldap_attr_modified' => 'modified',
        'ldap_attr_unique_id' => 'unique_id',
    ];

    public function index(Request $request): Response
    {
        return $this->render();
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $errors = [];
        $values = [];

        $host = trim((string) $request->input('ldap_host', ''));
        if ($host !== '' && !Validator::isHostname($host)) {
            $errors['ldap_host'] = 'Ungültiger Hostname oder ungültige IP-Adresse.';
        }
        $values['ldap_host'] = $host;

        $port = $request->inputInt('ldap_port', 636);
        if (!Validator::isPort($port)) {
            $errors['ldap_port'] = 'Der Port muss zwischen 1 und 65535 liegen.';
        }
        $values['ldap_port'] = (string) $port;

        $baseDn = Validator::cleanText((string) $request->input('ldap_base_dn', ''), 255);
        $values['ldap_base_dn'] = $baseDn;

        $bindDn = Validator::cleanText((string) $request->input('ldap_bind_dn', ''), 255);
        $values['ldap_bind_dn'] = $bindDn;

        $filter = trim((string) $request->input('ldap_filter', ''));
        if (!Validator::isLdapFilter($filter)) {
            $errors['ldap_filter'] = 'Der Suchfilter muss in Klammern stehen, z. B. (&(objectClass=user)(objectCategory=person)).';
        }
        $values['ldap_filter'] = $filter;

        $timeout = $request->inputInt('ldap_timeout', 10);
        if ($timeout < 1 || $timeout > 120) {
            $errors['ldap_timeout'] = 'Das Timeout muss zwischen 1 und 120 Sekunden liegen.';
        }
        $values['ldap_timeout'] = (string) $timeout;

        $interval = $request->inputInt('ldap_sync_interval', 3600);
        if ($interval < 60 || $interval > 86400) {
            $errors['ldap_sync_interval'] = 'Das Synchronisationsintervall muss zwischen 60 und 86400 Sekunden liegen.';
        }
        $values['ldap_sync_interval'] = (string) $interval;

        $values['ldap_use_tls'] = $request->has('ldap_use_tls') ? '1' : '0';
        $values['ldap_verify_cert'] = $request->has('ldap_verify_cert') ? '1' : '0';

        foreach (array_keys(self::ATTRIBUTE_KEYS) as $key) {
            $attribute = trim((string) $request->input($key, ''));
            if (!Validator::isLdapAttribute($attribute)) {
                $errors[$key] = 'Ungültiger Attributname.';
                continue;
            }
            $values[$key] = $attribute;
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die AD-Einstellungen.');

            return $this->render($errors, $values, 422);
        }

        Container::settings()->update($values);
        app_logger()->info('AD-Konfiguration geändert.', [
            'admin' => Container::auth()->username(),
            'host' => $values['ldap_host'],
        ]);
        Session::flash('success', 'Die AD-Einstellungen wurden gespeichert.');

        return $this->redirect('/admin/ad');
    }

    public function sync(Request $request): Response
    {
        $this->requireValidCsrf($request);

        if (!LdapClient::isSupported()) {
            Session::flash('error', 'Die PHP-Erweiterung "ldap" ist nicht verfügbar.');

            return $this->redirect('/admin/ad');
        }

        if (!Container::settings()->isLdapConfigured()) {
            Session::flash('error', 'Bitte zuerst LDAP-Server und Base DN konfigurieren.');

            return $this->redirect('/admin/ad');
        }

        $result = Container::adSync()->run();

        if ($result['status'] === 'success') {
            Session::flash('success', sprintf(
                'Synchronisation erfolgreich: %d Einträge aktualisiert, %d deaktiviert.',
                $result['processed'],
                $result['deactivated']
            ));
        } else {
            Session::flash('error', 'Die Synchronisation ist fehlgeschlagen. Details stehen im Log. Der letzte gültige Datenbestand bleibt erhalten.');
        }

        return $this->redirect('/admin/ad');
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $settings = Container::settings();
        $current = [
            'ldap_host' => $settings->get('ldap_host'),
            'ldap_port' => (string) $settings->int('ldap_port', 636),
            'ldap_use_tls' => $settings->bool('ldap_use_tls') ? '1' : '0',
            'ldap_verify_cert' => $settings->bool('ldap_verify_cert') ? '1' : '0',
            'ldap_base_dn' => $settings->get('ldap_base_dn'),
            'ldap_bind_dn' => $settings->get('ldap_bind_dn'),
            'ldap_filter' => $settings->get('ldap_filter'),
            'ldap_timeout' => (string) $settings->int('ldap_timeout', 10),
            'ldap_sync_interval' => (string) $settings->int('ldap_sync_interval', 3600),
        ];

        foreach (array_keys(self::ATTRIBUTE_KEYS) as $key) {
            $current[$key] = $settings->get($key);
        }

        return $this->adminView('admin.ldap', [
            'pageTitle' => 'Active Directory',
            'activeNav' => 'ldap',
            'values' => array_merge($current, $values),
            'errors' => $errors,
            'attributeKeys' => self::ATTRIBUTE_KEYS,
            'hasBindPassword' => (string) Config::get('ldap.password', '') !== '',
            'ldapExtensionAvailable' => LdapClient::isSupported(),
            'syncRuns' => Container::syncLogRepository()->recent(10),
            'phonebookCount' => Container::phonebook()->countActive(),
        ], $status);
    }
}
