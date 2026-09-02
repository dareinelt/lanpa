<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string,string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Validierung fehlgeschlagen.');
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
