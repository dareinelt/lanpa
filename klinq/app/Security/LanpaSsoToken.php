<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;

/**
 * Prueft den signierten Einstiegsschein des Intranets (LANPA).
 *
 * Aufbau (JWT-aehnlich, ohne externe Bibliothek):
 *   base64url(JSON) . "." . base64url(HMAC-SHA256)
 *
 * Geprueft werden Signatur, Aussteller, Empfaenger, Gueltigkeitsdauer und die
 * Einmalverwendung (jti). Das Geheimnis ist auf beiden Seiten identisch
 * (KLINQ_SSO_SECRET) und wird von der Intranet-Installation vorgegeben.
 */
final class LanpaSsoToken
{
    public function __construct(private readonly Config $config, private readonly string $appRoot) {}

    public function isConfigured(): bool
    {
        return strlen($this->secret()) >= 32;
    }

    /**
     * Schein pruefen und die enthaltenen Angaben zurueckgeben.
     *
     * @return array<string,mixed>|null null bei ungueltigem, abgelaufenem oder bereits verwendetem Schein
     */
    public function verify(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !$this->isConfigured()) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$body, $signature] = $parts;
        $expected = self::base64Url(hash_hmac('sha256', $body, $this->secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        if ($json === null) {
            return null;
        }

        $claims = json_decode($json, true);
        if (!is_array($claims)) {
            return null;
        }

        if ((string) ($claims['iss'] ?? '') !== (string) $this->config->get('lanpa.issuer', 'lanpa')) {
            return null;
        }
        if ((string) ($claims['aud'] ?? '') !== (string) $this->config->get('lanpa.audience', 'klinq')) {
            return null;
        }
        if ((int) ($claims['exp'] ?? 0) < time()) {
            return null;
        }

        $jti = (string) ($claims['jti'] ?? '');
        if ($jti === '' || !$this->rememberOnce($jti, (int) $claims['exp'])) {
            return null;
        }

        return $claims;
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

    private function secret(): string
    {
        return (string) $this->config->get('lanpa.sso_secret', '');
    }

    /**
     * Vermerkt die Kennung des Scheins. Ein zweiter Aufruf mit derselben Kennung
     * schlaegt fehl (Schutz gegen Wiederverwendung abgefangener Adressen).
     */
    private function rememberOnce(string $jti, int $expiresAt): bool
    {
        $file = $this->storePath();
        $now = time();
        $used = [];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            foreach (is_array($decoded) ? $decoded : [] as $key => $expiry) {
                if ((int) $expiry >= $now) {
                    $used[(string) $key] = (int) $expiry;
                }
            }
        }

        if (array_key_exists($jti, $used)) {
            return false;
        }

        $used[$jti] = $expiresAt;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, (string) json_encode($used), LOCK_EX);

        return true;
    }

    private function storePath(): string
    {
        $path = (string) $this->config->get('lanpa.replay_store', 'storage/tmp/lanpa-sso-used.json');

        return str_starts_with($path, '/') ? $path : rtrim($this->appRoot, '/') . '/' . $path;
    }
}
