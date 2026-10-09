<?php

declare(strict_types=1);

namespace App\Services\MailProxy;

use App\Contracts\MailProxyTransportInterface;
use App\Core\Logger;
use App\Repositories\MailProxyRepository;
use App\Security\SecretBox;
use App\Services\Orvanta\OrvantaException;
use App\Services\SmtpService;
use App\Support\Validator;

/**
 * Verwaltung des SMTP-/IMAP-Proxys (Admin → Office → SMTP-/IMAP-Proxy):
 * Mailserver je Identitaetsquelle, Postfaecher, Zuordnungen, Vorschlaege,
 * Verbindungstest, Diagnose und Cache-Invalidierung.
 *
 * Jede schreibende Aktion invalidiert den Zuordnungs-Cache (Generation +1,
 * Cache-Dateien loeschen, Proxy-Verbindungspool leeren).
 *
 * Validierungsfehler werden als \InvalidArgumentException mit deutscher,
 * geheimnisfreier Meldung geworfen.
 */
final class MailProxyService
{
    public const SMTP_PORTS = [25, 465, 587, 2525];
    public const IMAP_PORTS = [143, 993];
    public const SMTP_SECURITY = ['starttls', 'tls', 'none'];
    public const IMAP_SECURITY = ['tls', 'starttls'];
    private const PASSWORD_MAX = 4096;
    /** Obergrenze der festen Postfachgroesse je Proxy-Postfach (wie mailbox_quota_mb in Orvanta). */
    public const QUOTA_MAX_MB = 10485760;

    /**
     * @param \Closure(): array{label:string,base_dn:string,hosts?:list<string>} $primarySource Hauptquelle aus den LDAP-Einstellungen
     */
    public function __construct(
        private readonly MailProxyRepository $repository,
        private readonly MailProxyCache $cache,
        private readonly SecretBox $secrets,
        private readonly MailProxyTransportInterface $transport,
        private readonly MailProxyResolver $resolver,
        private readonly Logger $logger,
        private readonly \Closure $primarySource
    ) {
    }

    // ------------------------------------------------------------------
    // Identitaetsquellen
    // ------------------------------------------------------------------

    /**
     * Hauptquelle (id 0) und weitere Identitaetsquellen. `hosts` nennt die
     * konfigurierten Verzeichnisserver (FQDN oder IP-Adresse).
     *
     * @return list<array{id:int,key:string,label:string,base_dn:string,domain:string,hosts:list<string>,active:bool}>
     */
    public function sources(): array
    {
        $primary = ($this->primarySource)();
        $list = [[
            'id' => 0,
            'key' => '',
            'label' => $primary['label'] !== '' ? $primary['label'] : 'Zentrale',
            'base_dn' => $primary['base_dn'],
            'domain' => self::domainFromDn($primary['base_dn']),
            'hosts' => array_values(array_map('strval', (array) ($primary['hosts'] ?? []))),
            'active' => true,
        ]];
        foreach ($this->repository->identitySources() as $source) {
            $list[] = [
                'id' => $source['id'],
                'key' => $source['source_key'],
                'label' => $source['label'],
                'base_dn' => $source['base_dn'],
                'domain' => self::domainFromDn($source['base_dn']),
                'hosts' => $source['hosts'],
                'active' => $source['active'],
            ];
        }

        return $list;
    }

    /**
     * @return array{id:int,key:string,label:string,base_dn:string,domain:string,hosts:list<string>,active:bool}|null
     */
    public function source(int $id): ?array
    {
        foreach ($this->sources() as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }

        return null;
    }

    /**
     * "DC=mvzintsz,DC=local" -> "mvzintsz.local".
     */
    public static function domainFromDn(string $dn): string
    {
        $parts = [];
        foreach (explode(',', $dn) as $component) {
            $component = trim($component);
            if (stripos($component, 'DC=') === 0) {
                $parts[] = strtolower(trim(substr($component, 3)));
            }
        }

        return implode('.', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    // ------------------------------------------------------------------
    // Uebersicht
    // ------------------------------------------------------------------

    /**
     * Daten der Adminseite (ohne Zugangsdaten).
     *
     * @return array{sources:list<array<string,mixed>>,servers:list<array<string,mixed>>,selected:?array<string,mixed>,server:?array<string,mixed>,mailboxes:list<array<string,mixed>>,mappings:list<array<string,mixed>>}
     */
    public function overview(?int $sourceId): array
    {
        $sources = $this->sources();
        $byId = [];
        foreach ($sources as $source) {
            $byId[$source['id']] = $source;
        }
        $servers = [];
        foreach ($this->repository->servers() as $server) {
            $server['source'] = $byId[$server['identity_source_id']] ?? null;
            $servers[] = $server;
        }
        if ($sourceId === null && $servers !== []) {
            $sourceId = (int) $servers[0]['identity_source_id'];
        }
        $selected = $sourceId !== null ? ($byId[$sourceId] ?? null) : null;
        $server = $selected !== null ? $this->repository->findServerBySource($selected['id']) : null;

        return [
            'sources' => $sources,
            'servers' => $servers,
            'selected' => $selected,
            'server' => $server,
            'mailboxes' => $server !== null ? $this->repository->mailboxes((int) $server['id']) : [],
            'mappings' => $selected !== null ? $this->repository->mappings($selected['id']) : [],
        ];
    }

    // ------------------------------------------------------------------
    // Mailserver
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $input
     */
    public function saveServer(array $input, int $id = 0): int
    {
        $values = $this->validateServer($input);
        if ($id > 0) {
            $existing = $this->repository->findServer($id);
            if ($existing === null) {
                throw new \InvalidArgumentException('Die Proxy-Konfiguration wurde nicht gefunden.');
            }
            $this->repository->updateServer($id, $values);
            $sourceId = (int) $existing['identity_source_id'];
        } else {
            $sourceId = (int) ($input['identity_source_id'] ?? -1);
            if ($this->source($sourceId) === null) {
                throw new \InvalidArgumentException('Bitte eine vorhandene Identitätsquelle wählen.');
            }
            if ($this->repository->findServerBySource($sourceId) !== null) {
                throw new \InvalidArgumentException('Für diese Identitätsquelle ist bereits ein Mailserver konfiguriert.');
            }
            $id = $this->repository->createServer($values + ['identity_source_id' => $sourceId]);
        }
        $this->invalidate('server saved', ['server_id' => $id, 'identity_source' => $sourceId]);

        return $id;
    }

    public function setServerActive(int $id, bool $active): void
    {
        if ($this->repository->findServer($id) === null) {
            throw new \InvalidArgumentException('Die Proxy-Konfiguration wurde nicht gefunden.');
        }
        $this->repository->setServerActive($id, $active);
        $this->invalidate($active ? 'server activated' : 'server deactivated', ['server_id' => $id]);
    }

    public function deleteServer(int $id): void
    {
        if ($this->repository->findServer($id) === null) {
            throw new \InvalidArgumentException('Die Proxy-Konfiguration wurde nicht gefunden.');
        }
        if ($this->repository->countMailboxes($id) > 0) {
            throw new \InvalidArgumentException('Die Proxy-Konfiguration hat noch Postfächer. Bitte zuerst die Postfächer löschen oder die Konfiguration deaktivieren.');
        }
        $this->repository->deleteServer($id);
        $this->invalidate('server deleted', ['server_id' => $id]);
    }

    /**
     * Validierung inkl. SSRF-Schutz: nur vollqualifizierte Hostnamen oder
     * oeffentliche/private IP-Adressen, keine Loopback-/Link-Local-Ziele,
     * keine einteiligen Namen (Docker-Dienste), nur Mail-Ports.
     *
     * @param array<string,mixed> $input
     * @return array{name:string,smtp_host:string,smtp_port:int,smtp_security:string,smtp_auth:bool,imap_host:string,imap_port:int,imap_security:string,verify_tls:bool,timeout_seconds:int,active:bool}
     */
    public function validateServer(array $input): array
    {
        $name = Validator::cleanText((string) ($input['name'] ?? ''), 100);
        $smtpHost = strtolower(trim((string) ($input['smtp_host'] ?? '')));
        $imapHost = strtolower(trim((string) ($input['imap_host'] ?? '')));
        $smtpPort = (int) ($input['smtp_port'] ?? 0);
        $imapPort = (int) ($input['imap_port'] ?? 0);
        $smtpSecurity = (string) ($input['smtp_security'] ?? '');
        $imapSecurity = (string) ($input['imap_security'] ?? '');
        $smtpAuth = !empty($input['smtp_auth']);
        $timeout = (int) ($input['timeout_seconds'] ?? 20);

        if ($name === '') {
            throw new \InvalidArgumentException('Bitte einen Namen für die Konfiguration angeben.');
        }
        if (!self::isAllowedHost($smtpHost)) {
            throw new \InvalidArgumentException('Der SMTP-Host ist ungültig oder nicht zulässig (vollqualifizierter Name oder IP-Adresse, keine Loopback-/Link-Local-Adressen).');
        }
        if (!self::isAllowedHost($imapHost)) {
            throw new \InvalidArgumentException('Der IMAP-Host ist ungültig oder nicht zulässig (vollqualifizierter Name oder IP-Adresse, keine Loopback-/Link-Local-Adressen).');
        }
        if (!in_array($smtpPort, self::SMTP_PORTS, true)) {
            throw new \InvalidArgumentException('SMTP-Port nicht zulässig (erlaubt: ' . implode(', ', self::SMTP_PORTS) . ').');
        }
        if (!in_array($imapPort, self::IMAP_PORTS, true)) {
            throw new \InvalidArgumentException('IMAP-Port nicht zulässig (erlaubt: ' . implode(', ', self::IMAP_PORTS) . ').');
        }
        if (!in_array($smtpSecurity, self::SMTP_SECURITY, true)) {
            throw new \InvalidArgumentException('Ungültige SMTP-Verschlüsselung.');
        }
        if (!in_array($imapSecurity, self::IMAP_SECURITY, true)) {
            throw new \InvalidArgumentException('IMAP erfordert TLS oder STARTTLS.');
        }
        if ($smtpAuth && $smtpSecurity === 'none') {
            throw new \InvalidArgumentException('SMTP-Anmeldung benötigt TLS oder STARTTLS.');
        }
        if ($timeout < 5 || $timeout > 60) {
            throw new \InvalidArgumentException('Das Zeitlimit muss zwischen 5 und 60 Sekunden liegen.');
        }

        return [
            'name' => $name,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_security' => $smtpSecurity,
            'smtp_auth' => $smtpAuth,
            'imap_host' => $imapHost,
            'imap_port' => $imapPort,
            'imap_security' => $imapSecurity,
            'verify_tls' => !array_key_exists('verify_tls', $input) || !empty($input['verify_tls']),
            'timeout_seconds' => $timeout,
            'active' => !empty($input['active']),
        ];
    }

    public static function isAllowedHost(string $host): bool
    {
        if ($host === '' || !Validator::isHostname($host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // Private Netze sind fuer interne Mailserver erlaubt; Loopback,
            // Link-Local (u. a. Cloud-Metadaten), unspezifiziert und reserviert nicht.
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
            $packed = inet_pton($host);
            if ($packed === false) {
                return false;
            }
            if (strlen($packed) === 4) {
                $first = ord($packed[0]);
                $second = ord($packed[1]);

                return $first !== 0 && $first !== 127 && !($first === 169 && $second === 254) && $first < 224;
            }
            $hex = bin2hex($packed);

            return !str_starts_with($hex, 'fe8') && !str_starts_with($hex, 'fe9') && !str_starts_with($hex, 'fea') && !str_starts_with($hex, 'feb')
                && !str_starts_with($hex, 'ff') && $hex !== str_repeat('0', 31) . '1' && $hex !== str_repeat('0', 32);
        }
        if (!str_contains($host, '.') || str_ends_with($host, '.localhost') || $host === 'localhost') {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Postfaecher
    // ------------------------------------------------------------------

    /**
     * Postfach anlegen/aendern. Leeres Passwort beim Bearbeiten = unveraendert.
     *
     * @param array<string,mixed> $input username, email_address, display_name, quota_mb, active
     */
    public function saveMailbox(int $serverId, array $input, #[\SensitiveParameter] string $password, int $id = 0): int
    {
        $server = $this->repository->findServer($serverId);
        if ($server === null) {
            throw new \InvalidArgumentException('Die Proxy-Konfiguration wurde nicht gefunden.');
        }
        $username = trim((string) ($input['username'] ?? ''));
        $email = strtolower(trim((string) ($input['email_address'] ?? '')));
        $displayName = Validator::cleanText((string) ($input['display_name'] ?? ''), 190);
        $quotaRaw = trim((string) ($input['quota_mb'] ?? '0'));
        $quotaMb = $quotaRaw === '' ? 0 : (int) $quotaRaw;
        $active = !empty($input['active']);
        if ($username === '' || mb_strlen($username) > 190 || preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
            throw new \InvalidArgumentException('Bitte einen gültigen Benutzernamen (Anmeldename am Mailserver) angeben.');
        }
        if (!SmtpService::validEmail($email)) {
            throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        if (preg_match('/^\d*$/', $quotaRaw) !== 1 || $quotaMb < 0 || $quotaMb > self::QUOTA_MAX_MB) {
            throw new \InvalidArgumentException('Die Postfachgröße muss zwischen 0 (ohne Grenze) und 10.485.760 MB liegen.');
        }
        if ($password !== '' && (strlen($password) > self::PASSWORD_MAX || preg_match('/[\r\n\x00]/', $password) === 1)) {
            throw new \InvalidArgumentException('Das Passwort ist ungültig (keine Zeilenumbrüche, höchstens 4096 Zeichen).');
        }
        if ($this->repository->emailExists($serverId, $email, $id)) {
            throw new \InvalidArgumentException('Ein Postfach mit dieser E-Mail-Adresse ist bereits angelegt.');
        }
        if ($id > 0) {
            $existing = $this->repository->findMailbox($id);
            if ($existing === null || (int) $existing['server_id'] !== $serverId) {
                throw new \InvalidArgumentException('Das Postfach wurde nicht gefunden.');
            }
            $this->repository->updateMailbox($id, $username, $email, $displayName, $password !== '' ? $this->secrets->encrypt($password) : null, $active, $quotaMb);
        } else {
            if ($password === '') {
                throw new \InvalidArgumentException('Bitte ein Passwort für das neue Postfach angeben.');
            }
            $id = $this->repository->createMailbox($serverId, $username, $email, $displayName, $this->secrets->encrypt($password), $active, $quotaMb);
        }
        $this->invalidate('mailbox saved', ['mailbox_id' => $id, 'server_id' => $serverId, 'credentials_changed' => $password !== '']);

        return $id;
    }

    public function deleteMailbox(int $id): void
    {
        if ($this->repository->findMailbox($id) === null) {
            throw new \InvalidArgumentException('Das Postfach wurde nicht gefunden.');
        }
        $this->repository->deleteMailbox($id);
        $this->invalidate('mailbox deleted', ['mailbox_id' => $id]);
    }

    // ------------------------------------------------------------------
    // Zuordnungen
    // ------------------------------------------------------------------

    /**
     * AD-Benutzer (phonebook.id) einem Postfach derselben Identitaetsquelle zuordnen.
     */
    public function saveMapping(int $sourceId, int $phonebookId, int $mailboxId, int $id = 0): int
    {
        if ($this->source($sourceId) === null) {
            throw new \InvalidArgumentException('Die Identitätsquelle wurde nicht gefunden.');
        }
        $user = $this->repository->findUser($phonebookId);
        if ($user === null || !$user['active'] || $user['identity_source_id'] !== $sourceId) {
            throw new \InvalidArgumentException('Bitte einen aktiven AD-Benutzer dieser Identitätsquelle aus den Vorschlägen wählen.');
        }
        $mailbox = $this->repository->findMailbox($mailboxId);
        $server = $mailbox !== null ? $this->repository->findServer((int) $mailbox['server_id']) : null;
        if ($mailbox === null || $server === null || (int) $server['identity_source_id'] !== $sourceId) {
            throw new \InvalidArgumentException('Bitte ein Postfach dieser Identitätsquelle aus den Vorschlägen wählen.');
        }
        if (!$mailbox['active']) {
            throw new \InvalidArgumentException('Das Postfach ist deaktiviert.');
        }
        $userMapping = $this->repository->mappingIdForUser($phonebookId);
        if ($userMapping !== null && $userMapping !== $id) {
            throw new \InvalidArgumentException('Dieser Benutzer ist bereits einem Postfach zugeordnet.');
        }
        $mailboxMapping = $this->repository->mappingIdForMailbox($mailboxId);
        if ($mailboxMapping !== null && $mailboxMapping !== $id) {
            throw new \InvalidArgumentException('Dieses Postfach ist bereits einem anderen Benutzer zugeordnet.');
        }
        if ($id > 0) {
            $existing = $this->repository->findMapping($id);
            if ($existing === null || (int) $existing['identity_source_id'] !== $sourceId) {
                throw new \InvalidArgumentException('Die Zuordnung wurde nicht gefunden.');
            }
            $this->repository->updateMapping($id, $phonebookId, $mailboxId);
        } else {
            $id = $this->repository->createMapping($sourceId, $phonebookId, $mailboxId);
        }
        $this->invalidate('mapping saved', ['mapping_id' => $id, 'identity_source' => $sourceId, 'user' => $phonebookId, 'mailbox_id' => $mailboxId]);

        return $id;
    }

    public function deleteMapping(int $id): void
    {
        if ($this->repository->findMapping($id) === null) {
            throw new \InvalidArgumentException('Die Zuordnung wurde nicht gefunden.');
        }
        $this->repository->deleteMapping($id);
        $this->invalidate('mapping deleted', ['mapping_id' => $id]);
    }

    // ------------------------------------------------------------------
    // Vorschlaege (Autovervollstaendigung, ohne Zugangsdaten)
    // ------------------------------------------------------------------

    /**
     * @return list<array{id:int,label:string,username:string,email:string,source:string,department:string,mapped:bool}>
     */
    public function suggestUsers(int $sourceId, string $term): array
    {
        $source = $this->source($sourceId);
        if ($source === null) {
            return [];
        }
        $sourceName = $source['domain'] !== '' ? $source['domain'] : $source['label'];

        return array_map(static fn (array $user): array => [
            'id' => $user['id'],
            'label' => $user['display_name'] !== '' ? $user['display_name'] : $user['samaccount_name'],
            'username' => $user['samaccount_name'],
            'email' => $user['email'],
            'source' => $sourceName,
            'department' => $user['department'],
            'mapped' => $user['mapped'],
        ], $this->repository->suggestUsers($sourceId, $term));
    }

    /**
     * @return list<array{id:int,label:string,username:string,display_name:string}>
     */
    public function suggestMailboxes(int $sourceId, string $term, int $exceptMappingId = 0): array
    {
        if ($this->source($sourceId) === null) {
            return [];
        }

        return array_map(static fn (array $mailbox): array => [
            'id' => $mailbox['id'],
            'label' => $mailbox['email_address'],
            'username' => $mailbox['username'],
            'display_name' => $mailbox['display_name'],
        ], $this->repository->suggestMailboxes($sourceId, $term, $exceptMappingId));
    }

    // ------------------------------------------------------------------
    // Kennwort durch den Benutzer (Orvanta)
    // ------------------------------------------------------------------

    /**
     * Aktuelles Postfach-Kennwort des angemeldeten Benutzers uebernehmen,
     * z. B. nachdem er es am Mailserver geaendert hat. Das Kennwort wird
     * erst gegen den Mailserver geprueft (IMAP- und ggf. SMTP-Anmeldung,
     * ohne Mailversand) und nur bei Erfolg verschluesselt gespeichert; es
     * ersetzt dann den vom Admin hinterlegten Wert.
     *
     * @throws OrvantaException 422 bei abgelehntem/ungueltigem Kennwort,
     *         sonst Fehler der Postfach-Pruefung (403/409/502/503)
     */
    public function updateUserPassword(MailProxyRoute $route, #[\SensitiveParameter] string $password): void
    {
        if ($password === '' || strlen($password) > self::PASSWORD_MAX || preg_match('/[\r\n\x00]/', $password) === 1) {
            throw new OrvantaException('Bitte das aktuelle Kennwort des Postfachs eingeben (keine Zeilenumbrüche, höchstens 4096 Zeichen).', 422);
        }
        $account = $this->resolver->account($route)->withPassword($password);
        $data = $this->transport->request('mailbox.test', ['account' => $account->payload()]);
        unset($account);
        $imapLogin = false;
        $rejected = false;
        $failure = '';
        foreach ((array) ($data['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $ok = !empty($check['ok']);
            if (($check['code'] ?? '') === 'auth_failed') {
                $rejected = true;
            }
            if ($ok && ($check['name'] ?? '') === 'IMAP-Anmeldung') {
                $imapLogin = true;
            }
            if (!$ok && $failure === '') {
                $failure = mb_substr((string) ($check['message'] ?? ''), 0, 300);
            }
        }
        if ($rejected) {
            $this->logger->warning('mail-proxy user password rejected', ['mailbox_id' => $route->mailboxId]);
            throw new OrvantaException('Der Mailserver hat das Kennwort abgelehnt. Bitte erneut eingeben.', 422);
        }
        if (!$imapLogin) {
            throw new OrvantaException($failure !== '' ? 'Das Kennwort konnte nicht geprüft werden: ' . $failure : 'Das Kennwort konnte nicht geprüft werden.', 502);
        }
        $this->repository->updateMailboxPassword($route->mailboxId, $this->secrets->encrypt($password));
        $this->safeState(fn () => $this->repository->recordSuccess());
        $this->logger->info('mail-proxy password updated by user', ['mailbox_id' => $route->mailboxId, 'identity_source' => $route->sourceId]);
    }

    // ------------------------------------------------------------------
    // Verbindungstest und Diagnose
    // ------------------------------------------------------------------

    /**
     * SMTP und IMAP pruefen (Verbindung, TLS, Anmeldung) – ohne Mailversand.
     *
     * @return array{ok:bool,message:string,checks:list<array{name:string,ok:bool,message:string}>}
     */
    public function testConnection(int $mailboxId): array
    {
        try {
            $account = $this->resolver->accountForTest($mailboxId);
            $data = $this->transport->request('mailbox.test', ['account' => $account->payload()]);
            unset($account);
        } catch (OrvantaException $exception) {
            $this->logger->warning('mail-proxy connection test failed', ['mailbox_id' => $mailboxId, 'error' => $exception->getMessage()]);
            $this->safeState(fn () => $this->repository->recordError($exception->getMessage()));

            return ['ok' => false, 'message' => $exception->getMessage(), 'checks' => []];
        }
        $checks = [];
        foreach ((array) ($data['checks'] ?? []) as $check) {
            if (is_array($check)) {
                $checks[] = [
                    'name' => mb_substr((string) ($check['name'] ?? ''), 0, 60),
                    'ok' => !empty($check['ok']),
                    'message' => mb_substr((string) ($check['message'] ?? ''), 0, 300),
                ];
            }
        }
        $ok = $checks !== [] && !in_array(false, array_column($checks, 'ok'), true);
        $this->safeState(fn () => $ok ? $this->repository->recordSuccess() : $this->repository->recordError('Verbindungstest fehlgeschlagen.'));
        $this->logger->info('mail-proxy connection test', ['mailbox_id' => $mailboxId, 'ok' => $ok]);

        return ['ok' => $ok, 'message' => $ok ? 'SMTP und IMAP erreichbar, Anmeldung erfolgreich.' : 'Der Verbindungstest ist fehlgeschlagen.', 'checks' => $checks];
    }

    /**
     * Verbindungstest je Identitaetsquelle fuer das Nachrichtenfluss-Dashboard.
     * Je aktiver Quelle wird ein aktives Postfach geprueft; das Ergebnis
     * schreibt den Quellenzustand fort. Quellen ohne aktives Postfach gelten
     * als ungeprueft, inaktive Quellen werden nicht geprueft.
     *
     * @param int|null $sourceId Nur diese Identitätsquelle prüfen (null = alle aktiven).
     *
     * @return array<int,array{identity_source_id:int,label:string,mailbox:string,checked:bool,ok:bool,message:string}>
     */
    public function testSources(?int $sourceId = null): array
    {
        $result = [];
        $filter = $sourceId;
        foreach ($this->sources() as $source) {
            if (!$source['active']) {
                continue;
            }
            $sourceId = (int) $source['id'];
            if ($filter !== null && $filter !== $sourceId) {
                continue;
            }
            $probe = $this->repository->probeMailbox($sourceId);            if ($probe['id'] === 0) {
                $this->safeState(fn () => $this->repository->touchSourceCheck($sourceId));
                $result[$sourceId] = [
                    'identity_source_id' => $sourceId,
                    'label' => (string) $source['label'],
                    'mailbox' => '',
                    'checked' => false,
                    'ok' => false,
                    'message' => 'Kein aktives Postfach zum Prüfen vorhanden.',
                ];
                continue;
            }
            $test = $this->testConnection($probe['id']);
            $this->safeState(function () use ($sourceId, $test): void {
                $this->repository->touchSourceCheck($sourceId);
                if ($test['ok']) {
                    $this->repository->recordSourceSuccess($sourceId);
                } else {
                    $this->repository->recordSourceError($sourceId, $test['message']);
                }
            });
            $result[$sourceId] = [
                'identity_source_id' => $sourceId,
                'label' => (string) $source['label'],
                'mailbox' => $probe['email'],
                'checked' => true,
                'ok' => $test['ok'],
                'message' => $test['message'],
            ];
        }

        return $result;
    }

    /**
     * Diagnose fuer Office → Status & Diagnose (ohne Zugangsdaten).
     *
     * @return array{available:bool,counts:array<string,int>,state:array<string,mixed>,service:array{ok:bool,message:string,details:array<string,mixed>},cache_ttl:int}
     */
    public function diagnostics(bool $checkService = true): array
    {
        try {
            $counts = $this->repository->counts();
            $state = $this->repository->state();
        } catch (\PDOException) {
            return [
                'available' => false,
                'counts' => [],
                'state' => [],
                'service' => ['ok' => false, 'message' => 'Tabellen fehlen (Migration 039 ausführen).', 'details' => []],
                'cache_ttl' => $this->cache->ttl(),
            ];
        }
        $service = ['ok' => false, 'message' => 'Nicht geprüft (keine Proxy-Konfiguration).', 'details' => []];
        if ($checkService && $counts['servers'] > 0) {
            $service = $this->transport->health();
        }

        return ['available' => true, 'counts' => $counts, 'state' => $state, 'service' => $service, 'cache_ttl' => $this->cache->ttl()];
    }

    /**
     * Zuordnungs-Cache ungueltig machen (auch von der Identitaetsquellen-
     * Verwaltung aufgerufen). Fehler hier duerfen die Admin-Aktion nicht
     * verhindern; der TTL begrenzt veraltete Eintraege zusaetzlich.
     *
     * @param array<string,mixed> $context
     */
    public function invalidate(string $reason, array $context = []): void
    {
        $hasServers = false;
        try {
            $this->repository->bumpGeneration();
            $hasServers = $this->repository->counts()['servers'] > 0;
        } catch (\PDOException) {
            // Tabellen fehlen: nichts zu invalidieren.
        }
        $this->cache->clear();
        if ($hasServers) {
            try {
                // Nicht-leeres Objekt: ein leeres PHP-Array wuerde als JSON-Liste
                // gesendet und vom Proxy als ungueltig abgelehnt.
                $this->transport->request('cache.invalidate', ['reason' => $reason]);
            } catch (OrvantaException) {
                // Proxy nicht erreichbar: dessen Pool prueft die Generation ohnehin.
            }
        }
        $this->logger->info('mail-proxy configuration changed', ['reason' => $reason] + $context);
    }

    /**
     * @param \Closure(): void $write
     */
    private function safeState(\Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable) {
            // Diagnosezustand ist nachrangig.
        }
    }
}
