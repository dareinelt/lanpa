<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Config;

final class Permissions
{
    public function __construct(private readonly Config $config) {}

    /** @return array<int,string> */
    public function forRole(string $role): array
    {
        $roles = $this->config->get('permissions.roles', []);

        return $roles[$role] ?? [];
    }

    /** Alle bekannten Rechte. @return array<int,string> */
    public function all(): array
    {
        return $this->config->get('permissions.all', []);
    }

    public function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, $this->forRole($role), true);
    }

    /** @return array<string,string> */
    public function roleLabels(): array
    {
        return $this->config->get('permissions.role_labels', []);
    }

    /** @return array<int,string> */
    public function roleNames(): array
    {
        return array_keys($this->config->get('permissions.roles', []));
    }

    public function roleExists(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }

    /**
     * Berechtigungsgruppen: zusätzlich zur Rolle zuweisbare Rechte. Ein Benutzer kann
     * mehreren Gruppen gleichzeitig angehören (z. B. „Statistik“ für Ticket-Berichte).
     * @return array<int,string>
     */
    public function forGroup(string $group): array
    {
        $groups = $this->config->get('permissions.groups', []);

        return $groups[$group] ?? [];
    }

    public function groupHas(string $group, string $permission): bool
    {
        return in_array($permission, $this->forGroup($group), true);
    }

    /** @return array<string,string> */
    public function groupLabels(): array
    {
        return $this->config->get('permissions.group_labels', []);
    }

    /** @return array<int,string> */
    public function groupNames(): array
    {
        return array_keys($this->config->get('permissions.groups', []));
    }

    public function groupExists(string $group): bool
    {
        return in_array($group, $this->groupNames(), true);
    }

    /**
     * @param array<int,string> $groups
     */
    public function hasEffective(string $role, array $groups, string $permission): bool
    {
        if ($this->roleHas($role, $permission)) {
            return true;
        }
        foreach ($groups as $group) {
            if ($this->groupHas($group, $permission)) {
                return true;
            }
        }

        return false;
    }
}
