<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LdapClientInterface;
use App\Core\Logger;
use App\Support\Validator;
use RuntimeException;

/**
 * LDAP-/LDAPS-Client fuer Active Directory (ext-ldap).
 */
final class LdapClient implements LdapClientInterface
{
    private readonly LdapAttributeMapper $mapper;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(private readonly array $config, private readonly ?Logger $logger = null)
    {
        /** @var array<string,string> $attributes */
        $attributes = $this->config['attributes'] ?? [];
        $this->mapper = new LdapAttributeMapper($attributes);
    }

    public static function isSupported(): bool
    {
        return extension_loaded('ldap');
    }

    public function testConnection(): void
    {
        $connection = $this->connect();
        ldap_unbind($connection);
    }

    /**
     * Prueft jeden konfigurierten Server einzeln (Verbindung + Bind).
     *
     * @return array<string,?string> Server => null (erreichbar) bzw. Fehlermeldung
     */
    public function testHosts(): array
    {
        if (!self::isSupported()) {
            throw new RuntimeException('Die PHP-Erweiterung "ldap" ist nicht installiert.');
        }

        $port = (int) ($this->config['port'] ?? 636);
        $results = [];
        foreach ($this->hosts() as $host) {
            if (!Validator::isHostname($host) || !Validator::isPort($port)) {
                $results[$host] = 'Ungültiger Server oder Port.';
                continue;
            }

            try {
                $connection = $this->connectHost($host, $port);
                @ldap_unbind($connection);
                $results[$host] = null;
            } catch (RuntimeException $exception) {
                $results[$host] = $exception->getMessage();
            }
        }

        return $results;
    }

    /**
     * OID von LDAP_MATCHING_RULE_IN_CHAIN: loest verschachtelte
     * Gruppenmitgliedschaften serverseitig auf (Active Directory, Samba AD).
     */
    private const MATCHING_RULE_IN_CHAIN = '1.2.840.113556.1.4.1941';

    /** LDAP-Ergebniscode "invalidCredentials". */
    private const LDAP_INVALID_CREDENTIALS = 49;

    /**
     * @return list<array<string,string|null>>
     */
    public function fetchUsers(): array
    {
        $filter = $this->userFilter();
        $connection = $this->connect();
        $users = [];

        try {
            $this->pagedSearch(
                $connection,
                (string) ($this->config['base_dn'] ?? ''),
                $filter,
                $this->mapper->attributes(),
                function (array $entry) use (&$users): void {
                    $mapped = $this->mapper->map($entry, is_string($entry['dn'] ?? null) ? $entry['dn'] : null);
                    if ($mapped !== null) {
                        $users[] = $mapped;
                    }
                }
            );
        } finally {
            @ldap_unbind($connection);
        }

        return $users;
    }

    public function hasGroupConfig(): bool
    {
        return $this->groupBaseDns() !== [];
    }

    /**
     * @return list<array{dn:string,name:string,description:?string,members:list<string>}>
     */
    public function fetchGroups(): array
    {
        $baseDns = $this->groupBaseDns();
        if ($baseDns === []) {
            return [];
        }

        $groupFilter = trim((string) ($this->config['group_filter'] ?? ''));
        if ($groupFilter === '') {
            $groupFilter = '(objectClass=group)';
        }
        if (!Validator::isLdapFilter($groupFilter)) {
            throw new RuntimeException('Ungültiger LDAP-Gruppenfilter.');
        }

        $nameAttribute = trim((string) ($this->config['group_name_attribute'] ?? ''));
        if ($nameAttribute === '' || !Validator::isLdapAttribute($nameAttribute)) {
            $nameAttribute = 'cn';
        }

        $userFilter = $this->userFilter();
        $userBase = (string) ($this->config['base_dn'] ?? '');
        $connection = $this->connect();
        $groups = [];

        try {
            foreach ($baseDns as $baseDn) {
                $this->pagedSearch(
                    $connection,
                    $baseDn,
                    $groupFilter,
                    [$nameAttribute, 'cn', 'description'],
                    function (array $entry) use (&$groups, $nameAttribute): void {
                        $dn = is_string($entry['dn'] ?? null) ? $entry['dn'] : '';
                        $name = self::firstValue($entry, $nameAttribute) ?? self::firstValue($entry, 'cn');
                        if ($dn === '' || $name === null || trim($name) === '') {
                            return;
                        }
                        $description = self::firstValue($entry, 'description');
                        $groups[strtolower($dn)] = [
                            'dn' => $dn,
                            'name' => Validator::cleanText($name, 190),
                            'description' => $description === null ? null : Validator::cleanText($description, 255),
                            'members' => [],
                        ];
                    }
                );
            }

            // Mitglieder je Gruppe inkl. verschachtelter Gruppen; es werden nur
            // Benutzer beruecksichtigt, die auch der Benutzer-Suchfilter liefert.
            foreach ($groups as $key => $group) {
                $members = [];
                $filter = sprintf(
                    '(&%s(memberOf:%s:=%s))',
                    $userFilter,
                    self::MATCHING_RULE_IN_CHAIN,
                    ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER)
                );
                $this->pagedSearch(
                    $connection,
                    $userBase,
                    $filter,
                    $this->mapper->attributes(),
                    function (array $entry) use (&$members): void {
                        $mapped = $this->mapper->map($entry, is_string($entry['dn'] ?? null) ? $entry['dn'] : null);
                        if ($mapped !== null && (string) ($mapped['external_id'] ?? '') !== '') {
                            $members[(string) $mapped['external_id']] = true;
                        }
                    }
                );
                $groups[$key]['members'] = array_keys($members);
            }
        } finally {
            @ldap_unbind($connection);
        }

        return array_values($groups);
    }

    private function userFilter(): string
    {
        $filter = (string) ($this->config['filter'] ?? '');
        if (!Validator::isLdapFilter($filter)) {
            throw new RuntimeException('Ungültiger LDAP-Suchfilter.');
        }

        return $filter;
    }

    /**
     * @return list<string>
     */
    private function groupBaseDns(): array
    {
        $dns = $this->config['group_base_dns'] ?? [];

        return is_array($dns) ? array_values(array_filter(array_map('strval', $dns), static fn (string $dn): bool => trim($dn) !== '')) : [];
    }

    /**
     * Seitenweise Suche (Paged Results Control), ruft $onEntry je Eintrag auf.
     *
     * @param \LDAP\Connection $connection
     * @param list<string> $attributes
     * @param callable(array<string,mixed>):void $onEntry
     */
    private function pagedSearch($connection, string $baseDn, string $filter, array $attributes, callable $onEntry): void
    {
        $pageSize = max(50, min(1000, (int) ($this->config['page_size'] ?? 500)));
        $cookie = '';

        do {
            $controls = [[
                'oid' => LDAP_CONTROL_PAGEDRESULTS,
                'value' => ['size' => $pageSize, 'cookie' => $cookie],
            ]];

            $search = @ldap_search(
                $connection,
                $baseDn,
                $filter,
                $attributes,
                0,
                0,
                0,
                LDAP_DEREF_NEVER,
                $controls
            );

            if ($search === false) {
                throw new RuntimeException('LDAP-Suche fehlgeschlagen: ' . ldap_error($connection));
            }

            $responseControls = [];
            if (!@ldap_parse_result($connection, $search, $errorCode, $matchedDn, $errorMessage, $referrals, $responseControls)) {
                throw new RuntimeException('LDAP-Ergebnis konnte nicht gelesen werden.');
            }

            $entries = ldap_get_entries($connection, $search);
            if (!is_array($entries)) {
                throw new RuntimeException('LDAP-Einträge konnten nicht gelesen werden.');
            }

            $count = (int) ($entries['count'] ?? 0);
            for ($i = 0; $i < $count; $i++) {
                /** @var array<string,mixed> $entry */
                $entry = $entries[$i];
                $onEntry($entry);
            }

            $cookie = (string) ($responseControls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '');
        } while ($cookie !== '');
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function firstValue(array $entry, string $attribute): ?string
    {
        /** @var mixed $raw */
        $raw = $entry[strtolower($attribute)] ?? null;
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }

        return is_string($raw) ? $raw : null;
    }

    /**
     * Konfigurierte Server in Prioritaetsreihenfolge.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = $this->config['hosts'] ?? null;
        if (!is_array($hosts)) {
            $hosts = SettingsService::splitHostList((string) ($this->config['host'] ?? ''));
        }

        return array_values(array_filter(array_map(static fn ($host): string => trim((string) $host), $hosts), static fn (string $host): bool => $host !== ''));
    }

    /**
     * Baut die Verbindung zum ersten erreichbaren Server auf. Ist ein Server
     * nicht erreichbar (oder scheitert TLS), wird der naechste versucht. Bei
     * abgewiesenen Anmeldedaten wird sofort abgebrochen, damit das Dienstkonto
     * nicht durch Wiederholungen auf mehreren Servern gesperrt wird.
     *
     * @return \LDAP\Connection
     */
    private function connect()
    {
        if (!self::isSupported()) {
            throw new RuntimeException('Die PHP-Erweiterung "ldap" ist nicht installiert.');
        }

        $hosts = $this->hosts();
        $port = (int) ($this->config['port'] ?? 636);

        if ($hosts === [] || !Validator::isPort($port)) {
            throw new RuntimeException('LDAP-Host oder -Port ist nicht konfiguriert bzw. ungültig.');
        }
        foreach ($hosts as $host) {
            if (!Validator::isHostname($host)) {
                throw new RuntimeException('Ungültiger LDAP-Host: ' . $host);
            }
        }

        if ((string) ($this->config['base_dn'] ?? '') === '') {
            throw new RuntimeException('LDAP Base DN ist nicht konfiguriert.');
        }

        $failures = [];
        foreach ($hosts as $index => $host) {
            try {
                $connection = $this->connectHost($host, $port);
                if ($index > 0 && $this->logger !== null) {
                    $this->logger->warning('LDAP: Ausweichserver verwendet.', [
                        'source' => (string) ($this->config['label'] ?? ''),
                        'host' => $host,
                        'unavailable' => implode(', ', array_keys($failures)),
                    ]);
                }

                return $connection;
            } catch (RuntimeException $exception) {
                if ($exception->getCode() === self::LDAP_INVALID_CREDENTIALS) {
                    throw $exception;
                }
                $failures[$host] = $exception->getMessage();
            }
        }

        $details = [];
        foreach ($failures as $host => $message) {
            $details[] = $host . ': ' . $message;
        }

        throw new RuntimeException(
            (count($hosts) > 1 ? 'Kein LDAP-Server erreichbar (' : '') . implode('; ', $details) . (count($hosts) > 1 ? ')' : '')
        );
    }

    /**
     * @return \LDAP\Connection
     */
    private function connectHost(string $host, int $port)
    {
        $useTls = (bool) ($this->config['use_tls'] ?? true);
        $verifyCert = (bool) ($this->config['verify_cert'] ?? true);
        $timeout = max(1, (int) ($this->config['timeout'] ?? 10));
        $bindDn = (string) ($this->config['bind_dn'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        // Zertifikatspruefung nur, wenn bewusst deaktiviert (dokumentierte Ausnahme fuer Testumgebungen).
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $verifyCert ? LDAP_OPT_X_TLS_HARD : LDAP_OPT_X_TLS_NEVER);

        $uri = self::uri($host, $port, $useTls);
        $connection = @ldap_connect($uri);
        if ($connection === false) {
            throw new RuntimeException('LDAP-Verbindung konnte nicht aufgebaut werden.');
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($connection, LDAP_OPT_TIMELIMIT, $timeout);

        // StartTLS fuer den Klartext-Port, wenn TLS gewuenscht ist.
        if ($useTls && $port !== 636 && !@ldap_start_tls($connection)) {
            @ldap_unbind($connection);
            throw new RuntimeException('StartTLS fehlgeschlagen.');
        }

        $bound = $bindDn === ''
            ? @ldap_bind($connection)
            : @ldap_bind($connection, $bindDn, $password);

        if ($bound !== true) {
            // Fehlermeldung ohne Passwort.
            $errno = ldap_errno($connection);
            $message = 'LDAP-Bind fehlgeschlagen: ' . ldap_error($connection);
            @ldap_unbind($connection);

            throw new RuntimeException($message, $errno === self::LDAP_INVALID_CREDENTIALS ? self::LDAP_INVALID_CREDENTIALS : 0);
        }

        return $connection;
    }

    /**
     * LDAP-URI fuer einen Server; IPv6-Adressen werden in Klammern gesetzt.
     */
    public static function uri(string $host, int $port, bool $useTls): string
    {
        $scheme = $useTls && $port === 636 ? 'ldaps' : 'ldap';
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }
}

