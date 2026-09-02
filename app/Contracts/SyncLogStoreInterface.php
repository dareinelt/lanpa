<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Protokolliert Synchronisationslaeufe. Als Schnittstelle definiert, damit der
 * Synchronisationsdienst ohne Datenbank getestet werden kann.
 */
interface SyncLogStoreInterface
{
    public function start(): int;

    public function finish(int $id, string $status, int $processed, int $deactivated, ?string $message = null): void;
}
