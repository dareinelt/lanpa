<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Persistenz der synchronisierten AD-Gruppen und ihrer Mitglieder.
 */
interface AdGroupStoreInterface
{
    /**
     * Ersetzt den Gruppenbestand. Nicht mehr gelieferte Gruppen werden
     * deaktiviert (und verlieren ihre Mitglieder), damit entzogene Rechte
     * sofort wirken.
     *
     * @param list<array{dn:string,name:string,description:?string,members:list<string>}> $groups
     *        Mitglieder als external_id des Telefonbuchs
     *
     * @return int Anzahl aktiver Gruppen
     */
    public function replaceAll(array $groups, string $syncedAt): int;

    /**
     * Namen (Kleinschreibung) der aktiven Gruppen eines Telefonbuch-Eintrags.
     *
     * @return list<string>
     */
    public function namesForUser(int $phonebookId): array;
}
