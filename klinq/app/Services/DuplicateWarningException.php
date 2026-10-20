<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Signalisiert mögliche Dubletten; der Benutzer entscheidet über die Anlage
 * (Formular erneut anzeigen mit Hinweis + Bestätigungsoption).
 */
final class DuplicateWarningException extends \RuntimeException
{
    /** @param array<int,array<string,mixed>> $duplicates */
    public function __construct(private readonly array $duplicates)
    {
        parent::__construct('Mögliche Dubletten gefunden');
    }

    /** @return array<int,array<string,mixed>> */
    public function duplicates(): array
    {
        return $this->duplicates;
    }
}
