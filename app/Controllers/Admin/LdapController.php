<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Services\IdentitySourceService;
use App\Services\LdapClient;
use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Active Directory: Identitaetsquellen (Hauptquelle + weitere Verzeichnisse
 * von Zweigstellen, Tochtergesellschaften, ...), Synchronisation und
 * Gruppenvorschlaege.
 */
final class LdapController extends AdminController
{
    private const SSO_RESTART_HINT = ' Die Windows-Anmeldung übernimmt geänderte Domänen-Einstellungen erst nach einem Neustart der auth-Container (docker compose restart auth bzw. ./scripts/sso-domains.sh bei neuen oder entfernten Domänen).';

    public function index(Request $request): Response
    {
        return $this->render();
    }

    /**
     * Speichert die Hauptquelle (Einstellungen ldap_*) und das
     * Synchronisationsintervall.
     */
    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $service = Container::identitySources();
        [$values, $errors] = IdentitySourceService::validateConnection($request->post);

        $interval = $request->inputInt('ldap_sync_interval', 3600);
        if ($interval < 60 || $interval > 86400) {
            $errors['ldap_sync_interval'] = 'Das Synchronisationsintervall muss zwischen 60 und 86400 Sekunden liegen.';
        }
        $values['ldap_sync_interval'] = (string) $interval;

        [$ssoValues, $ssoErrors] = IdentitySourceService::validateSso($request->post, false);
        $values += $ssoValues;
        $errors += $ssoErrors;

        [$changes, $secretErrors] = IdentitySourceService::secretInput($request->post);
        $errors += $secretErrors;
        if ($values['sso_domain'] !== ''
            && !IdentitySourceService::willHaveSecret('sso_join_password', $changes, $service->secretStates(null))) {
            $errors['sso_join_password'] ??= 'Bitte das Passwort des Kontos für den Domänenbeitritt angeben.';
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die AD-Einstellungen.');

            return $this->render($errors, $values, 422);
        }

        $ssoChanged = $this->ssoFingerprint(null) !== $this->ssoFingerprint($values, $changes);
        Container::settings()->update($values);
        $service->savePrimarySecrets($changes);
        app_logger()->info('AD-Konfiguration geändert.', [
            'admin' => Container::auth()->username(),
            'source' => $values['ldap_label'],
            'host' => str_replace("\n", ', ', $values['ldap_host']),
            'passwords_changed' => array_keys($changes),
        ]);
        Session::flash('success', 'Die AD-Einstellungen wurden gespeichert.' . ($ssoChanged ? self::SSO_RESTART_HINT : ''));

        return $this->redirect('/admin/ad');
    }

    public function createSource(Request $request): Response
    {
        return $this->renderSource(null, IdentitySourceService::formValues(null));
    }

    public function storeSource(Request $request): Response
    {
        $this->requireValidCsrf($request);

        return $this->saveSource(null, $request);
    }

    public function editSource(Request $request): Response
    {
        $row = Container::identitySources()->find($request->queryInt('id', 0));
        if ($row === null) {
            Session::flash('error', 'Identitätsquelle nicht gefunden.');

            return $this->redirect('/admin/ad');
        }

        return $this->renderSource($row, IdentitySourceService::formValues($row));
    }

    public function updateSource(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $id = $request->inputInt('id', 0);
        if (Container::identitySources()->find($id) === null) {
            Session::flash('error', 'Identitätsquelle nicht gefunden.');

            return $this->redirect('/admin/ad');
        }

        return $this->saveSource($id, $request);
    }

    public function deleteSource(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $service = Container::identitySources();
        $row = $service->find($request->inputInt('id', 0));
        if ($row === null) {
            Session::flash('error', 'Identitätsquelle nicht gefunden.');

            return $this->redirect('/admin/ad');
        }

        $service->delete((int) $row['id']);
        app_logger()->info('Identitätsquelle gelöscht.', [
            'admin' => Container::auth()->username(),
            'source' => (string) $row['source_key'],
        ]);
        Session::flash('success', sprintf('Die Identitätsquelle „%s“ wurde gelöscht; ihre Einträge sind ausgeblendet.', (string) $row['label']));

        return $this->redirect('/admin/ad');
    }

    /**
     * Prueft jeden Server einer Quelle einzeln (0 = Hauptquelle).
     */
    public function testSource(Request $request): Response
    {
        $this->requireValidCsrf($request);

        if (!LdapClient::isSupported()) {
            Session::flash('error', 'Die PHP-Erweiterung "ldap" ist nicht verfügbar.');

            return $this->redirect('/admin/ad');
        }

        $id = $request->inputInt('id', 0);
        $config = null;
        foreach (Container::identitySources()->configs(false) as $candidate) {
            if ((int) $candidate['id'] === $id) {
                $config = $candidate;
                break;
            }
        }

        if ($config === null || !IdentitySourceService::isConfigured($config)) {
            Session::flash('error', 'Die Identitätsquelle ist nicht vollständig konfiguriert (Server und Base DN erforderlich).');

            return $this->redirect('/admin/ad');
        }

        $results = (new LdapClient($config))->testHosts();
        $lines = [];
        $reachable = 0;
        foreach ($results as $host => $error) {
            if ($error === null) {
                $reachable++;
                $lines[] = $host . ': erreichbar';
            } else {
                $lines[] = $host . ': ' . $error;
            }
        }

        $message = sprintf(
            'Verbindungstest „%s“: %d von %d Servern erreichbar. %s',
            (string) $config['label'],
            $reachable,
            count($results),
            implode(' · ', $lines)
        );
        Session::flash($reachable === count($results) ? 'success' : 'error', $message);

        return $this->redirect('/admin/ad');
    }

    public function sync(Request $request): Response
    {
        $this->requireValidCsrf($request);

        if (!LdapClient::isSupported()) {
            Session::flash('error', 'Die PHP-Erweiterung "ldap" ist nicht verfügbar.');

            return $this->redirect('/admin/ad');
        }

        if (!Container::identitySources()->isAnyConfigured()) {
            Session::flash('error', 'Bitte zuerst LDAP-Server und Base DN konfigurieren.');

            return $this->redirect('/admin/ad');
        }

        $result = Container::adSync()->run();
        $multiple = count($result['sources']) > 1;

        if ($result['status'] === 'error' && !$multiple) {
            Session::flash('error', 'Die Synchronisation ist fehlgeschlagen. Details stehen im Log. Der letzte gültige Datenbestand bleibt erhalten.');

            return $this->redirect('/admin/ad');
        }

        $message = sprintf(
            '%s: %d Einträge aktualisiert, %d deaktiviert.',
            match ($result['status']) {
                'success' => 'Synchronisation erfolgreich',
                'partial' => 'Synchronisation unvollständig',
                default => 'Synchronisation fehlgeschlagen',
            },
            $result['processed'],
            $result['deactivated']
        );
        if (($result['groups'] ?? null) !== null) {
            $message .= sprintf(' %d AD-Gruppen für die Rechtevergabe übernommen.', $result['groups']);
        } elseif (!$multiple && Container::settings()->ldapConfig()['group_base_dns'] !== []) {
            $message .= ' Die AD-Gruppen konnten nicht gelesen werden (siehe Log); der bisherige Gruppenbestand bleibt erhalten.';
        }

        if ($multiple) {
            $parts = [];
            foreach ($result['sources'] as $source) {
                $parts[] = $source['status'] === 'success'
                    ? sprintf('%s: %d aktualisiert, %d deaktiviert', $source['label'], $source['processed'], $source['deactivated'])
                    : sprintf('%s: fehlgeschlagen – letzter Stand bleibt erhalten', $source['label']);
            }
            $message .= ' ' . implode(' · ', $parts) . '.';
        }

        Session::flash($result['status'] === 'success' ? 'success' : 'error', $message);

        return $this->redirect('/admin/ad');
    }

    /**
     * Vorschlaege fuer AD-Gruppennamen bei der Rechtevergabe. Quelle ist
     * ausschliesslich der lokal synchronisierte Datenbestand (kein Live-Zugriff
     * auf das AD) sowie bereits vergebene Gruppennamen.
     */
    public function groups(Request $request): Response
    {
        $term = Validator::cleanText((string) $request->query('q', ''), 100);
        $items = [];

        try {
            foreach (Container::adGroupRepository()->suggest($term) as $group) {
                $items[strtolower($group['name'])] = $group + ['source' => 'ad'];
            }
        } catch (\Throwable) {
            // Gruppentabelle fehlt (Migration ausstehend) – nur vergebene Namen.
        }

        foreach (Container::navigationRepository()->permissionGroupNames($term, 20) as $name) {
            $items[strtolower($name)] ??= ['name' => $name, 'description' => '', 'members' => null, 'source' => 'vergeben'];
        }

        return Response::json(['items' => array_slice(array_values($items), 0, 20)])
            ->withHeader('Cache-Control', 'no-store');
    }

    private function saveSource(?int $id, Request $request): Response
    {
        $service = Container::identitySources();
        [$values, $errors] = $service->validateAdditional($request->post, $id);
        $row = $id === null ? null : $service->find($id);

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die Angaben zur Identitätsquelle.');

            return $this->renderSource($row, $values, $errors, 422);
        }

        [$changes] = IdentitySourceService::secretInput($request->post);
        $before = $row === null ? null : $this->ssoFingerprint($row);
        $savedId = $service->saveAdditional($id, $values, $changes);
        $after = $this->ssoFingerprint((array) $service->find($savedId));
        app_logger()->info($id === null ? 'Identitätsquelle angelegt.' : 'Identitätsquelle geändert.', [
            'admin' => Container::auth()->username(),
            'source' => $values['ldap_key'],
            'host' => str_replace("\n", ', ', $values['ldap_host']),
            'passwords_changed' => array_keys($changes),
        ]);

        $message = sprintf('Die Identitätsquelle „%s“ wurde gespeichert.', $values['ldap_label']);
        if ($service->secretStates($service->find($savedId))['ldap_bind_password'] !== 'set' && $values['ldap_bind_dn'] !== '') {
            $message .= ' Hinweis: Für das Dienstkonto ist noch kein Passwort hinterlegt.';
        }
        $ssoRelevant = !empty($row['sso_enabled']) || $values['sso_enabled'] === '1';
        if ($ssoRelevant && ($before ?? $this->ssoFingerprint([])) !== $after) {
            $message .= self::SSO_RESTART_HINT;
        }
        Session::flash('success', $message);

        return $this->redirect('/admin/ad');
    }

    /**
     * Kennwert der fuer die auth-Container relevanten Angaben einer Quelle
     * (null = Hauptquelle aus den Einstellungen), um nach dem Speichern auf
     * einen noetigen Neustart hinzuweisen.
     *
     * @param array<string,mixed>|null $data  Datensatz, Formularwerte oder null
     * @param array<string,string>     $changes Passwort-Aenderungen
     */
    private function ssoFingerprint(?array $data, array $changes = []): string
    {
        if ($data === null) {
            $settings = Container::settings();
            $data = [];
            foreach (['sso_domain', 'sso_dcs', 'sso_join_user'] as $key) {
                $data[$key] = $settings->get($key);
            }
            $data['sso_join_password'] = $settings->get('sso_join_password');
        } elseif (isset($data['ldap_key']) || isset($data['ldap_label'])) {
            // Formularwerte der Hauptquelle
            $data['sso_join_password'] = array_key_exists('sso_join_password', $changes)
                ? 'neu:' . hash('sha256', $changes['sso_join_password'])
                : Container::settings()->get('sso_join_password');
        }

        $parts = [];
        foreach (['source_key', 'active', 'sso_enabled', 'sso_domain', 'sso_dcs', 'sso_join_user', 'sso_join_password', 'sso_networks', 'sso_hostnames'] as $key) {
            $parts[] = (string) ($data[$key] ?? '');
        }

        return hash('sha256', implode("\0", $parts));
    }

    private function safeGroupCount(): ?int
    {
        try {
            return Container::adGroupRepository()->countActive();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,int>
     */
    private function safeCountsBySource(): array
    {
        try {
            return Container::phonebookRepository()->countActiveBySource();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,string> $values
     * @param array<string,string> $errors
     */
    private function renderSource(?array $row, array $values, array $errors = [], int $status = 200): Response
    {
        return $this->adminView('admin.ldap.source', [
            'pageTitle' => $row === null ? 'Neue Identitätsquelle' : 'Identitätsquelle bearbeiten',
            'activeNav' => 'ldap',
            'sourceId' => $row === null ? null : (int) $row['id'],
            'values' => $values,
            'errors' => $errors,
            'attributeKeys' => IdentitySourceService::ATTRIBUTE_KEYS,
            'secretStates' => Container::identitySources()->secretStates($row),
            'primary' => false,
            'ssoEnabled' => (bool) Config::get('sso.enabled', false),
            'serviceName' => ($values['ldap_key'] ?? '') !== '' ? IdentitySourceService::serviceName((string) $values['ldap_key']) : '',
        ], $status);
    }

    /**
     * @param array<string,string> $errors
     * @param array<string,string> $values
     */
    private function render(array $errors = [], array $values = [], int $status = 200): Response
    {
        $settings = Container::settings();
        $service = Container::identitySources();
        $primary = $settings->ldapConfig();
        $current = [
            'ldap_label' => (string) $primary['label'],
            'ldap_host' => implode("\n", $primary['hosts']),
            'ldap_port' => (string) $settings->int('ldap_port', 636),
            'ldap_use_tls' => $settings->bool('ldap_use_tls') ? '1' : '0',
            'ldap_verify_cert' => $settings->bool('ldap_verify_cert') ? '1' : '0',
            'ldap_base_dn' => $settings->get('ldap_base_dn'),
            'ldap_bind_dn' => $settings->get('ldap_bind_dn'),
            'ldap_filter' => $settings->get('ldap_filter'),
            'ldap_timeout' => (string) $settings->int('ldap_timeout', 10),
            'ldap_sync_interval' => (string) $settings->int('ldap_sync_interval', 3600),
            'ldap_group_base_dn' => implode("\n", SettingsService::splitDnList($settings->get('ldap_group_base_dn'))),
            'ldap_group_filter' => $settings->get('ldap_group_filter'),
            'ldap_group_name_attribute' => $settings->get('ldap_group_name_attribute'),
            'sso_domain' => $settings->get('sso_domain'),
            'sso_dcs' => $settings->get('sso_dcs'),
            'sso_join_user' => $settings->get('sso_join_user'),
        ];

        foreach (array_keys(IdentitySourceService::ATTRIBUTE_KEYS) as $key) {
            $current[$key] = $settings->get($key);
        }

        $counts = $this->safeCountsBySource();
        $sources = [[
            'id' => 0,
            'key' => '',
            'label' => (string) $primary['label'],
            'hosts' => $primary['hosts'],
            'base_dn' => (string) $primary['base_dn'],
            'active' => true,
            'primary' => true,
            'configured' => IdentitySourceService::isConfigured($primary),
            'password_state' => $service->secretStates(null)['ldap_bind_password'],
            'sso' => $settings->get('sso_domain') !== '' ? $settings->get('sso_domain') : null,
            'users' => $counts[0] ?? 0,
        ]];
        $rows = [];
        foreach ($service->additionalRows(false) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        foreach ($service->configs(false) as $config) {
            if ((int) $config['id'] === 0) {
                continue;
            }
            $row = $rows[(int) $config['id']] ?? [];
            $sources[] = [
                'id' => (int) $config['id'],
                'key' => (string) $config['key'],
                'label' => (string) $config['label'],
                'hosts' => $config['hosts'],
                'base_dn' => (string) $config['base_dn'],
                'active' => (bool) $config['active'],
                'primary' => false,
                'configured' => IdentitySourceService::isConfigured($config),
                'password_state' => $service->secretState((string) ($row['bind_password'] ?? '')),
                'sso' => !empty($row['sso_enabled']) ? (string) ($row['sso_domain'] ?? '') : null,
                'users' => $counts[(int) $config['id']] ?? 0,
            ];
        }

        return $this->adminView('admin.ldap', [
            'pageTitle' => 'Active Directory',
            'activeNav' => 'ldap',
            'values' => array_merge($current, $values),
            'errors' => $errors,
            'attributeKeys' => IdentitySourceService::ATTRIBUTE_KEYS,
            'secretStates' => $service->secretStates(null),
            'primary' => true,
            'ssoEnabled' => (bool) Config::get('sso.enabled', false),
            'ldapExtensionAvailable' => LdapClient::isSupported(),
            'sources' => $sources,
            'syncRuns' => Container::syncLogRepository()->recent(10),
            'phonebookCount' => Container::phonebook()->countActive(),
            'groupCount' => $this->safeGroupCount(),
        ], $status);
    }
}
