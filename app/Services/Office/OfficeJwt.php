<?php

declare(strict_types=1);

namespace App\Services\Office;

/**
 * Minimaler HS256-JWT-Encoder fuer die Kommunikation mit Euro-Office und der
 * Nextcloud-App intranet_integration (gemeinsames Secret, kurze Laufzeit).
 */
final class OfficeJwt
{
    public const DIAGNOSTICS_AUDIENCE = 'intranet_integration';
    public const SSO_AUDIENCE = 'intranet_integration_sso';
    public const SSO_LIFETIME = 60;

    /**
     * @param array<string,mixed> $claims
     */
    public static function encode(array $claims, string $secret): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = self::b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * Kurzlebiges Token fuer den Diagnose-Endpunkt der Nextcloud-App.
     */
    public static function diagnosticsToken(string $secret, ?int $now = null): string
    {
        $now ??= time();

        return self::encode([
            'aud' => self::DIAGNOSTICS_AUDIENCE,
            'iat' => $now,
            'exp' => $now + 60,
        ], $secret);
    }

    /**
     * Schluessel fuer Anmelde-Tokens: vom gemeinsamen Secret abgeleitet
     * (Domaenentrennung), damit ein Euro-Office-Token nie als Anmeldung gilt.
     */
    public static function ssoKey(string $secret): string
    {
        return $secret === '' ? '' : hash_hmac('sha256', self::SSO_AUDIENCE, $secret);
    }

    /**
     * Kurzlebiges, einmal verwendbares Token, mit dem Nextcloud (App
     * intranet_integration) den im Intranet erkannten Benutzer anmeldet.
     */
    public static function ssoToken(
        string $secret,
        string $username,
        string $displayName,
        string $email,
        string $target,
        ?int $now = null
    ): string {
        $now ??= time();

        return self::encode([
            'aud' => self::SSO_AUDIENCE,
            'sub' => $username,
            'name' => $displayName,
            'email' => $email,
            'target' => $target,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'exp' => $now + self::SSO_LIFETIME,
        ], self::ssoKey($secret));
    }

    /**
     * Prueft Signatur und (falls vorhanden) Ablauf. Fuer Tests und Diagnose.
     *
     * @return array<string,mixed>|null
     */
    public static function decode(string $token, string $secret, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $secret === '') {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $headerData = json_decode(self::unb64($header), true);
        if (!is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $claims = json_decode(self::unb64($payload), true);
        if (!is_array($claims)) {
            return null;
        }

        if (isset($claims['exp']) && (int) $claims['exp'] < ($now ?? time())) {
            return null;
        }

        return $claims;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($value, true);
    }
}
