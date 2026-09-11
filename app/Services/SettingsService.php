<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Repositories\SettingsRepository;
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
        return [
            'site_title' => (string) Config::get('app.name', 'Intranet'),
            'site_subtitle' => 'Zentraler Einstieg zu internen Anwendungen',
            'site_subtitle_visible' => '1',
            'landing_intro_visible' => '1',
            'footer_text' => (string) Config::get('app.name', 'Intranet'),
            'description_mode' => 'both',
            'phone_numbers_clickable' => '1',
            'color_primary' => '#1f4e79',
            'color_secondary' => '#37718e',
            'color_accent' => '#c8102e',
            'color_background' => '#f4f6f8',
            'color_text' => '#1b1f23',
            'color_background_dark' => '#12161c',
            'color_text_dark' => '#e8eaed',
            'logo_file' => '',
            'logo_mime' => '',
            'ldap_host' => (string) Config::get('ldap.host', ''),
            'ldap_port' => (string) Config::get('ldap.port', 636),
            'ldap_use_tls' => Config::get('ldap.use_tls', true) ? '1' : '0',
            'ldap_verify_cert' => Config::get('ldap.verify_cert', true) ? '1' : '0',
            'ldap_base_dn' => (string) Config::get('ldap.base_dn', ''),
            'ldap_bind_dn' => (string) Config::get('ldap.bind_dn', ''),
            'ldap_filter' => (string) Config::get('ldap.filter', '(&(objectClass=user)(objectCategory=person))'),
            'ldap_timeout' => (string) Config::get('ldap.timeout', 10),
            'ldap_sync_interval' => (string) Config::get('ldap.sync_interval', 3600),
            'ldap_attr_display_name' => (string) Config::get('ldap.attributes.display_name', 'displayName'),
            'ldap_attr_first_name' => (string) Config::get('ldap.attributes.first_name', 'givenName'),
            'ldap_attr_last_name' => (string) Config::get('ldap.attributes.last_name', 'sn'),
            'ldap_attr_phone' => (string) Config::get('ldap.attributes.phone', 'telephoneNumber'),
            'ldap_attr_mobile' => (string) Config::get('ldap.attributes.mobile', 'mobile'),
            'ldap_attr_email' => (string) Config::get('ldap.attributes.email', 'mail'),
            'ldap_attr_department' => (string) Config::get('ldap.attributes.department', 'department'),
            'ldap_attr_modified' => (string) Config::get('ldap.attributes.modified', 'whenChanged'),
            'ldap_attr_unique_id' => (string) Config::get('ldap.attributes.unique_id', 'objectGUID'),
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
     * Effektive AD-Konfiguration. Das Bind-Passwort stammt immer aus der Umgebung.
     *
     * @return array<string,mixed>
     */
    public function ldapConfig(): array
    {
        return [
            'host' => $this->get('ldap_host'),
            'port' => $this->int('ldap_port', 636),
            'use_tls' => $this->bool('ldap_use_tls'),
            'verify_cert' => $this->bool('ldap_verify_cert'),
            'base_dn' => $this->get('ldap_base_dn'),
            'bind_dn' => $this->get('ldap_bind_dn'),
            'password' => (string) Config::get('ldap.password', ''),
            'filter' => $this->get('ldap_filter'),
            'timeout' => $this->int('ldap_timeout', 10),
            'page_size' => (int) Config::get('ldap.page_size', 500),
            'sync_interval' => $this->int('ldap_sync_interval', 3600),
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
            ],
        ];
    }

    public function isLdapConfigured(): bool
    {
        $config = $this->ldapConfig();

        return $config['host'] !== '' && $config['base_dn'] !== '';
    }
}
