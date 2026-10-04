<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use RuntimeException;

/**
 * Fehler bei der Kommunikation mit Exchange oder bei Orvanta-Aktionen.
 * Die Meldung ist fuer die Anzeige im Frontend geeignet.
 */
final class OrvantaException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 502, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }
}
