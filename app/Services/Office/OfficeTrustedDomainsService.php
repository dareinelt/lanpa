<?php

declare(strict_types=1);

namespace App\Services\Office;

use App\Contracts\OfficeProbeInterface;
use App\Support\Validator;
use Closure;
use JsonException;

/**
 * Vertrauenswuerdige Hostnamen (trusted_domains) von Nextcloud.
 *
 * Nextcloud beantwortet Anfragen nur unter Hostnamen, die in trusted_domains
 * stehen. Das Intranet kennt alle Namen, unter denen es erreichbar ist:
 *
 *   - Hostname aus APP_URL, NEXTCLOUD_EXTRA_TRUSTED_DOMAINS, SSO_SPN_HOSTS
 *   - DNS-Name des Computerkontos nach dem Domaenenbeitritt
 *     (<SSO_NETBIOS_NAME>.<DNS-Domaene aus dem Base DN>) je Domaene mit
 *     Windows-Anmeldung sowie die Hostnamen weiterer Identitaetsquellen
 *   - Common Name und alternative Namen des aktiven HTTPS-Zertifikats
 *
 * Die Liste wird signiert an die Nextcloud-App intranet_integration
 * uebergeben, die trusted_domains setzt. Die Gesundheitspruefung vergleicht
 * den Stand in Nextcloud und uebertraegt bei Abweichung erneut (selbstheilend),
 * sodass nach einem Domaenenbeitritt, einem neuen Zertifikat oder einer
 * weiteren Domaene Nextcloud und Euro-Office ohne Handarbeit erreichbar sind.
 */
final class OfficeTrustedDomainsService
{
    /** Interner Containername (Healthcheck, interne Abfragen des Intranets). */
    public const INTERNAL_HOST = 'nextcloud';

    public const MAX_DOMAINS = 100;

    /** @var Closure(): list<string> */
    private readonly Closure $dynamicHosts;

    /**
     * @param array<string,mixed>          $config       app_url, extra_trusted_domains, spn_hosts
     * @param null|Closure(): list<string> $dynamicHosts Hostnamen aus Datenbank (Identitaetsquellen, Zertifikat)
     */
    public function __construct(
        private readonly OfficeConfigService $office,
        private readonly OfficeProbeInterface $probe,
        private readonly array $config,
        ?Closure $dynamicHosts = null
    ) {
        $this->dynamicHosts = $dynamicHosts ?? static fn (): array => [];
    }

    /**
     * Sortierte, eindeutige Liste der vertrauenswuerdigen Hostnamen.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $candidates = [self::INTERNAL_HOST];

        $appHost = parse_url((string) ($this->config['app_url'] ?? ''), PHP_URL_HOST);
        if (is_string($appHost)) {
            $candidates[] = $appHost;
        }
        foreach (['extra_trusted_domains', 'spn_hosts'] as $key) {
            foreach (self::splitList((string) ($this->config[$key] ?? '')) as $host) {
                $candidates[] = $host;
            }
        }
        foreach (($this->dynamicHosts)() as $host) {
            $candidates[] = (string) $host;
        }

        return self::normalizeList($candidates);
    }

    /**
     * Fingerabdruck der Liste (Vergleich mit dem Stand in Nextcloud).
     */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->domains());
    }

    /**
     * @param list<string> $domains
     */
    public static function fingerprintOf(array $domains): string
    {
        return hash('sha256', implode("\n", self::normalizeList($domains)));
    }

    /**
     * Ob der von Nextcloud gemeldete Stand der eigenen Liste entspricht.
     *
     * @param mixed $remote trusted_domains aus der Diagnose der Nextcloud-App
     */
    public function inSync(mixed $remote): bool
    {
        if (!is_array($remote)) {
            return false;
        }

        return hash_equals($this->fingerprint(), self::fingerprintOf(array_map('strval', array_values($remote))));
    }

    /**
     * @return array{version:int,domains:list<string>}
     */
    public function payload(): array
    {
        return ['version' => 1, 'domains' => $this->domains()];
    }

    /**
     * Uebertraegt die Liste an Nextcloud (signiert, an den Inhalt gebunden).
     *
     * @return array{ok:bool,message:string}
     */
    public function pushToNextcloud(): array
    {
        $secret = $this->office->jwtSecret();
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Kein JWT-Secret konfiguriert (OFFICE_JWT_SECRET_FILE).'];
        }

        try {
            $body = json_encode($this->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return ['ok' => false, 'message' => 'Hostnamen konnten nicht erzeugt werden.'];
        }

        $infra = $this->office->infrastructure();
        $url = rtrim((string) ($infra['nextcloud_internal_url'] ?? ''), '/') . '/index.php/apps/intranet_integration/api/hosts';
        $response = $this->probe->request('POST', $url, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . OfficeJwt::hostsConfigToken($secret, $body),
        ], $body, max(10, (int) ($infra['timeout'] ?? 4) * 3));

        if ($response['error'] !== null || $response['status'] === 0) {
            return ['ok' => false, 'message' => 'Nextcloud nicht erreichbar: ' . ($response['error'] ?? 'keine Antwort')];
        }
        if ($response['status'] === 401) {
            return ['ok' => false, 'message' => 'Nextcloud hat die Übergabe abgelehnt (JWT-Secret abweichend).'];
        }
        if ($response['status'] === 404) {
            return ['ok' => false, 'message' => 'Nextcloud-App intranet_integration ist nicht aktiv oder veraltet.'];
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Unerwartete Antwort von Nextcloud (HTTP ' . $response['status'] . ').'];
        }

        return [
            'ok' => !empty($data['ok']),
            'message' => Validator::cleanText((string) ($data['message'] ?? (!empty($data['ok']) ? 'An Nextcloud übergeben.' : 'Übergabe fehlgeschlagen.')), 300),
        ];
    }

    /**
     * DNS-Domaene aus einem LDAP Base DN (dc=khwf,dc=de -> khwf.de).
     */
    public static function dnsDomainFromBaseDn(string $baseDn): string
    {
        $labels = [];
        foreach (explode(',', $baseDn) as $part) {
            $part = trim($part);
            if (stripos($part, 'dc=') === 0) {
                $labels[] = strtolower(trim(substr($part, 3)));
            }
        }
        $labels = array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));

        return $labels === [] ? '' : implode('.', $labels);
    }

    /**
     * Hostname oder IP-Adresse normalisieren (klein, ohne Schema/Port/Pfad);
     * null bei ungueltigen Werten. Wildcards wie *.firma.local sind erlaubt.
     */
    public static function normalizeHost(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#', $value) === 1) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }
        $value = trim($value, '[]');
        if ($value === '' || $value === 'localhost' || $value === '*') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        // Port abtrennen (Nextcloud vergleicht Hostnamen ohne Port).
        if (preg_match('/^([^:]+):\d{1,5}$/', $value, $m) === 1) {
            $value = $m[1];
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return $value;
        }
        $pattern = '/^(\*\.)?[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?)*\.?$/';
        if (strlen($value) > 253 || preg_match($pattern, $value) !== 1) {
            return null;
        }

        return rtrim($value, '.');
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function normalizeList(array $values): array
    {
        $hosts = [];
        foreach ($values as $value) {
            $host = self::normalizeHost((string) $value);
            if ($host !== null) {
                $hosts[$host] = true;
            }
        }
        $hosts = array_keys($hosts);
        sort($hosts, SORT_STRING);

        return array_slice($hosts, 0, self::MAX_DOMAINS);
    }

    /**
     * @return list<string>
     */
    public static function splitList(string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', $value) ?: [], 'strlen'));
    }
}
