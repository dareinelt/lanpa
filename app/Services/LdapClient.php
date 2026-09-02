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
     * @return list<array<string,string|null>>
     */
    public function fetchUsers(): array
    {
        $connection = $this->connect();

        $baseDn = (string) ($this->config['base_dn'] ?? '');
        $filter = (string) ($this->config['filter'] ?? '');
        if (!Validator::isLdapFilter($filter)) {
            ldap_unbind($connection);

            throw new RuntimeException('Ungültiger LDAP-Suchfilter.');
        }

        $attributes = $this->mapper->attributes();
        $pageSize = max(50, min(1000, (int) ($this->config['page_size'] ?? 500)));
        $cookie = '';
        $users = [];

        try {
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
                    $mapped = $this->mapper->map($entry, is_string($entry['dn'] ?? null) ? $entry['dn'] : null);
                    if ($mapped !== null) {
                        $users[] = $mapped;
                    }
                }

                $cookie = (string) ($responseControls[LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '');
            } while ($cookie !== '');
        } finally {
            @ldap_unbind($connection);
        }

        return $users;
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
