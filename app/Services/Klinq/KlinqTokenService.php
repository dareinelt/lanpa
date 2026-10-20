<?php

declare(strict_types=1);

namespace App\Services\Klinq;

/**
 * Signierter Einstieg von LANPA in KLINQ.
 *
 * Aufbau (JWT-aehnlich, bewusst ohne externe Bibliothek):
 *   base64url(JSON) . "." . base64url(HMAC-SHA256)
 *
 * KLINQ prueft das Token mit demselben Secret (KLINQ_SSO_SECRET) und
 * uebernimmt daraus Benutzer, Rolle und Berechtigungsgruppen. Das Token ist
 * kurzlebig und einmalig verwendbar (jti).
 */
final class KlinqTokenService
{
    public const ISSUER = 'lanpa';
    public const AUDIENCE = 'klinq';

    public function __construct(private readonly KlinqConfigService $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->config->hasSecret();
    }

    /**
     * @param array<string,mixed> $claims
     */
    public function issue(array $claims, ?int $lifetime = null): string
    {
        $now = time();
        $payload = $claims + [
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'exp' => $now + max(30, $lifetime ?? $this->config->entryLifetime()),
            'jti' => bin2hex(random_bytes(16)),
        ];

        $body = self::base64Url((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $signature = self::base64Url(hash_hmac('sha256', $body, $this->config->secret(), true));

        return $body . '.' . $signature;
    }

    /**
     * Prueft ein Token (vor allem fuer Tests und die Diagnose).
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $token): ?array
    {
        if (!$this->config->hasSecret()) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;
        $expected = self::base64Url(hash_hmac('sha256', $body, $this->config->secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        if (($payload['iss'] ?? '') !== self::ISSUER || ($payload['aud'] ?? '') !== self::AUDIENCE) {
            return null;
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
