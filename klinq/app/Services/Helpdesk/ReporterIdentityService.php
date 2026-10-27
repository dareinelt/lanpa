<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Core\Config;
use App\Core\Request;
use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Services\Ad\AdUserLookupService;
use App\Services\Lanpa\LanpaBridge;
use App\Services\Lanpa\LanpaIdentityService;

/**
 * Erkennt den Melder einer Stoerungsmeldung ohne zusaetzliche Eingabefelder.
 *
 * Reihenfolge der Benutzererkennung:
 *  1. Angaben des Intranets (LANPA): Der auth-Container stellt die Windows-Anmeldung
 *     fest; das Intranet uebergibt Anmeldename, Kontaktdaten und Rechnername mit dem
 *     signierten Einstiegsschein (siehe LanpaIdentityService).
 *  2. optionaler Proxy-Header (nur wenn ausdruecklich freigegeben, da faelschbar)
 *  3. bestehende Anmeldung in der Anwendung (Sitzung)
 *
 * Zum erkannten Anmeldenamen werden Mitarbeiter- und Benutzerdatensatz gesucht und
 * anschliessend mit einem Live-Abgleich aus dem AD/LDAP angereichert (Telefon, E-Mail,
 * Abteilung, Position, Standort). Der Rechnername kommt aus dem Intranet; fehlt er,
 * wird die IP-Adresse per Reverse-DNS aufgeloest.
 */
final class ReporterIdentityService
{
    public function __construct(
        private readonly Config $config,
        private readonly CurrentUser $currentUser,
        private readonly UserRepository $users,
        private readonly EmployeeRepository $employees,
        private readonly AdUserLookupService $directory,
        private readonly LanpaIdentityService $lanpa,
        private readonly LanpaBridge $bridge
    ) {}

    /**
     * Melderdaten zur aktuellen Anfrage zusammenstellen.
     *
     * @return array{username:?string,detected_by:?string,display_name:?string,email:?string,phone:?string,
     *               department:?string,position:?string,location:?string,employee_id:?int,user_id:?int,
     *               directory_match:bool,host:?string,ip:string}
     */
    public function detect(Request $request): array
    {
        [$username, $detectedBy] = $this->username($request);
        $intranet = $this->lanpa->current();
        if ($intranet === null && $username !== null) {
            // Sitzung ohne Schein-Angaben (z. B. abgelaufen): Kontaktdaten beim Intranet nachfragen.
            $intranet = $this->bridge->reporter($username, $request->ip());
        }

        $user = $username !== null ? $this->users->findByUsername($username) : null;
        if ($user === null && $this->currentUser->isAuthenticated()) {
            $user = $this->users->find((int) $this->currentUser->id());
        }

        $employee = $username !== null ? $this->employees->findByUsername($username) : null;
        if ($employee === null && $user !== null && !empty($user['employee_id'])) {
            $employee = $this->employees->find((int) $user['employee_id']);
        }
        if ($username === null && $user !== null) {
            $username = (string) $user['username'];
            $detectedBy = 'session';
        }

        $ad = $username !== null ? $this->directory->findByUsername($username) : null;
        if ($employee === null && $ad !== null) {
            // Ohne Treffer ueber den Anmeldenamen: Mitarbeiter ueber Personalnummer bzw. E-Mail aus dem AD suchen
            $employee = !empty($ad['personnel_number']) ? $this->employees->findByPersonnelNumber((string) $ad['personnel_number']) : null;
            if ($employee === null && !empty($ad['email'])) {
                $employee = $this->employees->findByEmail((string) $ad['email']);
            }
        }

        $ip = (string) ($intranet['ip'] ?? '') !== '' ? (string) $intranet['ip'] : $request->ip();
        $host = trim((string) ($intranet['host'] ?? ''));

        return [
            'username' => $username,
            'detected_by' => $detectedBy,
            // Intranet-Angaben zuerst (frisch aus dem auth-Container), danach AD, Mitarbeiter- und Benutzerdatensatz
            'display_name' => $this->pick($intranet['display_name'] ?? null, $ad['display_name'] ?? null, $employee['display_name'] ?? null, $user['display_name'] ?? null, $username),
            'email' => $this->pick($intranet['email'] ?? null, $ad['email'] ?? null, $employee['email'] ?? null, $user['email'] ?? null),
            'phone' => $this->pick($intranet['phone'] ?? null, $ad['phone'] ?? null, $employee['phone'] ?? null),
            'department' => $this->pick($intranet['department'] ?? null, $ad['department'] ?? null, $employee['department'] ?? null),
            'position' => $this->pick($intranet['position'] ?? null, $ad['position'] ?? null, $employee['position'] ?? null),
            'location' => $this->pick($ad['ad_location'] ?? null, $employee['location_path'] ?? null, $employee['ad_location'] ?? null),
            'employee_id' => $employee !== null ? (int) $employee['id'] : null,
            'user_id' => $user !== null ? (int) $user['id'] : null,
            'directory_match' => $ad !== null,
            'host' => $host !== '' ? mb_substr($host, 0, 255) : $this->hostname($ip),
            'ip' => $ip,
        ];
    }

    /**
     * Angemeldetes Konto ermitteln und auf den reinen Anmeldenamen normalisieren.
     * @return array{0:?string,1:?string} [Benutzername, Erkennungsquelle]
     */
    private function username(Request $request): array
    {
        $intranet = $this->lanpa->current();
        if ($intranet !== null) {
            $value = self::normalize($intranet['username'] ?? null);
            if ($value !== null) {
                return [$value, 'lanpa'];
            }
        }
        if ((bool) $this->config->get('helpdesk.quick_report.trust_user_header', false)) {
            $header = (string) $this->config->get('helpdesk.quick_report.user_header', 'X-Remote-User');
            $value = self::normalize($request->header($header));
            if ($value !== null) {
                return [$value, 'header'];
            }
        }
        if ($this->currentUser->isAuthenticated()) {
            $value = self::normalize($this->currentUser->username());
            if ($value !== null) {
                return [$value, 'session'];
            }
        }

        return [null, null];
    }

    /** "DOMAIN\\benutzer", "benutzer@domain.tld" und "benutzer" auf den Anmeldenamen reduzieren. */
    public static function normalize(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        $backslash = strrpos($value, '\\');
        if ($backslash !== false) {
            $value = substr($value, $backslash + 1);
        }
        $at = strpos($value, '@');
        if ($at !== false) {
            $value = substr($value, 0, $at);
        }
        $value = trim($value);

        return $value !== '' && preg_match('/^[a-zA-Z0-9._\-]{1,100}$/', $value) === 1 ? strtolower($value) : null;
    }

    /** Rechnername per Reverse-DNS; liefert null, wenn nur die IP zurueckkommt. */
    private function hostname(string $ip): ?string
    {
        if ($ip === '' || !(bool) $this->config->get('helpdesk.quick_report.resolve_hostname', true)) {
            return null;
        }
        $host = @gethostbyaddr($ip);
        if (!is_string($host) || $host === '' || $host === $ip) {
            return null;
        }

        return mb_substr($host, 0, 255);
    }

    private function pick(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
