<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\SettingsRepository;
use App\Services\Office\OfficeConfigService;
use App\Support\Validator;

/**
 * Liest/schreibt die in der Datenbank gepflegte Konfiguration.
 * ENV-Werte dienen als Vorgabe, die Datenbank kann sie (ausser Secrets) ueberschreiben.
 */
final class SettingsService
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    /**
     * @return array<string,string>
     */
    public function defaults(): array
    {
        return OfficeConfigService::defaults() + [
            'site_title' => (string) Config::get('app.name', 'Intranet'),
            'site_subtitle' => 'Zentraler Einstieg zu internen Anwendungen',
            'site_subtitle_visible' => '1',
            'landing_intro_visible' => '1',
            'footer_text' => (string) Config::get('app.name', 'Intranet'),
            'description_mode' => 'both',
            'phone_numbers_clickable' => '1',
            'documentation_enabled' => '1',
            'nav_tree_mode' => '0',
            'color_primary' => '#1f4e79',
            'color_secondary' => '#37718e',
            'color_accent' => '#c8102e',
            'color_background' => '#f4f6f8',
            'color_text' => '#1b1f23',
            'color_background_dark' => '#12161c',
            'color_text_dark' => '#e8eaed',
            'nav_opacity' => '92',
            'tile_background_color' => '',
            'tile_background_opacity' => '97',
            'logo_file' => '',
            'logo_mime' => '',
            'background_file' => '',
            'background_mime' => '',
            'ldap_label' => (string) Config::get('ldap.label', 'Zentrale'),
            'ldap_host' => (string) Config::get('ldap.host', ''),
            'ldap_port' => (string) Config::get('ldap.port', 636),
            'ldap_use_tls' => Config::get('ldap.use_tls', true) ? '1' : '0',
            'ldap_verify_cert' => Config::get('ldap.verify_cert', true) ? '1' : '0',
            'ldap_base_dn' => (string) Config::get('ldap.base_dn', ''),
            'ldap_bind_dn' => (string) Config::get('ldap.bind_dn', ''),
            'ldap_filter' => (string) Config::get('ldap.filter', '(&(objectClass=user)(objectCategory=person))'),
            'ldap_timeout' => (string) Config::get('ldap.timeout', 10),
            'ldap_sync_interval' => (string) Config::get('ldap.sync_interval', 3600),
            'ldap_group_base_dn' => (string) Config::get('ldap.group_base_dn', ''),
            'ldap_group_filter' => (string) Config::get('ldap.group_filter', '(objectClass=group)'),
            'ldap_group_name_attribute' => (string) Config::get('ldap.group_name_attribute', 'cn'),
            'ldap_attr_display_name' => (string) Config::get('ldap.attributes.display_name', 'displayName'),
            'ldap_attr_first_name' => (string) Config::get('ldap.attributes.first_name', 'givenName'),
            'ldap_attr_last_name' => (string) Config::get('ldap.attributes.last_name', 'sn'),
            'ldap_attr_phone' => (string) Config::get('ldap.attributes.phone', 'telephoneNumber'),
            'ldap_attr_mobile' => (string) Config::get('ldap.attributes.mobile', 'mobile'),
            'ldap_attr_email' => (string) Config::get('ldap.attributes.email', 'mail'),
            'ldap_attr_department' => (string) Config::get('ldap.attributes.department', 'department'),
            'ldap_attr_modified' => (string) Config::get('ldap.attributes.modified', 'whenChanged'),
            'ldap_attr_unique_id' => (string) Config::get('ldap.attributes.unique_id', 'objectGUID'),
            'ldap_attr_samaccount_name' => (string) Config::get('ldap.attributes.samaccount_name', 'sAMAccountName'),
            'alarm_host' => (string) Config::get('alarm.host', ''),
            'alarm_username' => (string) Config::get('alarm.username', ''),
            'alarm_password' => '',
            'alarm_single_custom' => '0',
            'alarm_single_host' => '',
            'alarm_single_username' => '',
            'alarm_single_password' => '',
            'snmp_community' => (string) Config::get('snmp.community', 'public'),
            'snmp_sys_location' => (string) Config::get('snmp.sys_location', 'Intranet'),
            'snmp_sys_contact' => (string) Config::get('snmp.sys_contact', 'admin@example.internal'),
            'sms_code_template' => 'Ihr Zugangscode: {code}',
            'sms_code_timeout' => '120',
            'sms_code_secret' => '',
        ];
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = $this->repository->all();
            $this->cache = array_merge($this->defaults(), array_filter(
                $stored,
                static fn (string $value): bool => $value !== ''
            ));
        }

        return $this->cache;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->get($key), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string,string> $values
     */
    public function update(array $values): void
    {
        $this->repository->setMany($values);
        $this->cache = null;
    }

    /**
     * Leert den Zwischenspeicher (z. B. nach einem Sicherungs-Import).
     */
    public function resetCache(): void
    {
        $this->cache = null;
    }

    public function descriptionMode(): string
    {
        $mode = $this->get('description_mode', 'both');

        return Validator::isDescriptionMode($mode) ? $mode : 'both';
    }

    public function phoneNumbersClickable(): bool
    {
        return $this->bool('phone_numbers_clickable');
    }

    /**
     * Anzeige der Handbücher (Anwender-/Administratorhandbuch) in der Anwendung.
     */
    public function documentationEnabled(): bool
    {
        return $this->bool('documentation_enabled');
    }

    /**
     * Baumansicht der Navigation im Administrationsbereich.
     */
    public function navTreeMode(): bool
    {
        return $this->bool('nav_tree_mode');
    }

    /**
     * @return array<string,string>
     */
    public function theme(): array
    {
        $defaults = $this->defaults();
        $keys = [
            'color_primary',
            'color_secondary',
            'color_accent',
            'color_background',
            'color_text',
            'color_background_dark',
            'color_text_dark',
        ];

        $theme = [];
        foreach ($keys as $key) {
            $value = Validator::normalizeHexColor($this->get($key, $defaults[$key]));
            $theme[$key] = $value ?? $defaults[$key];
        }

        return $theme;
    }

    /**
     * Deckkraft der Navigationselemente in Prozent (0-100).
     */
    public function navOpacity(): int
    {
        $value = $this->get('nav_opacity', $this->defaults()['nav_opacity']);

        if (!Validator::isPercentage($value)) {
            return (int) $this->defaults()['nav_opacity'];
        }

        return (int) $value;
    }

    /**
     * Zentrale Kachel-Hintergrundfarbe (leer = Standard-Oberflächenfarbe).
     */
    public function tileBackgroundColor(): string
    {
        $color = $this->get('tile_background_color', '');

        return Validator::normalizeHexColor($color) ?? '';
    }

    /**
     * Zentrale Kachel-Deckkraft in Prozent (0-100).
     */
    public function tileBackgroundOpacity(): int
    {
        $value = $this->get('tile_background_opacity', $this->defaults()['tile_background_opacity']);

        if (!Validator::isPercentage($value)) {
            return (int) $this->defaults()['tile_background_opacity'];
        }

        return (int) $value;
    }

    /**
     * Effektive AD-Konfiguration. Das Bind-Passwort stammt immer aus der Umgebung.
     *
     * @return array<string,mixed>
     */
    public function ldapConfig(): array
    {
        $hosts = self::splitHostList($this->get('ldap_host'));
        $label = trim($this->get('ldap_label'));

        return [
            // Hauptquelle: ID 0, ohne Kennung.
            'id' => 0,
            'key' => '',
            'label' => $label !== '' ? $label : 'Zentrale',
            'hosts' => $hosts,
            'host' => $hosts[0] ?? '',
            'port' => $this->int('ldap_port', 636),
            'use_tls' => $this->bool('ldap_use_tls'),
            'verify_cert' => $this->bool('ldap_verify_cert'),
            'base_dn' => $this->get('ldap_base_dn'),
            'bind_dn' => $this->get('ldap_bind_dn'),
            // Bind-Passwort: verschluesselt in ldap_bind_password, entschluesselt
            // von IdentitySourceService::primaryConfig().
            'password' => '',
            'filter' => $this->get('ldap_filter'),
            'timeout' => $this->int('ldap_timeout', 10),
            'page_size' => (int) Config::get('ldap.page_size', 500),
            'sync_interval' => $this->int('ldap_sync_interval', 3600),
            'group_base_dns' => self::splitDnList($this->get('ldap_group_base_dn')),
            'group_filter' => $this->get('ldap_group_filter'),
            'group_name_attribute' => $this->get('ldap_group_name_attribute'),
            'attributes' => [
                'display_name' => $this->get('ldap_attr_display_name'),
                'first_name' => $this->get('ldap_attr_first_name'),
                'last_name' => $this->get('ldap_attr_last_name'),
                'phone' => $this->get('ldap_attr_phone'),
                'mobile' => $this->get('ldap_attr_mobile'),
                'email' => $this->get('ldap_attr_email'),
                'department' => $this->get('ldap_attr_department'),
                'modified' => $this->get('ldap_attr_modified'),
                'unique_id' => $this->get('ldap_attr_unique_id'),
                'samaccount_name' => $this->get('ldap_attr_samaccount_name'),
            ],
        ];
    }

    public function isLdapConfigured(): bool
    {
        $config = $this->ldapConfig();

        return $config['hosts'] !== [] && $config['base_dn'] !== '';
    }

    /**
     * Zerlegt eine Serverliste (IP-Adressen oder Hostnamen, getrennt durch
     * Zeilenumbruch, Komma, Semikolon oder Leerzeichen). Reihenfolge = Prioritaet.
     *
     * @return list<string>
     */
    public static function splitHostList(string $raw): array
    {
        $hosts = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $host) {
            $host = trim($host);
            if ($host !== '' && !isset($hosts[strtolower($host)])) {
                $hosts[strtolower($host)] = $host;
            }
        }

        return array_values($hosts);
    }

    /**
     * Zerlegt eine Liste von DNs (getrennt durch ";" oder Zeilenumbruch).
     *
     * @return list<string>
     */
    public static function splitDnList(string $raw): array
    {
        $dns = [];
        foreach (preg_split('/[;\r\n]+/', $raw) ?: [] as $dn) {
            $dn = trim($dn);
            if ($dn !== '' && !isset($dns[strtolower($dn)])) {
                $dns[strtolower($dn)] = $dn;
            }
        }

        return array_values($dns);
    }

    /**
     * Effektive SMS-Gateway-Konfiguration. Das Passwort stammt bevorzugt aus der
     * Umgebung (ALARM_PASSWORD / ALARM_PASSWORD_FILE) und wird nie zurückgegeben
     * oder gerendert. Andernfalls wird der in der Datenbank hinterlegte Wert
     * verwendet (ebenfalls nie exponiert).
     *
     * @return array{host:string,username:string,password:string}
     */
    public function alarmConfig(): array
    {
        $envPassword = (string) Config::get('alarm.password', '');

        return [
            'host' => $this->get('alarm_host'),
            'username' => $this->get('alarm_username'),
            'password' => $envPassword !== '' ? $envPassword : $this->get('alarm_password'),
        ];
    }

    public function isAlarmConfigured(): bool
    {
        $config = $this->alarmConfig();

        return $config['host'] !== '' && $config['username'] !== '' && $config['password'] !== '';
    }

    /**
     * Effektive Konfiguration fuer den Einzelnummern-Versand. Ohne Opt-In
     * ("Einzelversand benötigt andere Einstellungen") gelten die Werte des
     * Gruppenversands. Bei aktivem Opt-In werden die abweichenden Werte aus
     * der Datenbank verwendet.
     *
     * @return array{host:string,username:string,password:string}
     */
    public function alarmSingleConfig(): array
    {
        if (!$this->bool('alarm_single_custom')) {
            return $this->alarmConfig();
        }

        return [
            'host' => $this->get('alarm_single_host'),
            'username' => $this->get('alarm_single_username'),
            'password' => $this->get('alarm_single_password'),
        ];
    }

    /**
     * Effektive SNMP-Konfiguration (net-snmp/snmpd). Der am Host
     * veroeffentlichte UDP-Port bleibt ueber die Umgebung konfiguriert.
     *
     * @return array{community:string,sys_location:string,sys_contact:string}
     */
    public function snmpConfig(): array
    {
        return [
            'community' => $this->get('snmp_community'),
            'sys_location' => $this->get('snmp_sys_location'),
            'sys_contact' => $this->get('snmp_sys_contact'),
        ];
    }
}
