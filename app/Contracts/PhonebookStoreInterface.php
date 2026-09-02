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
     *
     * @param array<string,string|null> $user
     */
    public function upsert(array $user, string $syncedAt): void;

    /**
     * Deaktiviert alle Eintraege, die im aktuellen Lauf nicht geliefert wurden.
     */
    public function deactivateStale(string $syncedAt): int;

    public function countActive(): int;
}
