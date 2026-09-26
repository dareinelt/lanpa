<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Persistenz des Telefonbuchs. Die Schnittstelle erlaubt Tests der
 * Synchronisationslogik ohne echte Datenbank.
 */
interface PhonebookStoreInterface
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Legt einen Datensatz an oder aktualisiert ihn (Schluessel: external_id).
     * `identity_source_id` im Datensatz ordnet ihn einer Identitaetsquelle zu.
     *
     * @param array<string,string|null> $user
     */
    public function upsert(array $user, string $syncedAt): void;

    /**
     * Deaktiviert alle Eintraege der Identitaetsquelle (0 = Hauptquelle), die
     * im aktuellen Lauf nicht geliefert wurden.
     */
    public function deactivateStale(string $syncedAt, int $sourceId = 0): int;

    public function countActive(): int;
}
