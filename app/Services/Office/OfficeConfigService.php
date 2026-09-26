<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Services\SettingsService;
use App\Support\Validator;

/**
 * Konfiguration der Office-Integration: Infrastruktur (ENV/Secrets) und die im
 * Adminbereich gepflegten Darstellungswerte (Tabelle settings).
 */
final class OfficeConfigService
{
    public const DIRECT_ACCESS_MODES = ['footer', 'redirect'];

    /** SSO-Endpunkt der Nextcloud-App intranet_integration (relativ zum Webroot). */
    public const SSO_LOGIN_ROUTE = 'index.php/apps/intranet_integration/sso';

    public const SETTING_KEYS = [
        'office_footer_enabled',
        'office_footer_text',
        'office_footer_transparency',
        'office_footer_show_logo',
        'office_footer_home_url',
        'office_footer_show_back',
        'office_direct_access',
    ];

    /**
     * Darstellung des Verfuegbarkeitsstatus auf der Office-Kachel.
     */
    public const TILE_STATUS_MODES = [
        'full' => 'Punkt und Text',
        'compact' => 'Nur Farbpunkt',
        'problems' => 'Nur bei Störungen',
        'off' => 'Ausblenden',
    ];

    /**
     * @param array<string,mixed> $config Werte aus config/office.php
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly array $config
    ) {
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return [
            'office_footer_enabled' => '1',
            'office_footer_text' => '',
            'office_footer_transparency' => '40',
            'office_footer_show_logo' => '1',
            'office_footer_home_url' => '/',
            'office_footer_show_back' => '1',
            'office_direct_access' => 'footer',
            'office_tile_status' => 'full',
            'office_owa_url' => '',
        ] + OfficeAiService::defaults();
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function hasJwtSecret(): bool
    {
        return $this->jwtSecret() !== '';
    }

    public function jwtSecret(): string
    {
        return trim((string) ($this->config['jwt_secret'] ?? ''));
    }

    public function jwtHeader(): string
    {
        $header = (string) ($this->config['jwt_header'] ?? 'AuthorizationJwt');

        return preg_match('/^[A-Za-z0-9-]{1,64}$/', $header) === 1 ? $header : 'AuthorizationJwt';
    }

    public function publicPath(): string
    {
        return self::normalizePath((string) ($this->config['public_path'] ?? '/office/'), '/office/');
    }

    /**
     * Einstieg in Nextcloud mit automatischer Anmeldung des im Intranet
     * erkannten Benutzers: signiertes, einmal verwendbares Token an den
     * SSO-Endpunkt der App intranet_integration, die danach zu $target leitet.
     *
     * @param array{username:string,display_name?:string,email?:string} $ssoUser
     */
    public function ssoEntryUrl(array $ssoUser, string $target, ?int $now = null): ?string
    {
        $secret = $this->jwtSecret();
        if ($secret === '' || ($ssoUser['username'] ?? '') === '') {
            return null;
        }

        $token = OfficeJwt::ssoToken(
            $secret,
            (string) $ssoUser['username'],
            (string) ($ssoUser['display_name'] ?? ''),
            (string) ($ssoUser['email'] ?? ''),
            $this->entryTarget($target),
            $now
        );

        return $this->publicPath() . self::SSO_LOGIN_ROUTE . '?' . http_build_query(['token' => $token]);
    }

    public function euroOfficePublicPath(): string
    {
        return self::normalizePath((string) ($this->config['eurooffice_public_path'] ?? '/eurooffice/'), '/eurooffice/');
    }

    /**
     * @return array<string,mixed>
     */
    public function infrastructure(): array
    {
        return $this->config;
    }

    public function footerEnabled(): bool
    {
        return $this->settings->bool('office_footer_enabled');
    }

    public function footerText(): string
    {
        $text = Validator::cleanText($this->settings->get('office_footer_text'), 120);

        return $text !== '' ? $text : Validator::cleanText($this->settings->get('site_title'), 120);
    }

    /**
     * Transparenz der Fusszeile im Ruhezustand in Prozent (0 = deckend).
     */
    public function footerTransparency(): int
    {
        $value = $this->settings->get('office_footer_transparency', '40');

        return Validator::isPercentage($value) ? min(90, (int) $value) : 40;
    }

    public function footerShowLogo(): bool
    {
        return $this->settings->bool('office_footer_show_logo');
    }

    public function footerShowBack(): bool
    {
        return $this->settings->bool('office_footer_show_back');
    }

    public function footerHomeUrl(): string
    {
        $url = trim($this->settings->get('office_footer_home_url', '/'));

        return self::isSafeHomeUrl($url) ? $url : '/';
    }

    public function directAccessMode(): string
    {
        $mode = $this->settings->get('office_direct_access', 'footer');

        return in_array($mode, self::DIRECT_ACCESS_MODES, true) ? $mode : 'footer';
    }

    public function tileStatusEnabled(): bool
    {
        return $this->tileStatusMode() !== 'off';
    }

    public function tileStatusMode(): string
    {
        $mode = $this->settings->get('office_tile_status', 'full');
        // Fruehere Ja/Nein-Einstellung weiterhin verstehen.
        $mode = match ($mode) {
            '1' => 'full',
            '0' => 'off',
            default => $mode,
        };

        return array_key_exists($mode, self::TILE_STATUS_MODES) ? $mode : 'full';
    }

    public static function isTileStatusMode(string $mode): bool
    {
        return array_key_exists($mode, self::TILE_STATUS_MODES);
    }

    public function entryLifetime(): int
    {
        return max(300, (int) ($this->config['entry_lifetime'] ?? 43200));
    }

    /**
     * Aktuelle Werte fuer das Admin-Formular.
     *
     * @return array<string,string>
     */
    public function formValues(): array
    {
        $values = [];
        foreach (self::SETTING_KEYS as $key) {
            $values[$key] = $this->settings->get($key, self::defaults()[$key]);
        }

        return $values;
    }

    /**
     * Prueft die Eingaben des Admin-Formulars.
     *
     * @param array<string,mixed> $input
     *
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $values = [];

        foreach (['office_footer_enabled', 'office_footer_show_logo', 'office_footer_show_back'] as $key) {
            $values[$key] = !empty($input[$key]) ? '1' : '0';
        }

        $values['office_footer_text'] = Validator::cleanText((string) ($input['office_footer_text'] ?? ''), 120);

        $transparency = trim((string) ($input['office_footer_transparency'] ?? '40'));
        if (!Validator::isPercentage($transparency) || (int) $transparency > 90) {
            $errors['office_footer_transparency'] = 'Bitte einen Wert zwischen 0 und 90 Prozent angeben.';
        }
        $values['office_footer_transparency'] = $transparency;

        $homeUrl = trim((string) ($input['office_footer_home_url'] ?? '/'));
        if ($homeUrl === '') {
            $homeUrl = '/';
        }
        if (!self::isSafeHomeUrl($homeUrl)) {
            $errors['office_footer_home_url'] = 'Bitte einen internen Pfad (z. B. /) oder eine http(s)-Adresse angeben.';
        }
        $values['office_footer_home_url'] = $homeUrl;

        $mode = (string) ($input['office_direct_access'] ?? 'footer');
        if (!in_array($mode, self::DIRECT_ACCESS_MODES, true)) {
            $errors['office_direct_access'] = 'Ungültiger Modus.';
            $mode = 'footer';
        }
        $values['office_direct_access'] = $mode;

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Konfiguration, die die Fusszeile in Nextcloud/Euro-Office erhaelt.
     *
     * @param array<string,string> $theme  Farben aus SettingsService::theme()
     *
     * @return array<string,mixed>
     */
    public function footerPayload(array $theme, bool $hasLogo, string $assetVersion): array
    {
        $version = rawurlencode($assetVersion);

        return [
            'enabled' => $this->isEnabled() && $this->footerEnabled(),
            'text' => $this->footerText(),
            'logo_url' => $hasLogo && $this->footerShowLogo() ? '/logo' : '',
            'home_url' => $this->footerHomeUrl(),
            'home_label' => 'Zum Intranet',
            'show_back' => $this->footerShowBack(),
            'transparency' => $this->footerTransparency(),
            'colors' => [
                'primary' => $theme['color_primary'] ?? '#1f4e79',
                'secondary' => $theme['color_secondary'] ?? '#37718e',
                'accent' => $theme['color_accent'] ?? '#c8102e',
                'background' => $theme['color_background'] ?? '#f4f6f8',
                'text' => $theme['color_text'] ?? '#1b1f23',
            ],
            'office_path' => $this->publicPath(),
            'assets' => [
                'css' => '/assets/css/office-footer.css?v=' . $version,
                'js' => '/assets/js/office-footer.js?v=' . $version,
            ],
        ];
    }

    /**
     * Normalisiert das Sprungziel nach dem Intranet-Einstieg. Erlaubt sind nur
     * Pfade unterhalb des Office-Pfads (kein offener Redirect).
     */
    public function entryTarget(?string $target): string
    {
        $base = $this->publicPath();
        $target = trim((string) $target);

        if ($target === '' || str_contains($target, '\\') || preg_match('/[\x00-\x1f\x7f]/', $target) === 1) {
            return $base;
        }

        $path = (string) parse_url($target, PHP_URL_PATH);
        if (
            !str_starts_with($target, $base)
            || str_starts_with($target, '//')
            || parse_url($target, PHP_URL_SCHEME) !== null
            || parse_url($target, PHP_URL_HOST) !== null
            || preg_match('#(^|/)\.\.?(/|$)#', rawurldecode($path)) === 1
        ) {
            return $base;
        }

        return $target;
    }

    public static function isSafeHomeUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
    }

    private static function normalizePath(string $path, string $fallback): string
    {
        $path = '/' . trim($path, '/') . '/';

        return preg_match('#^/[A-Za-z0-9._~/-]*/$#', $path) === 1 && !str_contains($path, '//') ? $path : $fallback;
    }
}
