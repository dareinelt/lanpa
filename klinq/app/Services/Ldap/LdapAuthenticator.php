<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use App\Core\Config;
use App\Core\Logger;
use App\Repositories\EmployeeRepository;

/**
 * Optionale LDAP/AD-Anmeldung: Bind mit den Benutzerdaten, Verknüpfung mit
 * synchronisiertem Mitarbeiter über den Benutzernamen.
 */
final class LdapAuthenticator
{
    public function __construct(
        private readonly Config $config,
        private readonly LdapClientInterface $client,
        private readonly EmployeeRepository $employees,
        private readonly Logger $logger
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('ldap.enabled') && (bool) $this->config->get('ldap.auth.enabled');
    }

    /** @return array<string,mixed>|null */
    public function authenticate(string $username, string $password): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9._\-]{1,120}$/', $username)) {
            return null;
        }

        $bindDn = str_replace('{username}', $username, (string) $this->config->get('ldap.auth.bind_template'));
        try {
            if (!$this->client->bind($bindDn, $password)) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger->error('LDAP-Anmeldung fehlgeschlagen', ['username' => $username, 'error' => $e->getMessage()]);

            return null;
        }

        $employee = $this->employees->findByUsername($username);

        return [
            'display_name' => $employee['display_name'] ?? $username,
            'email' => $employee['email'] ?? null,
            'employee_id' => $employee !== null ? (int) $employee['id'] : null,
        ];
    }
}
