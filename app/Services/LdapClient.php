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

    public function verifyUserPassword(string $username, string $password): bool
    {
        if ($username === '' || $password === '' || strlen($password) > 4096) {
            return false;
        }
        if (empty($this->config['use_tls']) || empty($this->config['verify_cert'])) {
            throw new RuntimeException('Die Kennwortbestätigung benötigt LDAP mit TLS und Zertifikatsprüfung.');
        }
        $attribute = (string) ($this->config['attributes']['samaccount_name'] ?? 'sAMAccountName');
        if (!Validator::isLdapAttribute($attribute)) {
            throw new RuntimeException('Ungültiges LDAP-Anmeldeattribut.');
        }
        $connection = $this->connect();
        try {
            $filter = '(&(objectClass=user)(' . $attribute . '=' . ldap_escape($username, '', LDAP_ESCAPE_FILTER)
                . ')(!(userAccountControl:1.2.840.113556.1.4.803:=2)))';
            $result = @ldap_search($connection, (string) $this->config['base_dn'], $filter, ['dn'], 0, 2);
            if ($result === false) {
                throw new RuntimeException('AD-Konto konnte nicht geprüft werden.');
            }
            $entries = ldap_get_entries($connection, $result);
            if (!is_array($entries) || $entries['count'] !== 1) {
                return false;
            }
            if (@ldap_bind($connection, (string) $entries[0]['dn'], $password)) {
                return true;
            }
            if (ldap_errno($connection) !== self::LDAP_INVALID_CREDENTIALS) {
                throw new RuntimeException('AD-Kennwortbestätigung derzeit nicht verfügbar.');
            }

            return false;
        } finally {
            @ldap_unbind($connection);
        }
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
        $rejected = false;
        foreach ($this->hosts() as $host) {
            if (!Validator::isHostname($host) || !Validator::isPort($port)) {
                $results[$host] = 'Ungültiger Server oder Port.';
                continue;
            }
            // Nach abgewiesener Anmeldung keine weiteren Versuche, damit das
            // Dienstkonto nicht durch den Test gesperrt wird.
            if ($rejected) {
                $results[$host] = 'Nicht geprüft – Anmeldung bereits abgewiesen (Schutz vor Kontosperre).';
                continue;
            }

            try {
                $connection = $this->connectHost($host, $port);
                @ldap_unbind($connection);
                $results[$host] = null;
            } catch (RuntimeException $exception) {
                $results[$host] = $exception->getMessage();
                $rejected = $exception->getCode() === self::LDAP_INVALID_CREDENTIALS;
            }
        }

        return $results;
    }

    /** Exchange-Postfachgrenzen am Benutzer bzw. an der Postfachdatenbank (Werte in KB). */
    private const MAILBOX_QUOTA_ATTRIBUTES = ['mdbusedefaults', 'mdbstoragequota', 'mdboverquotalimit', 'mdboverhardquotalimit', 'homemdb'];

    /**
     * Grenzen des Exchange-Postfachs (On-Premise) eines Benutzers aus dem AD:
     * mDBStorageQuota (Warnung), mDBOverQuotaLimit (Senden verbieten) und
     * mDBOverHardQuotaLimit (Senden und Empfangen verbieten). Steht
     * mDBUseDefaults auf TRUE, gelten die Werte der Postfachdatenbank
     * (homeMDB, Konfigurationspartition). null = kein Postfach gefunden.
     *
     * @return array{warning:int,send:int,receive:int,defaults:bool}|null Grenzen in Byte (0 = keine)
     */
    public function mailboxQuota(string $samAccountName): ?array
    {
        $attribute = (string) ($this->config['attributes']['samaccount_name'] ?? 'sAMAccountName');
        if ($samAccountName === '' || !Validator::isLdapAttribute($attribute)) {
            return null;
        }
        $connection = $this->connect();
        try {
            $filter = '(&(objectClass=user)(' . $attribute . '=' . ldap_escape($samAccountName, '', LDAP_ESCAPE_FILTER) . '))';
            $result = @ldap_search($connection, (string) $this->config['base_dn'], $filter, self::MAILBOX_QUOTA_ATTRIBUTES, 0, 2);
            if ($result === false) {
                throw new RuntimeException('LDAP-Suche fehlgeschlagen: ' . ldap_error($connection));
            }
            $entries = ldap_get_entries($connection, $result);
            if (!is_array($entries) || ($entries['count'] ?? 0) !== 1) {
                return null;
            }
            /** @var array<string,mixed> $user */
            $user = $entries[0];
            $database = null;
            $homeMdb = self::firstValue($user, 'homeMDB');
            if ($homeMdb !== null && strtoupper((string) self::firstValue($user, 'mDBUseDefaults')) !== 'FALSE') {
                $read = @ldap_read($connection, $homeMdb, '(objectClass=*)', self::MAILBOX_QUOTA_ATTRIBUTES);
                $dbEntries = $read !== false ? ldap_get_entries($connection, $read) : false;
                if (is_array($dbEntries) && ($dbEntries['count'] ?? 0) === 1) {
                    /** @var array<string,mixed> $database */
                    $database = $dbEntries[0];
                }
            }

            return self::mailboxQuotaFromEntries($user, $database);
        } finally {
            @ldap_unbind($connection);
        }
    }

    /**
     * Wertet die Postfachgrenzen aus den LDAP-Eintraegen von Benutzer und
     * Postfachdatenbank aus (fehlende Attribute = keine Grenze).
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed>|null $database
     *
     * @return array{warning:int,send:int,receive:int,defaults:bool}|null
     */
    public static function mailboxQuotaFromEntries(array $user, ?array $database): ?array
    {
        if (self::firstValue($user, 'homeMDB') === null) {
            return null;
        }
        $defaults = strtoupper((string) self::firstValue($user, 'mDBUseDefaults')) !== 'FALSE';
        $source = $defaults ? $database : $user;
        if ($source === null) {
            return null;
        }
        $bytes = static fn (string $attribute): int => max(0, (int) self::firstValue($source, $attribute)) * 1024;

        return [
            'warning' => $bytes('mDBStorageQuota'),
            'send' => $bytes('mDBOverQuotaLimit'),
            'receive' => $bytes('mDBOverHardQuotaLimit'),
            'defaults' => $defaults,
        ];
    }

    /** SMTP-Adressen des Postfachs am Benutzerobjekt (primaer + Aliase). */
    private const MAILBOX_ADDRESS_ATTRIBUTE = 'proxyAddresses';

    /**
     * Primaere SMTP-Adresse des Exchange-Postfachs eines Benutzers aus dem AD
     * (proxyAddresses). Nur diese Adresse ist als Postfach-Kennung eindeutig:
     * das Attribut "mail" oder der Anmeldename koennen auf eine Adresse
     * zeigen, die Exchange nicht als Alias kennt. null = kein Postfach bzw.
     * keine verwendbare Adresse gefunden.
     */
    public function primaryMailboxAddress(string $samAccountName): ?string
    {
        $attribute = (string) ($this->config['attributes']['samaccount_name'] ?? 'sAMAccountName');
        if ($samAccountName === '' || !Validator::isLdapAttribute($attribute)) {
            return null;
        }
        $connection = $this->connect();
        try {
            $filter = '(&(objectClass=user)(' . $attribute . '=' . ldap_escape($samAccountName, '', LDAP_ESCAPE_FILTER) . '))';
            $result = @ldap_search($connection, (string) $this->config['base_dn'], $filter, [self::MAILBOX_ADDRESS_ATTRIBUTE], 0, 2);
            if ($result === false) {
                throw new RuntimeException('LDAP-Suche fehlgeschlagen: ' . ldap_error($connection));
            }
            $entries = ldap_get_entries($connection, $result);
            if (!is_array($entries) || ($entries['count'] ?? 0) !== 1) {
                return null;
            }
            /** @var array<string,mixed> $user */
            $user = $entries[0];

            return self::primarySmtpFromProxyAddresses($user);
        } finally {
            @ldap_unbind($connection);
        }
    }

    /**
     * Primaere SMTP-Adresse aus proxyAddresses: nur der Eintrag mit
     * Grossbuchstaben-Praefix "SMTP:" ist die primaere Adresse des Postfachs,
     * "smtp:" sind Alias-Adressen (nicht Postfach-Kennung), andere Praefixe
     * (X400, SIP, …) sind keine SMTP-Adressen.
     *
     * @param array<string,mixed> $user LDAP-Eintrag des Benutzers
     */
    public static function primarySmtpFromProxyAddresses(array $user): ?string
    {
        foreach (self::allValues($user, self::MAILBOX_ADDRESS_ATTRIBUTE) as $value) {
            $separator = strpos($value, ':');
            if ($separator === false || substr($value, 0, $separator) !== 'SMTP') {
                continue;
            }
            $address = trim(substr($value, $separator + 1));
            if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                return $address;
            }
        }

        return null;
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
     * Alle Werte eines mehrwertigen LDAP-Attributs (ldap_get_entries liefert
     * ['count' => n, 0 => 'wert', …]).
     *
     * @param array<string,mixed> $entry
     *
     * @return list<string>
     */
    private static function allValues(array $entry, string $attribute): array
    {
        /** @var mixed $raw */
        $raw = $entry[strtolower($attribute)] ?? null;
        if (is_string($raw)) {
            return [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $key => $value) {
            if ($key === 'count' || !is_string($value)) {
                continue;
            }
            $values[] = $value;
        }

        return $values;
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

        // Bind-DN ohne Passwort waere ein nicht authentifizierter Bind, den das
        // AD als "Invalid credentials" abweist – Ursache klar benennen.
        if ($bindDn !== '' && $password === '') {
            throw new RuntimeException(
                'LDAP-Bind nicht möglich: Für das Dienstkonto ist kein (entschlüsselbares) Passwort hinterlegt. Bitte unter Verwaltung → Active Directory neu eingeben.',
                self::LDAP_INVALID_CREDENTIALS
            );
        }

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
            $diagnostic = '';
            if (@ldap_get_option($connection, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic) && is_string($diagnostic)) {
                $detail = self::bindErrorDetail($diagnostic);
                if ($detail !== null) {
                    $message .= ' (' . $detail . ')';
                }
            }
            @ldap_unbind($connection);

            throw new RuntimeException($message, $errno === self::LDAP_INVALID_CREDENTIALS ? self::LDAP_INVALID_CREDENTIALS : 0);
        }

        return $connection;
    }

    /**
     * Klartext zum Unterfehler des Active Directory bei abgewiesenem Bind
     * ("AcceptSecurityContext error, data 775, ..."). AD meldet z. B. auch
     * gesperrte oder abgelaufene Konten als "Invalid credentials".
     */
    public static function bindErrorDetail(string $diagnostic): ?string
    {
        if (preg_match('/\bdata ([0-9a-f]{2,8})\b/i', $diagnostic, $match) !== 1) {
            return null;
        }
        $code = strtolower($match[1]);
        $text = match ($code) {
            '525' => 'Konto nicht gefunden – Bind-DN prüfen',
            '52e' => 'Passwort oder Bind-DN falsch',
            '530' => 'Anmeldung zu dieser Zeit nicht erlaubt',
            '531' => 'Anmeldung von diesem Rechner nicht erlaubt',
            '532' => 'Passwort abgelaufen',
            '533' => 'Konto deaktiviert',
            '568' => 'zu viele Sicherheitskennungen (Gruppen) im Token',
            '701' => 'Konto abgelaufen',
            '773' => 'Passwort muss geändert werden',
            '775' => 'Konto gesperrt – zu viele Fehlanmeldungen',
            default => null,
        };

        return 'AD-Code ' . $code . ($text === null ? '' : ': ' . $text);
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
