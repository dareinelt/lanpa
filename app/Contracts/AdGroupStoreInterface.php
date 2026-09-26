<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Persistenz der synchronisierten AD-Gruppen und ihrer Mitglieder.
 */
interface AdGroupStoreInterface
{
    /**
     * Ersetzt den Gruppenbestand einer Identitaetsquelle (0 = Hauptquelle);
     * die Gruppen anderer Quellen bleiben unveraendert. Nicht mehr gelieferte Gruppen werden
     * deaktiviert (und verlieren ihre Mitglieder), damit entzogene Rechte
     * sofort wirken.
     *
     * @param list<array{dn:string,name:string,description:?string,members:list<string>}> $groups
     *        Mitglieder als external_id des Telefonbuchs
     *
     * @return int Anzahl aktiver Gruppen
     */
    public function replaceAll(array $groups, string $syncedAt, int $sourceId = 0): int;

    /**
     * Namen (Kleinschreibung) der aktiven Gruppen eines Telefonbuch-Eintrags.
     *
     * @return list<string>
     */
    public function namesForUser(int $phonebookId): array;
}
