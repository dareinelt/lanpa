<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ConflictException extends HttpException
{
    /** @param array<string,mixed> $details */
    public function __construct(string $message, private readonly array $details = [])
    {
        parent::__construct($message, 409);
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
