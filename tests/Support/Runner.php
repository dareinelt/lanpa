<?php

declare(strict_types=1);

namespace Tests\Support;

use Throwable;

/**
 * Minimalistischer Testrunner ohne externe Abhaengigkeiten.
 */
final class Runner
{
    /** @var list<array{name:string,fn:callable}> */
    private static array $tests = [];

    private static int $assertions = 0;

    public static function test(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function countAssertion(): void
    {
        self::$assertions++;
    }

    public static function run(): int
    {
        $passed = 0;
        /** @var list<string> $failures */
        $failures = [];

        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                $passed++;
                fwrite(STDOUT, '.');
            } catch (Throwable $exception) {
                $failures[] = $test['name'] . ': ' . $exception->getMessage();
                fwrite(STDOUT, 'F');
            }
        }

        fwrite(STDOUT, PHP_EOL . PHP_EOL);

        foreach ($failures as $failure) {
            fwrite(STDOUT, 'FEHLGESCHLAGEN: ' . $failure . PHP_EOL);
        }

        fwrite(STDOUT, sprintf(
            '%d Tests, %d erfolgreich, %d fehlgeschlagen, %d Zusicherungen.%s',
            count(self::$tests),
            $passed,
            count($failures),
            self::$assertions,
            PHP_EOL
        ));

        return $failures === [] ? 0 : 1;
    }
}
