<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Lesender Zugriff auf die weiteren Identitaetsquellen (Active Directorys von
 * Zweigstellen, Tochtergesellschaften, ...). Die Hauptquelle (ID 0) steht
 * nicht in diesem Speicher, sondern in den Einstellungen.
 */
interface IdentitySourceStoreInterface
{
    /**
     * Aktive Quelle anhand ihrer Kennung (case-insensitiv) oder null.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveByKey(string $key): ?array;
}
