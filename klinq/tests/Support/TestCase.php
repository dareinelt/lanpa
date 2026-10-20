<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Minimaler Test-Basisklasse ohne externe Abhängigkeiten. */
abstract class TestCase
{
    protected function setUp(): void {}
    protected function tearDown(): void {}

    /** @internal wird vom Runner aufgerufen */
    public function runTest(string $method): void
    {
        $this->setUp();
        try {
            $this->{$method}();
        } finally {
            $this->tearDown();
        }
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value !== true) {
            throw new AssertionFailed($message !== '' ? $message : 'Erwartet: true, erhalten: ' . var_export($value, true));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value !== false) {
            throw new AssertionFailed($message !== '' ? $message : 'Erwartet: false, erhalten: ' . var_export($value, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(($message !== '' ? $message . ' – ' : '') . 'Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected != $actual) {
            throw new AssertionFailed(($message !== '' ? $message . ' – ' : '') . 'Erwartet: ' . var_export($expected, true) . ', erhalten: ' . var_export($actual, true));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        if ($actual === null) {
            throw new AssertionFailed($message !== '' ? $message : 'Wert darf nicht null sein');
        }
    }

    protected function assertCount(int $expected, array|\Countable $actual, string $message = ''): void
    {
        $this->assertSame($expected, count($actual), $message !== '' ? $message : 'Anzahl');
    }

    protected function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionFailed($message !== '' ? $message : var_export($needle, true) . ' nicht enthalten');
        }
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed($message !== '' ? $message : "'{$needle}' nicht enthalten in: " . mb_substr($haystack, 0, 200));
        }
    }

    protected function assertMatches(string $pattern, string $value, string $message = ''): void
    {
        if (!preg_match($pattern, $value)) {
            throw new AssertionFailed($message !== '' ? $message : "'{$value}' entspricht nicht {$pattern}");
        }
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $callback, ?string $messageContains = null): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (!($e instanceof $class)) {
                throw new AssertionFailed("Erwartete Ausnahme {$class}, erhalten " . $e::class . ': ' . $e->getMessage());
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed("Ausnahmetext '{$e->getMessage()}' enthält nicht '{$messageContains}'");
            }

            return $e;
        }
        throw new AssertionFailed("Erwartete Ausnahme {$class} wurde nicht ausgelöst");
    }

    protected function markSkipped(string $reason): never
    {
        throw new SkippedTest($reason);
    }
}
