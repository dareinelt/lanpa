<?php

declare(strict_types=1);

namespace App\Security;

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
 */
final class SsoAuth
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly PhonebookRepository $phonebook,
        private readonly array $config
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /**
     * Liefert den angemeldeten Windows-Nutzer oder null, wenn keine (gueltige)
     * SSO-Authentifizierung vorliegt.
     *
     * @return array{id:int,username:string,display_name:string,groups:list<string>}|null
     */
    public function resolve(Request $request): ?array
    {
        if (!$this->isEnabled() || !$this->isTrusted($request)) {
            return null;
        }

        $username = $this->normalizeUsername($this->header($request, (string) $this->config['header']));
        if ($username === null) {
            return null;
        }

        $user = $this->phonebook->findBySamAccountName($username);
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'username' => $username,
            'display_name' => (string) ($user['display_name'] ?? $username),
            'groups' => $this->normalizeGroups($this->header($request, (string) ($this->config['groups_header'] ?? ''))),
        ];
    }

    /**
     * Prueft, ob die Anfrage von einem vertrauenswuerdigen Proxy stammt.
     */
    public function isTrusted(Request $request): bool
    {
        $allowed = trim((string) ($this->config['trusted_proxy'] ?? ''));
        if ($allowed === '') {
            return false;
        }

        $remote = (string) ($request->server['REMOTE_ADDR'] ?? '');
        if ($remote === '') {
            return false;
        }

        foreach (explode(',', $allowed) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if ($entry === $remote) {
                return true;
            }

            // Hostnamen (z. B. "auth") ueber die Docker-DNS aufloesen.
            $resolved = @gethostbyname($entry);
            if ($resolved !== $entry && $resolved === $remote) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalisiert den uebergebenen Benutzernamen.
     *
     * Akzeptiert die ueblichen Schreibweisen "DOMAIN\benutzer",
     * "benutzer@domain" und "benutzer"; zurueckgegeben wird der reine
     * SamAccountName in Kleinschreibung (SamAccountNames sind im AD
     * case-insensitiv).
     */
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
