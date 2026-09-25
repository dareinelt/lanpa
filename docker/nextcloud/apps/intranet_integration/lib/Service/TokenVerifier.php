<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

/**
 * Prueft kurzlebige HS256-JWTs des Intranets (gemeinsames Euro-Office-Secret).
 */
class TokenVerifier {
    public const AUDIENCE = 'intranet_integration';
    public const MAX_LIFETIME = 120;

    public function verify(string $token, string $secret, ?int $now = null): bool {
        if ($secret === '' || $token === '') {
            return false;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        [$h, $p, $s] = $parts;
        $header = json_decode($this->b64decode($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return false;
        }
        $expected = $this->b64encode(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        if (!hash_equals($expected, $s)) {
            return false;
        }
        $claims = json_decode($this->b64decode($p), true);
        if (!is_array($claims)) {
            return false;
        }
        $now ??= time();
        $iat = (int) ($claims['iat'] ?? 0);
        $exp = (int) ($claims['exp'] ?? 0);
        return ($claims['aud'] ?? '') === self::AUDIENCE
            && $exp >= $now
            && $iat <= $now + 30
            && $exp - $iat <= self::MAX_LIFETIME;
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
