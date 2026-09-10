<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Serverseitige Validierung. Alle Admin-Eingaben laufen hierueber.
 */
final class Validator
{
    public const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Erlaubt absolute http(s)-URLs sowie anwendungsinterne Pfade (/telefonliste).
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }

        // Steuerzeichen sind nie erlaubt.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            // Interner Pfad, kein protokollrelativer Link (//evil.example).
            return !str_starts_with($url, '//');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public static function isHexColor(string $color): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($color)) === 1;
    }

    public static function normalizeHexColor(string $color): ?string
    {
        $color = trim($color);
        if (!self::isHexColor($color)) {
            return null;
        }

        $color = strtolower($color);
        if (strlen($color) === 4) {
            $color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
        }

        return $color;
    }

    public static function isNotEmpty(?string $value, int $max = 255): bool
    {
        $value = trim((string) $value);

        return $value !== '' && mb_strlen($value) <= $max;
    }

    public static function isNavigationType(string $type): bool
    {
        return in_array($type, ['external', 'internal'], true);
    }

    public static function isDescriptionMode(string $mode): bool
    {
        return in_array($mode, ['hover', 'expand', 'both'], true);
    }

    /**
     * Reduziert eine Telefonnummer auf Ziffern (fuer die Suche).
     */
    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?? '';
    }

    /**
     * Erlaubt Ziffern sowie uebliche Trennzeichen einer Telefonnummer.
     */
    public static function isPhoneNumber(string $phone): bool
    {
        $phone = trim($phone);

        return $phone !== '' && preg_match('/^[0-9+\/\-\s()]{2,64}$/', $phone) === 1;
    }

    /**
     * Entfernt Steuerzeichen und begrenzt die Laenge.
     */
    public static function cleanText(?string $value, int $max = 255): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';

        return mb_substr(trim($value), 0, $max);
    }

    public static function isLdapFilter(string $filter): bool
    {
        $filter = trim($filter);
        if ($filter === '' || mb_strlen($filter) > 512) {
            return false;
        }

        if (!str_starts_with($filter, '(') || !str_ends_with($filter, ')')) {
            return false;
        }

        return substr_count($filter, '(') === substr_count($filter, ')');
    }

    /**
     * Ein AD-Attributname (z. B. displayName, telephoneNumber).
     */
    public static function isLdapAttribute(string $attribute): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', trim($attribute)) === 1;
    }

    public static function isHostname(string $host): bool
    {
        $host = trim($host);
        if ($host === '' || mb_strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1;
    }

    public static function isPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }
}
