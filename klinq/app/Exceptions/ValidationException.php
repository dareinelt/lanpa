<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors, string $message = 'Eingaben ungültig')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => $message], $message);
    }
}
