#!/usr/bin/env php
<?php
/**
 * Minimaler Testrunner (keine externen Abhängigkeiten).
 * Aufruf: php tests/run.php [--filter=Name] [--unit|--integration]
 * Integrationstests benötigen eine erreichbare Test-Datenbank (DB_TEST_DATABASE).
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap/autoload.php';

use Tests\Support\AssertionFailed;
use Tests\Support\SkippedTest;
use Tests\Support\TestCase;

$options = getopt('', ['filter::', 'unit', 'integration']);
$filter = $options['filter'] ?? null;
$dirs = [];
if (isset($options['unit']) || !isset($options['integration'])) { $dirs[] = __DIR__ . '/Unit'; }
if (isset($options['integration']) || !isset($options['unit'])) { $dirs[] = __DIR__ . '/Integration'; }

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];

$files = [];
foreach ($dirs as $dir) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$passed = $failed = $skipped = 0;
$skips = [];
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
    $relative = str_replace(__DIR__ . '/', '', $file);
    $class = 'Tests\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
    require_once $file;
    if (!class_exists($class) || !is_subclass_of($class, TestCase::class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $name = $reflection->getShortName() . '::' . $method->getName();
        if ($filter !== null && stripos($name, $filter) === false) {
            continue;
        }
        $_SESSION = [];
        try {
            $instance = $reflection->newInstance();
            $instance->runTest($method->getName());
            $passed++;
            echo "\033[32m.\033[0m";
        } catch (SkippedTest $e) {
            $skipped++;
            $skips[$e->getMessage()] = true;
            echo "\033[33mS\033[0m";
        } catch (AssertionFailed $e) {
            $failed++;
            $failures[] = [$name, $e->getMessage(), $e->getTraceAsString()];
            echo "\033[31mF\033[0m";
        } catch (Throwable $e) {
            $failed++;
            $failures[] = [$name, $e::class . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', $e->getTraceAsString()];
            echo "\033[31mE\033[0m";
        }
    }
}

$duration = number_format(microtime(true) - $start, 2);
echo "\n\n";
foreach ($failures as $i => [$name, $message, $trace]) {
    echo "\033[31m" . ($i + 1) . ") {$name}\033[0m\n   {$message}\n";
    if (getenv('TEST_VERBOSE')) {
        echo "   " . str_replace("\n", "\n   ", $trace) . "\n";
    }
    echo "\n";
}
if ($skips !== []) {
    echo "Übersprungen: " . implode(" | ", array_keys($skips)) . "\n";
}
echo "Tests: " . ($passed + $failed + $skipped) . ", Bestanden: {$passed}, Fehlgeschlagen: {$failed}, Übersprungen: {$skipped} ({$duration}s)\n";
exit($failed > 0 ? 1 : 0);
