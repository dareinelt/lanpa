<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\IdentitySourceRepository;
use App\Security\SecretBox;
use App\Support\Validator;

/**
 * Verwaltung der Identitaetsquellen (Active Directorys).
 *
 * - Hauptquelle (ID 0): Einstellungen `ldap_*` und `sso_*`.
 * - Weitere Quellen (Zweigstellen, Tochtergesellschaften, ...): Tabelle
 *   `identity_sources`.
 *
 * Jede Quelle hat eine Beschriftung und einen oder mehrere Server, die der
 * Reihe nach versucht werden. Zugangsdaten (Bind-Passwort, Konto fuer den
 * Domaenenbeitritt der Windows-Anmeldung) werden im Adminbereich gepflegt und
 * nur verschluesselt gespeichert (SecretBox); sie werden nie an den Browser
 * zurueckgegeben.
 */
final class IdentitySourceService
{
    public const PRIMARY_ID = 0;

    public const MAX_HOSTS = 10;

    /** Formularfeld => interner Attributname. */
    public const ATTRIBUTE_KEYS = [
        'ldap_attr_display_name' => 'display_name',
        'ldap_attr_first_name' => 'first_name',
        'ldap_attr_last_name' => 'last_name',
        'ldap_attr_phone' => 'phone',
        'ldap_attr_mobile' => 'mobile',
        'ldap_attr_email' => 'email',
        'ldap_attr_department' => 'department',
        'ldap_attr_modified' => 'modified',
        'ldap_attr_unique_id' => 'unique_id',
        'ldap_attr_samaccount_name' => 'samaccount_name',
    ];

    /** Vorgaben fuer neue Quellen (entsprechen den LDAP_*-Standardwerten). */
    public const DEFAULT_ATTRIBUTES = [
        'display_name' => 'displayName',
        'first_name' => 'givenName',
        'last_name' => 'sn',
        'phone' => 'telephoneNumber',
        'mobile' => 'mobile',
        'email' => 'mail',
        'department' => 'department',
        'modified' => 'whenChanged',
        'unique_id' => 'objectGUID',
        'samaccount_name' => 'sAMAccountName',
    ];

    /** Passwortfelder: Formularfeld = Einstellung (Hauptquelle) bzw. Spalte. */
    public const SECRET_FIELDS = [
        'ldap_bind_password' => 'bind_password',
        'sso_join_password' => 'sso_join_password',
    ];

    /** Einstellungen der Hauptquelle mit verschluesselten Werten. */
    public const PRIMARY_SECRET_SETTINGS = ['ldap_bind_password', 'sso_join_password'];

    public const MAX_SECRET_LENGTH = 256;

    public const MAX_DCS = 10;

    public const MAX_SSO_ROUTES = 100;

    public function __construct(
        private readonly IdentitySourceRepository $repository,
        private readonly SettingsService $settings,
        private readonly SecretBox $secrets
    ) {
    }

    /**
     * Docker-Compose-Dienst der auth-Instanz einer weiteren Quelle.
     */
    public static function serviceName(string $key): string
    {
        return 'auth-' . str_replace('_', '-', strtolower(self::normalizeKey($key)));
    }

    public static function normalizeKey(string $key): string
    {
        return strtoupper(trim($key));
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $key) === 1;
    }

    /**
     * Zustand eines gespeicherten (verschluesselten) Passworts:
     * 'missing' (nicht gesetzt), 'set' oder 'invalid' (nicht entschluesselbar,
     * z. B. aus der Sicherung einer anderen Installation).
     */
    public function secretState(?string $stored): string
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return 'missing';
        }

        return $this->secrets->decrypt($stored) === null ? 'invalid' : 'set';
    }

    public function decryptSecret(?string $stored): string
    {
        return (string) $this->secrets->decrypt($stored);
    }

    public function encryptSecret(string $plain): string
    {
        return $this->secrets->encrypt($plain);
    }

    /**
     * Gespeicherte Passwoerter einer Quelle (null = Hauptquelle), verschluesselt.
     *
     * @return array<string,string> Formularfeld => verschluesselter Wert
     */
    public function storedSecrets(?array $row): array
    {
        $stored = [];
        foreach (self::SECRET_FIELDS as $field => $column) {
            $stored[$field] = $row === null
                ? $this->settings->get($field)
                : (string) ($row[$column] ?? '');
        }

        return $stored;
    }

    /**
     * Zustaende der Passwoerter einer Quelle (null = Hauptquelle).
     *
     * @return array<string,string> Formularfeld => missing|set|invalid
     */
    public function secretStates(?array $row): array
    {
        return array_map(fn (string $value): string => $this->secretState($value), $this->storedSecrets($row));
    }

    /**
     * Passwort-Eingaben eines Formulars. Leere Felder bleiben unveraendert,
     * die Checkbox "<feld>_clear" entfernt ein gespeichertes Passwort.
     *
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,string>,1:array<string,string>} [Feld => neuer Klartext ('' = entfernen), Fehler]
     */
    public static function secretInput(array $input): array
    {
        $changes = [];
        $errors = [];
        foreach (array_keys(self::SECRET_FIELDS) as $field) {
            if (!empty($input[$field . '_clear'])) {
                $changes[$field] = '';
                continue;
            }

            $value = $input[$field] ?? '';
            $value = is_string($value) ? $value : '';
            if ($value === '') {
                continue;
            }
            if (strlen($value) > self::MAX_SECRET_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                $errors[$field] = sprintf('Das Passwort darf höchstens %d Zeichen und keine Steuerzeichen enthalten.', self::MAX_SECRET_LENGTH);
                continue;
            }
            $changes[$field] = $value;
        }

        return [$changes, $errors];
    }

    /**
     * Ob nach dem Speichern ein Passwort vorhanden ist.
     *
     * @param array<string,string> $changes Ergebnis von secretInput()
     * @param array<string,string> $states  Ergebnis von secretStates()
     */
    public static function willHaveSecret(string $field, array $changes, array $states): bool
    {
        if (array_key_exists($field, $changes)) {
            return $changes[$field] !== '';
        }

        return ($states[$field] ?? 'missing') === 'set';
    }

    /**
     * Speichert Passwort-Aenderungen der Hauptquelle verschluesselt.
     *
     * @param array<string,string> $changes Ergebnis von secretInput()
     */
    public function savePrimarySecrets(array $changes): void
    {
        $values = [];
        foreach ($changes as $field => $plain) {
            $values[$field] = $plain === '' ? '' : $this->secrets->encrypt($plain);
        }
        if ($values !== []) {
            $this->settings->update($values);
        }
    }

    /**
     * Konfiguration der Hauptquelle inkl. entschluesseltem Bind-Passwort.
     *
     * @return array<string,mixed>
     */
    public function primaryConfig(): array
    {
        $config = $this->settings->ldapConfig();
        $config['password'] = $this->decryptSecret($this->settings->get('ldap_bind_password'));

        return $config;
    }

    /**
     * Effektive Konfigurationen aller Quellen, Hauptquelle zuerst. Die
     * Hauptquelle ist immer enthalten (auch wenn sie noch unkonfiguriert ist).
     *
     * @return list<array<string,mixed>>
     */
    public function configs(bool $activeOnly = true): array
    {
        $configs = [$this->primaryConfig() + ['active' => true]];
        foreach ($this->additionalRows($activeOnly) as $row) {
            $configs[] = $this->rowToConfig($row);
        }

        return $configs;
    }

    /**
     * Ob mindestens eine aktive Quelle vollstaendig konfiguriert ist.
     */
    public function isAnyConfigured(): bool
    {
        foreach ($this->configs() as $config) {
            if (self::isConfigured($config)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function isConfigured(array $config): bool
    {
        return ($config['hosts'] ?? []) !== [] && trim((string) ($config['base_dn'] ?? '')) !== '';
    }

    /**
     * Beschriftungen aller Quellen (auch inaktiver), Schluessel = Quell-ID.
     *
     * @return array<int,string>
     */
    public function labels(): array
    {
        $labels = [self::PRIMARY_ID => (string) $this->settings->ldapConfig()['label']];
        foreach ($this->additionalRows(false) as $row) {
            $labels[(int) $row['id']] = (string) $row['label'];
        }

        return $labels;
    }

    /**
     * Ob neben der Hauptquelle weitere aktive Quellen existieren.
     */
    public function hasAdditionalSources(): bool
    {
        return $this->additionalRows(true) !== [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function additionalRows(bool $activeOnly = false): array
    {
        try {
            return $this->repository->all($activeOnly);
        } catch (\PDOException) {
            // Tabelle fehlt (Migration ausstehend) – nur die Hauptquelle.
            return [];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $id > 0 ? $this->repository->find($id) : null;
    }

    /**
     * Wandelt einen Tabellen-Datensatz in die von LdapClient erwartete
     * Konfiguration um (Bind-Passwort entschluesselt).
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public function rowToConfig(array $row): array
    {
        $key = self::normalizeKey((string) ($row['source_key'] ?? ''));
        $hosts = SettingsService::splitHostList((string) ($row['hosts'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'key' => $key,
            'label' => (string) ($row['label'] ?? $key),
            'hosts' => $hosts,
            'host' => $hosts[0] ?? '',
            'port' => (int) ($row['port'] ?? 636),
            'use_tls' => (bool) ($row['use_tls'] ?? true),
            'verify_cert' => (bool) ($row['verify_cert'] ?? true),
            'base_dn' => (string) ($row['base_dn'] ?? ''),
            'bind_dn' => (string) ($row['bind_dn'] ?? ''),
            'password' => $this->decryptSecret((string) ($row['bind_password'] ?? '')),
            'filter' => (string) ($row['user_filter'] ?? ''),
            'timeout' => (int) ($row['timeout'] ?? 10),
            'page_size' => (int) Config::get('ldap.page_size', 500),
            'group_base_dns' => SettingsService::splitDnList((string) ($row['group_base_dn'] ?? '')),
            'group_filter' => (string) ($row['group_filter'] ?? ''),
            'group_name_attribute' => (string) ($row['group_name_attribute'] ?? ''),
            'attributes' => self::decodeAttributes((string) ($row['attributes'] ?? '')),
            'active' => (bool) ($row['active'] ?? true),
        ];
    }

    /**
     * Formularwerte (ldap_*) einer weiteren Quelle; ohne Datensatz die Vorgaben.
     *
     * @param array<string,mixed>|null $row
     *
     * @return array<string,string>
     */
    public static function formValues(?array $row): array
    {
        $attributes = self::decodeAttributes((string) ($row['attributes'] ?? ''));
        $values = [
            'ldap_label' => (string) ($row['label'] ?? ''),
            'ldap_key' => (string) ($row['source_key'] ?? ''),
            'ldap_host' => implode("\n", SettingsService::splitHostList((string) ($row['hosts'] ?? ''))),
            'ldap_port' => (string) ($row['port'] ?? 636),
            'ldap_use_tls' => (string) (int) ($row['use_tls'] ?? 1),
            'ldap_verify_cert' => (string) (int) ($row['verify_cert'] ?? 1),
            'ldap_timeout' => (string) ($row['timeout'] ?? 10),
            'ldap_base_dn' => (string) ($row['base_dn'] ?? ''),
            'ldap_bind_dn' => (string) ($row['bind_dn'] ?? ''),
            'ldap_filter' => (string) ($row['user_filter'] ?? '(&(objectClass=user)(objectCategory=person))'),
            'ldap_group_base_dn' => implode("\n", SettingsService::splitDnList((string) ($row['group_base_dn'] ?? ''))),
            'ldap_group_filter' => (string) ($row['group_filter'] ?? '(objectClass=group)'),
            'ldap_group_name_attribute' => (string) ($row['group_name_attribute'] ?? 'cn'),
            'ldap_sort_order' => (string) ($row['sort_order'] ?? 0),
            'ldap_active' => (string) (int) ($row['active'] ?? 1),
            'sso_enabled' => (string) (int) ($row['sso_enabled'] ?? 0),
            'sso_domain' => (string) ($row['sso_domain'] ?? ''),
            'sso_dcs' => (string) ($row['sso_dcs'] ?? ''),
            'sso_join_user' => (string) ($row['sso_join_user'] ?? ''),
            'sso_networks' => (string) ($row['sso_networks'] ?? ''),
            'sso_hostnames' => (string) ($row['sso_hostnames'] ?? ''),
        ];
        foreach (self::ATTRIBUTE_KEYS as $field => $internal) {
            $values[$field] = $attributes[$internal];
        }

        return $values;
    }

    /**
     * Prueft die gemeinsamen Verbindungs-, Gruppen- und Mapping-Felder einer
     * Quelle (Hauptquelle und weitere Quellen).
     *
     * @param array<string,mixed> $input Formularwerte (ldap_*); Checkboxen
     *        fehlen, wenn sie nicht gesetzt sind
     *
     * @return array{0:array<string,string>,1:array<string,string>} [Werte, Fehler]
     */
    public static function validateConnection(array $input): array
    {
        $errors = [];
        $values = [];

        $label = Validator::cleanText((string) ($input['ldap_label'] ?? ''), 100);
        if ($label === '') {
            $errors['ldap_label'] = 'Bitte eine Beschriftung angeben (z. B. „Zentrale“ oder „Zweigstelle Hamburg“).';
        }
        $values['ldap_label'] = $label;

        $hosts = SettingsService::splitHostList((string) ($input['ldap_host'] ?? ''));
        foreach ($hosts as $host) {
            if (!Validator::isHostname($host)) {
                $errors['ldap_host'] = 'Ungültiger Hostname oder ungültige IP-Adresse: ' . $host;
                break;
            }
        }
        if (count($hosts) > self::MAX_HOSTS) {
            $errors['ldap_host'] = sprintf('Es sind höchstens %d Server je Identitätsquelle möglich.', self::MAX_HOSTS);
        }
        $values['ldap_host'] = implode("\n", $hosts);

        $port = self::intValue($input['ldap_port'] ?? null, 636);
        if (!Validator::isPort($port)) {
            $errors['ldap_port'] = 'Der Port muss zwischen 1 und 65535 liegen.';
        }
        $values['ldap_port'] = (string) $port;

        $values['ldap_base_dn'] = Validator::cleanText((string) ($input['ldap_base_dn'] ?? ''), 255);
        $values['ldap_bind_dn'] = Validator::cleanText((string) ($input['ldap_bind_dn'] ?? ''), 255);

        $filter = trim((string) ($input['ldap_filter'] ?? ''));
        if (!Validator::isLdapFilter($filter)) {
            $errors['ldap_filter'] = 'Der Suchfilter muss in Klammern stehen, z. B. (&(objectClass=user)(objectCategory=person)).';
        }
        $values['ldap_filter'] = $filter;

        $timeout = self::intValue($input['ldap_timeout'] ?? null, 10);
        if ($timeout < 1 || $timeout > 120) {
            $errors['ldap_timeout'] = 'Das Timeout muss zwischen 1 und 120 Sekunden liegen.';
        }
        $values['ldap_timeout'] = (string) $timeout;

        $groupDns = SettingsService::splitDnList((string) ($input['ldap_group_base_dn'] ?? ''));
        foreach ($groupDns as $dn) {
            if (mb_strlen($dn) > 255 || !str_contains($dn, '=') || preg_match('/[\x00-\x1F]/', $dn) === 1) {
                $errors['ldap_group_base_dn'] = 'Bitte je Zeile einen gültigen DN angeben, z. B. OU=Gruppen,DC=example,DC=internal.';
                break;
            }
        }
        if (count($groupDns) > 20) {
            $errors['ldap_group_base_dn'] = 'Es sind höchstens 20 Gruppen-Pfade möglich.';
        }
        $values['ldap_group_base_dn'] = implode("\n", $groupDns);

        $groupFilter = trim((string) ($input['ldap_group_filter'] ?? ''));
        if ($groupFilter === '') {
            $groupFilter = '(objectClass=group)';
        }
        if (!Validator::isLdapFilter($groupFilter)) {
            $errors['ldap_group_filter'] = 'Der Gruppenfilter muss in Klammern stehen, z. B. (objectClass=group).';
        }
        $values['ldap_group_filter'] = $groupFilter;

        $groupName = trim((string) ($input['ldap_group_name_attribute'] ?? ''));
        if ($groupName === '') {
            $groupName = 'cn';
        }
        if (!Validator::isLdapAttribute($groupName)) {
            $errors['ldap_group_name_attribute'] = 'Ungültiger Attributname.';
        }
        $values['ldap_group_name_attribute'] = $groupName;

        $values['ldap_use_tls'] = !empty($input['ldap_use_tls']) ? '1' : '0';
        $values['ldap_verify_cert'] = !empty($input['ldap_verify_cert']) ? '1' : '0';

        foreach (array_keys(self::ATTRIBUTE_KEYS) as $key) {
            $attribute = trim((string) ($input[$key] ?? ''));
            if (!Validator::isLdapAttribute($attribute)) {
                $errors[$key] = 'Ungültiger Attributname.';
            }
            $values[$key] = $attribute;
        }

        return [$values, $errors];
    }

    /**
     * Prueft eine weitere Quelle (zusaetzlich Kennung, Reihenfolge, Status).
     *
     * @param array<string,mixed> $input
     *
     * @return array{0:array<string,string>,1:array<string,string>} [Werte, Fehler]
     */
    public function validateAdditional(array $input, ?int $id = null): array
    {
        [$values, $errors] = self::validateConnection($input);

        $key = self::normalizeKey((string) ($input['ldap_key'] ?? ''));
        if (!self::isValidKey($key)) {
            $errors['ldap_key'] = 'Kennung: 1–32 Zeichen, beginnend mit einem Buchstaben; erlaubt sind A–Z, 0–9 und _ (z. B. HAMBURG).';
        } elseif ($this->repository->keyExists($key, $id)) {
            $errors['ldap_key'] = 'Diese Kennung wird bereits von einer anderen Identitätsquelle verwendet.';
        }
        $values['ldap_key'] = $key;

        if ($values['ldap_host'] === '') {
            $errors['ldap_host'] ??= 'Bitte mindestens einen Server angeben.';
        }
        if ($values['ldap_base_dn'] === '') {
            $errors['ldap_base_dn'] = 'Bitte den Base DN angeben.';
        }

        $sort = self::intValue($input['ldap_sort_order'] ?? null, 0);
        if ($sort < 0 || $sort > 9999) {
            $errors['ldap_sort_order'] = 'Die Reihenfolge muss zwischen 0 und 9999 liegen.';
        }
        $values['ldap_sort_order'] = (string) $sort;
        $values['ldap_active'] = !empty($input['ldap_active']) ? '1' : '0';

        [$ssoValues, $ssoErrors] = self::validateSso($input, true);
        $values += $ssoValues;
        $errors += $ssoErrors;

        [$changes, $secretErrors] = self::secretInput($input);
        $errors += $secretErrors;
        $states = $this->secretStates($id === null ? null : $this->repository->find($id));
        if ($values['sso_enabled'] === '1' && !self::willHaveSecret('sso_join_password', $changes, $states)) {
            $errors['sso_join_password'] ??= 'Bitte das Passwort des Kontos für den Domänenbeitritt angeben.';
        }

        return [$values, $errors];
    }

    /**
     * Prueft die Angaben zur Windows-Anmeldung (NTLM) einer Quelle.
     *
     * @param array<string,mixed> $input
     * @param bool $additional weitere Quelle (mit Schalter, Netzen und
     *        Hostnamen); die Hauptquelle wird ueber SSO_ENABLED geschaltet
     *
     * @return array{0:array<string,string>,1:array<string,string>} [Werte, Fehler]
     */
    public static function validateSso(array $input, bool $additional): array
    {
        $errors = [];
        $values = [];

        $domain = strtoupper(trim((string) ($input['sso_domain'] ?? '')));
        if ($domain !== '' && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,14}$/', $domain) !== 1) {
            $errors['sso_domain'] = 'NetBIOS-Name der Domäne: 1–15 Zeichen (A–Z, 0–9, . _ -), z. B. HAMBURG.';
        }
        $values['sso_domain'] = $domain;

        $lines = [];
        foreach (self::parseDcs((string) ($input['sso_dcs'] ?? '')) as $dc) {
            if (!Validator::isHostname($dc['host']) || ($dc['ip'] !== '' && filter_var($dc['ip'], FILTER_VALIDATE_IP) === false)) {
                $errors['sso_dcs'] = 'Bitte je Zeile einen Domänencontroller angeben: Hostname, optional gefolgt von der IP-Adresse (z. B. dc01.hh.local 10.20.0.10).';
                break;
            }
            $lines[] = trim($dc['host'] . ' ' . $dc['ip']);
        }
        if (count($lines) > self::MAX_DCS) {
            $errors['sso_dcs'] = sprintf('Es sind höchstens %d Domänencontroller möglich.', self::MAX_DCS);
        }
        $values['sso_dcs'] = implode("\n", $lines);

        $joinUser = trim((string) ($input['sso_join_user'] ?? ''));
        if ($joinUser !== '' && (mb_strlen($joinUser) > 255 || preg_match('/[\x00-\x1F\x7F%"]/', $joinUser) === 1)) {
            $errors['sso_join_user'] = 'Ungültiger Kontoname (ohne Steuerzeichen, % und Anführungszeichen).';
        }
        $values['sso_join_user'] = $joinUser;

        $enabled = $additional ? !empty($input['sso_enabled']) : $domain !== '';
        if ($additional) {
            $values['sso_enabled'] = $enabled ? '1' : '0';

            $networks = [];
            foreach (self::splitList((string) ($input['sso_networks'] ?? '')) as $network) {
                $normalized = self::normalizeNetwork($network);
                if ($normalized === null) {
                    $errors['sso_networks'] = 'Ungültiges Netz: ' . $network . ' (CIDR, z. B. 10.20.0.0/16).';
                    break;
                }
                $networks[] = $normalized;
            }
            $values['sso_networks'] = implode("\n", array_values(array_unique($networks)));

            $hostnames = [];
            foreach (self::splitList((string) ($input['sso_hostnames'] ?? '')) as $hostname) {
                $hostname = strtolower($hostname);
                if (preg_match('/^[a-z0-9]([a-z0-9-]{0,62})(\.[a-z0-9]([a-z0-9-]{0,62}))*$/', $hostname) !== 1 || strlen($hostname) > 253) {
                    $errors['sso_hostnames'] = 'Ungültiger Hostname: ' . $hostname;
                    break;
                }
                $hostnames[] = $hostname;
            }
            $values['sso_hostnames'] = implode("\n", array_values(array_unique($hostnames)));

            if ($enabled && $networks === [] && $hostnames === []) {
                $errors['sso_networks'] ??= 'Bitte die Client-Netze und/oder Hostnamen angeben, über die diese Domäne erkannt wird.';
            }
        }

        if ($enabled) {
            if ($domain === '') {
                $errors['sso_domain'] ??= 'Bitte den NetBIOS-Namen der Domäne angeben.';
            }
            if ($lines === []) {
                $errors['sso_dcs'] ??= 'Bitte mindestens einen Domänencontroller angeben.';
            }
            if ($joinUser === '') {
                $errors['sso_join_user'] ??= 'Bitte das Konto für den Domänenbeitritt angeben.';
            }
        }

        return [$values, $errors];
    }

    /**
     * Zerlegt die Domaenencontroller-Liste (je Zeile "host [ip]").
     *
     * @return list<array{host:string,ip:string}>
     */
    public static function parseDcs(string $raw): array
    {
        $dcs = [];
        foreach (preg_split('/\R|;/', $raw) ?: [] as $line) {
            $parts = preg_split('/[\s,]+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($parts === []) {
                continue;
            }
            $dcs[] = ['host' => $parts[0], 'ip' => (string) ($parts[1] ?? '')];
            if (count($parts) > 2) {
                // Mehr als zwei Angaben in einer Zeile: als ungueltig markieren.
                $dcs[count($dcs) - 1]['ip'] = '#';
            }
        }

        return $dcs;
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $raw): array
    {
        return preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Normalisiert ein Netz (CIDR oder einzelne Adresse); null = ungueltig.
     */
    public static function normalizeNetwork(string $network): ?string
    {
        $parts = explode('/', trim($network), 2);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        if (!isset($parts[1])) {
            return $ip . '/' . $max;
        }
        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
            return null;
        }

        return $ip . '/' . (int) $parts[1];
    }

    /**
     * Speichert eine (bereits validierte) weitere Quelle.
     *
     * @param array<string,string> $values  Ergebnis von validateAdditional()
     * @param array<string,string> $changes Passwort-Aenderungen (secretInput())
     */
    public function saveAdditional(?int $id, array $values, array $changes = []): int
    {
        $attributes = [];
        foreach (self::ATTRIBUTE_KEYS as $field => $internal) {
            $attributes[$internal] = $values[$field];
        }

        $data = [
            'source_key' => $values['ldap_key'],
            'label' => $values['ldap_label'],
            'hosts' => $values['ldap_host'],
            'port' => (int) $values['ldap_port'],
            'use_tls' => $values['ldap_use_tls'] === '1',
            'verify_cert' => $values['ldap_verify_cert'] === '1',
            'timeout' => (int) $values['ldap_timeout'],
            'base_dn' => $values['ldap_base_dn'],
            'bind_dn' => $values['ldap_bind_dn'],
            'user_filter' => $values['ldap_filter'],
            'group_base_dn' => $values['ldap_group_base_dn'],
            'group_filter' => $values['ldap_group_filter'],
            'group_name_attribute' => $values['ldap_group_name_attribute'],
            'attributes' => json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'sso_enabled' => ($values['sso_enabled'] ?? '0') === '1',
            'sso_domain' => $values['sso_domain'] ?? '',
            'sso_dcs' => $values['sso_dcs'] ?? '',
            'sso_join_user' => $values['sso_join_user'] ?? '',
            'sso_networks' => $values['sso_networks'] ?? '',
            'sso_hostnames' => $values['sso_hostnames'] ?? '',
            'sort_order' => (int) $values['ldap_sort_order'],
            'active' => $values['ldap_active'] === '1',
        ];
        foreach ($changes as $field => $plain) {
            $data[self::SECRET_FIELDS[$field]] = $plain === '' ? null : $this->secrets->encrypt($plain);
        }

        if ($id === null) {
            return $this->repository->create($data);
        }

        $wasActive = (bool) ($this->repository->find($id)['active'] ?? false);
        $this->repository->update($id, $data);
        if ($wasActive && !$data['active']) {
            // Deaktivierte Quelle: Personen und Gruppen sofort ausblenden.
            $this->repository->deactivateData($id);
        }

        return $id;
    }

    /**
     * Weitere Quellen mit aktiver Windows-Anmeldung (eigene auth-Instanz).
     *
     * @return list<array{key:string,label:string,service:string,networks:list<string>,hostnames:list<string>}>
     */
    public function ssoWorkers(): array
    {
        $workers = [];
        foreach ($this->additionalRows(true) as $row) {
            if (empty($row['sso_enabled'])) {
                continue;
            }
            $key = self::normalizeKey((string) $row['source_key']);
            $workers[] = [
                'key' => $key,
                'label' => (string) $row['label'],
                'service' => self::serviceName($key),
                'networks' => self::splitList((string) ($row['sso_networks'] ?? '')),
                'hostnames' => self::splitList((string) ($row['sso_hostnames'] ?? '')),
            ];
        }

        return array_slice($workers, 0, self::MAX_SSO_ROUTES);
    }

    /**
     * Verteilregeln fuer den HAProxy der Hauptinstanz
     * ("KENNUNG|ziel|netz1,netz2|host1,host2;...", siehe sso-routes.sh).
     */
    public function ssoRoutes(): string
    {
        $routes = [];
        foreach ($this->ssoWorkers() as $worker) {
            if ($worker['networks'] === [] && $worker['hostnames'] === []) {
                continue;
            }
            $routes[] = implode('|', [
                $worker['key'],
                $worker['service'],
                implode(',', $worker['networks']),
                implode(',', $worker['hostnames']),
            ]);
        }

        return implode(';', $routes);
    }

    /**
     * Konfiguration einer auth-Instanz (leere Kennung = Hauptinstanz), die der
     * Container beim Start ueber /internal/sso-config abruft. null = unbekannte
     * oder inaktive Quelle.
     *
     * @return array<string,string>|null
     */
    public function authEnvironment(string $key): ?array
    {
        $key = self::normalizeKey($key);
        $globallyEnabled = (bool) Config::get('sso.enabled', false);

        if ($key === '') {
            $domain = $this->settings->get('sso_domain');
            $env = [
                'SSO_CONFIGURED' => $domain !== '' ? '1' : '0',
                'SSO_ROUTES' => $this->ssoRoutes(),
            ];
            if ($domain !== '') {
                $env += $this->ssoDomainEnvironment(
                    $domain,
                    $this->settings->get('sso_dcs'),
                    $this->settings->get('sso_join_user'),
                    $this->settings->get('sso_join_password')
                );
            }

            return $env;
        }

        if (!self::isValidKey($key)) {
            return null;
        }
        $row = $this->repository->findActiveByKey($key);
        if ($row === null) {
            return null;
        }

        $enabled = $globallyEnabled && !empty($row['sso_enabled']);

        return [
            'SSO_CONFIGURED' => '1',
            'SSO_ENABLED' => $enabled ? 'true' : 'false',
        ] + $this->ssoDomainEnvironment(
            (string) ($row['sso_domain'] ?? ''),
            (string) ($row['sso_dcs'] ?? ''),
            (string) ($row['sso_join_user'] ?? ''),
            (string) ($row['sso_join_password'] ?? '')
        );
    }

    /**
     * @return array<string,string>
     */
    private function ssoDomainEnvironment(string $domain, string $dcs, string $joinUser, string $encryptedPassword): array
    {
        $hosts = [];
        $ips = [];
        foreach (self::parseDcs($dcs) as $dc) {
            $hosts[] = $dc['host'];
            $ips[] = $dc['ip'] !== '' ? $dc['ip'] : '-';
        }

        return [
            'SSO_DOMAIN' => $domain !== '' ? $domain : 'WORKGROUP',
            'SSO_DC' => implode(' ', $hosts),
            'SSO_DC_IP' => in_array(true, array_map(static fn (string $ip): bool => $ip !== '-', $ips), true) ? implode(' ', $ips) : '',
            'SSO_JOIN_USER' => $joinUser,
            'SSO_JOIN_PASSWORD' => $this->decryptSecret($encryptedPassword),
        ];
    }

    /**
     * Eintraege fuer SSO_TRUSTED_PROXY: jede auth-Instanz einer weiteren
     * Quelle darf nur ihre eigene Kennung melden.
     *
     * @return list<string>
     */
    public function trustedWorkerProxies(): array
    {
        return array_map(
            static fn (array $worker): string => $worker['service'] . '=' . $worker['key'],
            $this->ssoWorkers()
        );
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $this->repository->deactivateData($id);
        $this->repository->delete($id);
    }

    /**
     * @return array<string,string>
     */
    public static function decodeAttributes(string $json): array
    {
        $decoded = $json === '' ? null : json_decode($json, true);
        $attributes = self::DEFAULT_ATTRIBUTES;
        if (is_array($decoded)) {
            foreach (self::DEFAULT_ATTRIBUTES as $internal => $default) {
                $value = $decoded[$internal] ?? null;
                if (is_string($value) && Validator::isLdapAttribute($value)) {
                    $attributes[$internal] = $value;
                }
            }
        }

        return $attributes;
    }

    private static function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        $value = trim((string) $value);

        return preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }
}
