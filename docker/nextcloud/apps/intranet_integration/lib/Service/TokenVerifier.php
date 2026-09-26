<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

/**
 * Prueft kurzlebige HS256-JWTs des Intranets (gemeinsames Euro-Office-Secret).
 */
class TokenVerifier {
    public const AUDIENCE = 'intranet_integration';
    public const SSO_AUDIENCE = 'intranet_integration_sso';
    public const AI_AUDIENCE = 'intranet_integration_ai';
    public const MAX_LIFETIME = 120;

    public function verify(string $token, string $secret, ?int $now = null): bool {
        return $this->claims($token, $secret, self::AUDIENCE, $now) !== null;
    }

    /**
     * Schluessel der Anmelde-Tokens (vom gemeinsamen Secret abgeleitet, wie
     * App\Services\Office\OfficeJwt::ssoKey im Intranet).
     */
    public static function ssoKey(string $secret): string {
        return $secret === '' ? '' : hash_hmac('sha256', self::SSO_AUDIENCE, $secret);
    }

    /**
     * Liefert die Claims eines gueltigen Tokens (Signatur, Audience, Laufzeit).
     *
     * @return array<string,mixed>|null
     */
    public function claims(string $token, string $secret, string $audience, ?int $now = null): ?array {
        if ($secret === '' || $token === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode($this->b64decode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $expected = $this->b64encode(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $claims = json_decode($this->b64decode($p), true);
        if (!is_array($claims)) {
            return null;
        }
        $now ??= time();
        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        $valid = ($claims['aud'] ?? '') === $audience
            && $exp >= $now
            && $iat <= $now + 30
            && $exp - $iat <= self::MAX_LIFETIME;
        return $valid ? $claims : null;
    }

    private function b64decode(string $value): string {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($value, true);
    }

    private function b64encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
