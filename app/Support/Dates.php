<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Dates
{
    public static function formatDate(?string $value, string $format = 'd.m.Y'): string
    {
        if ($value === null || trim($value) === '') {
            return '–';
        }

        try {
            return (new DateTimeImmutable($value))->format($format);
        } catch (\Exception) {
            return '–';
        }
    }

    public static function formatDateTime(?string $value): string
    {
        return self::formatDate($value, 'd.m.Y H:i');
    }

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
    }
}
