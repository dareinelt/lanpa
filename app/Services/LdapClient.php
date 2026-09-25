<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LdapClientInterface;
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
    public function __construct(private readonly array $config)
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
     * OID von LDAP_MATCHING_RULE_IN_CHAIN: loest verschachtelte
     * Gruppenmitgliedschaften serverseitig auf (Active Directory, Samba AD).
     */
    private const MATCHING_RULE_IN_CHAIN = '1.2.840.113556.1.4.1941';

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
     * @return \LDAP\Connection
     */
    private function connect()
    {
        if (!self::isSupported()) {
            throw new RuntimeException('Die PHP-Erweiterung "ldap" ist nicht installiert.');
        }

        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 636);
        $useTls = (bool) ($this->config['use_tls'] ?? true);
        $verifyCert = (bool) ($this->config['verify_cert'] ?? true);
        $timeout = max(1, (int) ($this->config['timeout'] ?? 10));
        $bindDn = (string) ($this->config['bind_dn'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if (!Validator::isHostname($host) || !Validator::isPort($port)) {
            throw new RuntimeException('LDAP-Host oder -Port ist nicht konfiguriert bzw. ungültig.');
        }

        if ((string) ($this->config['base_dn'] ?? '') === '') {
            throw new RuntimeException('LDAP Base DN ist nicht konfiguriert.');
        }

        // Zertifikatspruefung nur, wenn bewusst deaktiviert (dokumentierte Ausnahme fuer Testumgebungen).
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $verifyCert ? LDAP_OPT_X_TLS_HARD : LDAP_OPT_X_TLS_NEVER);

        $uri = sprintf('%s://%s:%d', $useTls && $port === 636 ? 'ldaps' : 'ldap', $host, $port);
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
            throw new RuntimeException('StartTLS fehlgeschlagen.');
        }

        $bound = $bindDn === ''
            ? @ldap_bind($connection)
            : @ldap_bind($connection, $bindDn, $password);

        if ($bound !== true) {
            // Fehlermeldung ohne Passwort.
            throw new RuntimeException('LDAP-Bind fehlgeschlagen: ' . ldap_error($connection));
        }

        return $connection;
    }
}
