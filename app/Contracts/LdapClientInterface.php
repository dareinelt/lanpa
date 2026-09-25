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
     * Liefert die Gruppen unterhalb der konfigurierten Gruppen-Pfade samt
     * (auch verschachtelter) Mitglieder als external_id der Benutzer.
     * Ohne konfigurierten Gruppen-Pfad wird eine leere Liste geliefert.
     *
     * @return list<array{dn:string,name:string,description:?string,members:list<string>}>
     *
     * @throws \RuntimeException wenn die Verbindung oder die Suche fehlschlaegt
     */
    public function fetchGroups(): array;

    /**
     * Ob ein Gruppen-Pfad konfiguriert ist.
     */
    public function hasGroupConfig(): bool;

    /**
     * Reine Verbindungspruefung (Bind) ohne Suche.
     *
     * @throws \RuntimeException bei Fehlern
     */
    public function testConnection(): void;
}
