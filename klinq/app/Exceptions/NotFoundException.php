<?php

declare(strict_types=1);

namespace App\Exceptions;

final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Nicht gefunden')
    {
        parent::__construct($message, 404);
    }
}
