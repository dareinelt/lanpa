<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use RuntimeException;

/**
 * Fehler bei der Kommunikation mit Exchange oder bei Orvanta-Aktionen.
 * Die Meldung ist fuer die Anzeige im Frontend geeignet. Der optionale
 * Grund (reason) ist ein maschinenlesbarer Code fuer das Frontend, z. B.
 * MAIL_AUTH, wenn der Mailserver das hinterlegte Kennwort ablehnt.
 */
final class OrvantaException extends RuntimeException
{
    /** Mailserver lehnt die hinterlegten Zugangsdaten ab (Kennwort-Abfrage im Frontend). */
    public const MAIL_AUTH = 'mail_auth';

    public function __construct(string $message, private readonly int $status = 502, ?\Throwable $previous = null, private readonly string $reason = '')
    {
        parent::__construct($message, 0, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
