<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Ein aus dem Verzeichnisdienst gelesener Benutzerdatensatz (bereits gemappt).
 */
interface LdapClientInterface
{
    /**
     * Liefert die gemappten Benutzerdatensaetze.
     *
     * @return list<array<string,string|null>>
     *
     * @throws \RuntimeException wenn die Verbindung oder die Suche fehlschlaegt
     */
    public function fetchUsers(): array;

    /**
     * Reine Verbindungspruefung (Bind) ohne Suche.
     *
     * @throws \RuntimeException bei Fehlern
     */
    public function testConnection(): void;
}
