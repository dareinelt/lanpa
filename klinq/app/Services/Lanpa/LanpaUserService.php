<?php

declare(strict_types=1);

namespace App\Services\Lanpa;

use App\Core\Logger;
use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepository;

/**
 * Legt Benutzer aus dem Intranet (LANPA) an bzw. gleicht sie ab.
 *
 * Rolle und Berechtigungsgruppen werden im Intranet ueber AD-Gruppen vergeben und
 * mit dem Einstiegsschein uebergeben. Sie ersetzen bei jeder Anmeldung die in KLINQ
 * gespeicherten Zuordnungen; damit ist das Intranet die einzige Quelle.
 */
final class LanpaUserService
{
    /** Rolle fuer Benutzer ohne ausdrueckliche KLINQ-Berechtigung (nur Serviceportal). */
    public const FALLBACK_ROLE = 'portal';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EmployeeRepository $employees,
        private readonly Logger $logger
    ) {}

    /**
     * Benutzer anhand der Schein-Angaben anlegen oder aktualisieren.
     *
     * @param array<string,mixed> $claims
     * @return array<string,mixed>|null Benutzerdatensatz inkl. Rolle und Gruppen
     */
    public function provision(array $claims): ?array
    {
        $username = self::normalizeUsername((string) ($claims['sub'] ?? ''));
        if ($username === null) {
            return null;
        }

        $role = (string) ($claims['role'] ?? '');
        $roleId = $role === '' ? null : $this->users->roleId($role);
        if ($roleId === null) {
            // Ohne ausdrueckliche KLINQ-Berechtigung ausschliesslich das Serviceportal
            // (eigene Tickets); keine Rechte der Assetverwaltung.
            $role = self::FALLBACK_ROLE;
            $roleId = $this->users->roleId($role);
        }
        if ($roleId === null) {
            $this->logger->warning('LANPA-SSO: Rolle nicht vorhanden.', ['rolle' => $role]);

            return null;
        }

        $displayName = self::clean((string) ($claims['name'] ?? ''), 200) ?: $username;
        $email = self::clean((string) ($claims['email'] ?? ''), 255);
        $groupIds = $this->groupIds((array) ($claims['permission_groups'] ?? []));
        $employeeId = $this->employeeId($username, $email);

        $user = $this->users->findByUsername($username);
        if ($user === null) {
            $id = $this->users->create([
                'username' => $username,
                'display_name' => $displayName,
                'email' => $email === '' ? null : $email,
                'password_hash' => null,
                'role_id' => $roleId,
                'auth_source' => 'ldap',
                'employee_id' => $employeeId,
                'is_active' => 1,
            ]);
            $this->users->syncGroups($id, $groupIds);
            $user = $this->users->find($id);
            $this->logger->info('LANPA-SSO: Benutzer angelegt.', ['benutzer' => $username, 'rolle' => $role]);
        } else {
            $data = [
                'display_name' => $displayName,
                'email' => $email === '' ? null : $email,
                'role_id' => $roleId,
                'auth_source' => 'ldap',
                'is_active' => 1,
                'locked_until' => null,
                'failed_logins' => 0,
            ];
            // Eine bereits gepflegte Verknüpfung nie mit NULL überschreiben, nur weil der
            // Abgleich gerade keinen Mitarbeiter findet (z. B. AD-Abfrage nicht erreichbar).
            if ($employeeId !== null) {
                $data['employee_id'] = $employeeId;
            }
            $this->users->update((int) $user['id'], $data);
            $this->users->syncGroups((int) $user['id'], $groupIds);
            $user = $this->users->find((int) $user['id']);
        }

        if ($user !== null) {
            $this->users->recordLoginSuccess((int) $user['id']);
        }

        return $user;
    }

    /**
     * Lokalen Mitarbeiterdatensatz zum Konto ermitteln.
     *
     * Der Einstiegsschein des Intranets enthaelt keine Mitarbeiter-ID; der Abgleich laeuft
     * daher ueber den Anmeldenamen (AD-Konto) mit Rueckfall auf die E-Mail-Adresse. Ohne
     * diese Verknuepfung erkennt der Help Desk nicht, welche Tickets die Person als Melder
     * oder Betroffener betreffen (Portal-Sichtbarkeit ueber `users.employee_id`).
     */
    private function employeeId(string $username, string $email): ?int
    {
        $employee = $this->employees->findByUsername($username);
        if ($employee === null && $email !== '') {
            $employee = $this->employees->findByEmail($email);
        }

        return $employee !== null ? (int) $employee['id'] : null;
    }

    /**
     * Namen der Berechtigungsgruppen in Datenbank-IDs uebersetzen. Unbekannte Namen
     * werden uebergangen (das Intranet kennt ggf. mehr Gruppen als KLINQ).
     *
     * @param array<int,mixed> $names
     * @return array<int,int>
     */
    private function groupIds(array $names): array
    {
        $wanted = [];
        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name !== '') {
                $wanted[$name] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $ids = [];
        foreach ($this->users->permissionGroups() as $group) {
            if (isset($wanted[strtolower((string) $group['name'])])) {
                $ids[] = (int) $group['id'];
            }
        }

        return $ids;
    }

    /** "DOMAIN\\benutzer", "benutzer@domain.tld" und "benutzer" auf den Anmeldenamen reduzieren. */
    public static function normalizeUsername(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '\\')) {
            $value = substr($value, (int) strrpos($value, '\\') + 1);
        }
        if (str_contains($value, '@')) {
            $value = substr($value, 0, (int) strpos($value, '@'));
        }
        $value = trim($value);

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) === 1 ? strtolower($value) : null;
    }

    private static function clean(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $maxLength);
    }
}
