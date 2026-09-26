<?php

declare(strict_types=1);

namespace App\Security;

use App\Contracts\AdGroupStoreInterface;
use App\Contracts\IdentitySourceStoreInterface;
use App\Core\Request;
use App\Repositories\PhonebookRepository;

/**
 * Validiert den vom vorgelagerten auth-Container uebergebenen
 * Windows-Benutzernamen (SamAccountName) und ordnet ihn dem lokalen
 * Telefonbuch zu.
 *
 * Wichtig: Der Header wird nur akzeptiert, wenn SSO aktiviert ist und die
 * Anfrage von einem konfigurierten, vertrauenswuerdigen Proxy stammt. Ohne
 * gueltige Zuordnung liefert resolve() null – die Anwendung faellt dann auf
 * das bisherige Verhalten (oeffentliche Elemente) zurueck.
 *
 * Testmodus: Mit SSO_FAKE_USER (nur ausserhalb von APP_ENV=production) wird
 * eine bestehende Windows-Anmeldung simuliert – ohne auth-Container, NTLM und
 * Domaene. Existiert der Benutzer nicht im Telefonbuch, wird ein Testbenutzer
 * mit den Gruppen aus SSO_FAKE_GROUPS angenommen.
 *
 * Mehrere Identitaetsquellen: Jede weitere Domaene (Zweigstelle,
 * Tochtergesellschaft, ...) wird von einer eigenen auth-Instanz gegen ihr
 * eigenes AD geprueft (ohne Vertrauensstellung). Diese Instanz setzt den
 * Header X-Remote-Source auf die Kennung der Quelle; fehlt er, gilt die
 * Hauptquelle. Der Benutzer wird ausschliesslich innerhalb dieser Quelle
 * gesucht, da SamAccountNames nur je Verzeichnis eindeutig sind.
 *
 * Keine Anmeldepflicht: Der auth-Container verlangt NTLM nur am Anmeldepunkt
 * /sso/anmelden. Die dort erkannte Identitaet wird in der Sitzung gemerkt
 * (remember()) und bei jeder Anfrage erneut gegen das Telefonbuch geprueft;
 * ohne Anmeldung bleiben die oeffentlichen Elemente sichtbar.
 *
 * @phpstan-type SsoUser array{id:int,username:string,display_name:string,email:string,groups:list<string>,fake:bool,source_id:int,source_key:string,source_label:string,office_uid:string}
 */
final class SsoAuth
{
    public const SESSION_KEY = 'sso_identity';
    public const ATTEMPT_KEY = 'sso_attempted_at';
    public const RETURN_KEY = 'sso_return';
    public const LOGIN_PATH = '/sso';

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly PhonebookRepository $phonebook,
        private readonly array $config,
        private readonly ?AdGroupStoreInterface $groups = null,
        private readonly ?IdentitySourceStoreInterface $sources = null,
        private readonly string $primaryLabel = ''
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false) || $this->isFake();
    }

    /**
     * Simulierte Anmeldung (Testmodus) aktiv?
     */
    public function isFake(): bool
    {
        return $this->fakeUsername() !== null;
    }

    /**
     * Liefert den angemeldeten Windows-Nutzer oder null, wenn keine (gueltige)
     * SSO-Authentifizierung vorliegt.
     *
     * @return SsoUser|null
     */
    public function resolve(Request $request): ?array
    {
        $fake = $this->fakeUsername();
        if ($fake !== null) {
            return $this->resolveFake($fake);
        }

        if (!$this->isEnabled()) {
            return null;
        }

        return $this->resolveHeader($request) ?? $this->resolveSession();
    }

    /**
     * Benutzer aus dem Header des vertrauenswuerdigen auth-Containers (nur am
     * Anmeldepunkt gesetzt).
     *
     * @return SsoUser|null
     */
    public function resolveHeader(Request $request): ?array
    {
        $proxy = $this->isEnabled() && !$this->isFake() ? $this->trustedProxy($request) : null;
        if ($proxy === null) {
            return null;
        }

        $username = $this->normalizeUsername($this->header($request, (string) $this->config['header']));
        if ($username === null) {
            return null;
        }

        $source = $this->source($this->header($request, (string) ($this->config['source_header'] ?? 'X-Remote-Source')));
        if ($source === null || ($proxy['bound'] !== null && $proxy['bound'] !== $source['key'])) {
            return null;
        }

        $user = $this->phonebook->findBySamAccountName($username, $source['id']);
        if ($user === null) {
            return null;
        }

        // Gruppen stammen aus der AD-Synchronisation (Gruppen-Pfad) und
        // optional zusaetzlich aus einem vertrauenswuerdigen Proxy-Header.
        $groups = $this->normalizeGroups($this->header($request, (string) ($this->config['groups_header'] ?? '')));

        return $this->buildUser($user, $username, $groups, false, $source);
    }

    /**
     * Merkt sich die erkannte Windows-Anmeldung in der Sitzung (neue
     * Sitzungs-ID gegen Session-Fixation).
     *
     * @param SsoUser $user
     */
    public function remember(array $user): void
    {
        Session::regenerate();
        Session::put(self::SESSION_KEY, [
            'username' => (string) $user['username'],
            'source_key' => (string) $user['source_key'],
            'at' => time(),
        ]);
        Session::forget(self::ATTEMPT_KEY);
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    /**
     * Soll der Browser einmal je Sitzung zum Anmeldepunkt geleitet werden?
     * Nur fuer Seitenaufrufe (GET, HTML) ohne erkannte Anmeldung.
     */
    public function shouldAttempt(Request $request): bool
    {
        if ($request->method !== 'GET' || $this->isFake() || !$this->isEnabled() || empty($this->config['auto_login'])) {
            return false;
        }

        if (Session::get(self::ATTEMPT_KEY) !== null) {
            return false;
        }

        $accept = strtolower((string) ($request->server['HTTP_ACCEPT'] ?? ''));
        if (!str_contains($accept, 'text/html')) {
            return false;
        }

        return $this->resolve($request) === null;
    }

    /**
     * Anmeldepunkt samt Ruecksprungziel.
     */
    public static function loginUrl(string $target): string
    {
        return self::LOGIN_PATH . '?' . http_build_query(['ziel' => self::safeTarget($target)]);
    }

    /**
     * Nur lokale Pfade als Ruecksprungziel (kein offener Redirect, keine
     * Schleife ueber den Anmeldepunkt).
     */
    public static function safeTarget(?string $target): string
    {
        $target = trim((string) $target);
        if (
            $target === ''
            || strlen($target) > 2000
            || $target[0] !== '/'
            || str_starts_with($target, '//')
            || str_contains($target, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $target) === 1
            || preg_match('#^/sso(/|\?|$)#', $target) === 1
        ) {
            return '/';
        }

        return $target;
    }

    /**
     * In der Sitzung gemerkte Anmeldung; wird bei jeder Anfrage erneut gegen
     * Telefonbuch und Identitaetsquelle geprueft.
     *
     * @return SsoUser|null
     */
    private function resolveSession(): ?array
    {
        $data = Session::get(self::SESSION_KEY);
        if (!is_array($data)) {
            return null;
        }

        $lifetime = (int) ($this->config['session_lifetime'] ?? 28800);
        if ($lifetime > 0 && time() - (int) ($data['at'] ?? 0) > $lifetime) {
            // Abgelaufen: beim naechsten Seitenaufruf erneut pruefen.
            $this->forget();
            Session::forget(self::ATTEMPT_KEY);

            return null;
        }

        $username = self::normalizeUsername((string) ($data['username'] ?? ''));
        $source = $username !== null ? $this->source((string) ($data['source_key'] ?? '')) : null;
        $user = null;
        if ($username !== null && $source !== null) {
            try {
                $user = $this->phonebook->findBySamAccountName($username, $source['id']);
            } catch (\Throwable) {
                return null;
            }
        }

        if ($username === null || $source === null || $user === null) {
            $this->forget();

            return null;
        }

        return $this->buildUser($user, $username, [], false, $source);
    }

    /**
     * Ermittelt die Identitaetsquelle zur Kennung der auth-Instanz. Leer =
     * Hauptquelle; unbekannte oder deaktivierte Kennungen liefern null.
     *
     * @return array{id:int,key:string,label:string}|null
     */
    private function source(?string $key): ?array
    {
        $key = strtoupper(trim((string) $key));
        if ($key === '') {
            return ['id' => 0, 'key' => '', 'label' => $this->primaryLabel];
        }

        if ($this->sources === null || preg_match('/^[A-Z][A-Z0-9_]{0,31}$/', $key) !== 1) {
            return null;
        }

        try {
            $row = $this->sources->findActiveByKey($key);
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'key' => strtoupper((string) $row['source_key']),
            'label' => (string) ($row['label'] ?? $key),
        ];
    }

    /**
     * @return SsoUser|null
     */
    private function resolveFake(string $username): ?array
    {
        $groups = $this->normalizeGroups((string) ($this->config['fake_groups'] ?? ''));
        $source = $this->source((string) ($this->config['fake_source'] ?? ''));
        if ($source === null) {
            // Unbekannte Test-Quelle: bewusst ohne Datenbankabgleich.
            $key = strtoupper(trim((string) ($this->config['fake_source'] ?? '')));
            $source = ['id' => -1, 'key' => $key, 'label' => $key];
        }

        $user = null;
        try {
            $user = $source['id'] >= 0 ? $this->phonebook->findBySamAccountName($username, $source['id']) : null;
        } catch (\Throwable) {
            // Ohne Datenbank bleibt der Testbenutzer nutzbar.
        }

        if ($user === null) {
            $name = trim((string) ($this->config['fake_display_name'] ?? ''));

            return [
                'id' => 0,
                'username' => $username,
                'display_name' => $name !== '' ? $name : $username,
                'email' => trim((string) ($this->config['fake_email'] ?? '')),
                'groups' => $groups,
                'fake' => true,
                'source_id' => max(0, $source['id']),
                'source_key' => $source['key'],
                'source_label' => $source['label'],
                'office_uid' => self::officeUid($username, $source['key']),
            ];
        }

        return $this->buildUser($user, $username, $groups, true, $source);
    }

    /**
     * Benutzerkennung fuer Nextcloud: Benutzer der Hauptquelle behalten ihren
     * SamAccountName, Benutzer weiterer Quellen erhalten "@kennung" als
     * Zusatz, damit gleichnamige Konten verschiedener Verzeichnisse nicht
     * dasselbe Nextcloud-Konto verwenden ("@" kommt in normalisierten
     * SamAccountNames nicht vor).
     */
    public static function officeUid(string $username, string $sourceKey): string
    {
        $sourceKey = strtolower(trim($sourceKey));

        return $sourceKey === '' ? $username : $username . '@' . $sourceKey;
    }

    /**
     * @param array<string,mixed> $user
     * @param list<string> $groups
     * @param array{id:int,key:string,label:string} $source
     *
     * @return SsoUser
     */
    private function buildUser(array $user, string $username, array $groups, bool $fake, array $source): array
    {
        if ($this->groups !== null) {
            try {
                $groups = array_values(array_unique(array_merge($groups, $this->groups->namesForUser((int) $user['id']))));
            } catch (\Throwable) {
                // Ohne Gruppentabelle (z. B. vor der Migration) nur Benutzerrechte.
            }
        }

        $displayName = trim((string) ($user['display_name'] ?? ''));

        return [
            'id' => (int) $user['id'],
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'email' => trim((string) ($user['email'] ?? '')),
            'groups' => $groups,
            'fake' => $fake,
            'source_id' => $source['id'],
            'source_key' => $source['key'],
            'source_label' => $source['label'],
            'office_uid' => self::officeUid($username, $source['key']),
        ];
    }

    private function fakeUsername(): ?string
    {
        if (empty($this->config['fake_allowed'])) {
            return null;
        }

        return self::normalizeUsername((string) ($this->config['fake_user'] ?? ''));
    }

    /**
     * Prueft, ob die Anfrage von einem vertrauenswuerdigen Proxy stammt.
     */
    public function isTrusted(Request $request): bool
    {
        return $this->trustedProxy($request) !== null;
    }

    /**
     * Ermittelt den passenden Eintrag aus SSO_TRUSTED_PROXY (Komma-Liste aus
     * IP-Adressen oder Hostnamen). Ein Eintrag "host=KENNUNG" bindet den Proxy
     * an eine Identitaetsquelle ("host=" = nur Hauptquelle).
     *
     * @return array{bound:?string}|null null = nicht vertrauenswuerdig
     */
    private function trustedProxy(Request $request): ?array
    {
        $allowed = trim((string) ($this->config['trusted_proxy'] ?? ''));
        if ($allowed === '') {
            return null;
        }

        $remote = (string) ($request->server['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return null;
        }

        foreach (explode(',', $allowed) as $entry) {
            $entry = trim($entry);
            $bound = null;
            if (str_contains($entry, '=')) {
                [$entry, $bound] = array_map('trim', explode('=', $entry, 2));
                $bound = strtoupper($bound);
            }
            if ($entry === '') {
                continue;
            }

            if ($entry === $remote) {
                return ['bound' => $bound];
            }

            // Hostnamen (z. B. "auth") ueber die Docker-DNS aufloesen (alle
            // Adressen, falls der Container in mehreren Netzen haengt).
            if (in_array($remote, self::resolveHost($entry), true)) {
                return ['bound' => $bound];
            }
        }

        return null;
    }

    /**
     * IPv4-Adressen eines Hostnamens (leer, wenn nicht aufloesbar oder
     * bereits eine IP-Adresse).
     *
     * @return list<string>
     */
    public static function resolveHost(string $host): array
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $addresses = @gethostbynamel($host);

        return is_array($addresses) ? array_values($addresses) : [];
    }

    public static function normalizeUsername(?string $raw): ?string
    {
        $username = trim((string) $raw);
        if ($username === '') {
            return null;
        }

        // "benutzer@domain" -> "benutzer"
        $at = strrpos($username, '@');
        if ($at !== false && $at > 0) {
            $username = substr($username, 0, $at);
        }

        // "DOMAIN\benutzer" -> "benutzer"
        $slash = strrpos($username, '\\');
        if ($slash !== false) {
            $username = substr($username, $slash + 1);
        }

        $username = trim($username);
        if ($username === '' || preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $username) !== 1) {
            return null;
        }

        return strtolower($username);
    }

    /**
     * @return list<string>
     */
    private function normalizeGroups(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $groups = [];
        foreach (explode(',', $raw) as $group) {
            $group = trim($group);
            if ($group === '') {
                continue;
            }

            $groups[] = strtolower($group);
        }

        return array_values(array_unique($groups));
    }

    private function header(Request $request, string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        // "X-Remote-User" -> HTTP_X_REMOTE_USER
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $request->server[$key] ?? null;

        return is_string($value) ? trim($value) : null;
    }
}
