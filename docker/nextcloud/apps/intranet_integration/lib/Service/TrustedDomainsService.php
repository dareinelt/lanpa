<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Vertrauenswuerdige Hostnamen (trusted_domains) aus dem Intranet.
 *
 * Das Intranet kennt alle Namen, unter denen es (und damit Nextcloud unter
 * /office) erreichbar ist: APP_URL, Kerberos-SPNs, DNS-Name des
 * Computerkontos nach dem Domaenenbeitritt, Hostnamen weiterer Domaenen
 * und das HTTPS-Zertifikat. Es uebergibt die Liste signiert; hier wird sie
 * geprueft und als trusted_domains gesetzt. Der interne Containername bleibt
 * immer enthalten (Healthcheck, Abfragen des Intranets).
 */
class TrustedDomainsService {
    public const INTERNAL_HOST = 'nextcloud';
    public const MAX_DOMAINS = 100;

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>
     */
    public function current(): array {
        $domains = $this->config->getSystemValue('trusted_domains', []);
        return self::normalize(is_array($domains) ? $domains : []);
    }

    /**
     * @return array{trusted_domains: list<string>}
     */
    public function status(): array {
        return ['trusted_domains' => $this->current()];
    }

    /**
     * @param array<string,mixed> $payload vom Intranet (signiert)
     *
     * @return array{ok: bool, message: string, changed: bool}
     */
    public function apply(array $payload): array {
        $domains = $payload['domains'] ?? null;
        if (!is_array($domains) || count($domains) > self::MAX_DOMAINS) {
            return ['ok' => false, 'message' => 'Ungueltige Hostnamen.', 'changed' => false];
        }
        foreach ($domains as $domain) {
            if (!is_string($domain) || self::normalizeHost($domain) === null) {
                return ['ok' => false, 'message' => 'Ungueltiger Hostname: ' . (is_string($domain) ? $domain : gettype($domain)), 'changed' => false];
            }
        }

        $wanted = self::normalize(array_merge([self::INTERNAL_HOST], $domains));
        if ($wanted === $this->current()) {
            return ['ok' => true, 'message' => 'Hostnamen unveraendert.', 'changed' => false];
        }

        try {
            $this->config->setSystemValue('trusted_domains', $wanted);
        } catch (\Throwable $e) {
            $this->logger->error('trusted_domains konnten nicht gesetzt werden', ['exception' => $e]);
            return ['ok' => false, 'message' => 'config.php ist nicht beschreibbar.', 'changed' => false];
        }
        $this->logger->info('trusted_domains aus dem Intranet uebernommen', ['domains' => $wanted]);

        return ['ok' => true, 'message' => 'Hostnamen uebernommen: ' . implode(', ', $wanted), 'changed' => true];
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    public static function normalize(array $values): array {
        $hosts = [];
        foreach ($values as $value) {
            $host = is_string($value) ? self::normalizeHost($value) : null;
            if ($host !== null) {
                $hosts[$host] = true;
            }
        }
        $hosts = array_keys($hosts);
        sort($hosts, SORT_STRING);
        return array_slice($hosts, 0, self::MAX_DOMAINS);
    }

    /**
     * Hostname, IP-Adresse oder Wildcard (*.firma.local), kleingeschrieben.
     */
    public static function normalizeHost(string $value): ?string {
        $value = strtolower(trim($value));
        $value = trim($value, '[]');
        if ($value === '' || strlen($value) > 253) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        if (preg_match('/^([^:]+):\d{1,5}$/', $value, $m) === 1) {
            $value = $m[1];
            if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                return $value;
            }
        }
        $pattern = '/^(\*\.)?[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?)*$/';
        return preg_match($pattern, $value) === 1 ? $value : null;
    }
}
