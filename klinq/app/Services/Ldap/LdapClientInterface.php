<?php

declare(strict_types=1);

namespace App\Services\Ldap;

interface LdapClientInterface
{
    /** Verbindet und bindet mit den angegebenen Zugangsdaten. */
    public function bind(string $dn, string $password): bool;

    /**
     * Sucht Einträge unterhalb der Base-DN.
     *
     * @param array<int,string> $attributes
     * @return array<int,array<string,mixed>> Liste von Einträgen (Attribut → Wert(e))
     */
    public function search(string $baseDn, string $filter, array $attributes): array;

    public function close(): void;
}
