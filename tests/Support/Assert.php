<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class Assert
{
    public static function true(bool $condition, string $message = 'Bedingung ist nicht erfüllt.'): void
    {
        Runner::countAssertion();
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    public static function false(bool $condition, string $message = 'Bedingung sollte falsch sein.'): void
    {
        self::true(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        Runner::countAssertion();
        if ($expected !== $actual) {
            throw new RuntimeException(
                ($message !== '' ? $message . ' – ' : '')
                . 'erwartet: ' . self::describe($expected) . ', erhalten: ' . self::describe($actual)
            );
        }
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        Runner::countAssertion();
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(
                ($message !== '' ? $message . ' – ' : '') . '"' . $needle . '" nicht enthalten in: ' . $haystack
            );
        }
    }

    public static function null(mixed $value, string $message = 'Wert sollte null sein.'): void
    {
        self::true($value === null, $message);
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'komplexer Wert';
    }
}
