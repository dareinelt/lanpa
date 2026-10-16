<?php

declare(strict_types=1);

use Tests\Support\Assert;
use Tests\Support\Runner;

/**
 * Wachhund gegen eine Fehlerklasse, die der SQLite-Testaufbau nicht aufdeckt:
 * Die Anwendung verbindet sich mit PDO::ATTR_EMULATE_PREPARES = false
 * (app/Core/Database.php). MySQL ersetzt benannte Platzhalter dann in
 * Fragezeichen und weist einen mehrfach verwendeten Namen mit
 * "SQLSTATE[HY093]: Invalid parameter number" ab. SQLite bindet dagegen nach
 * Namen und erlaubt denselben Platzhalter beliebig oft - ein solcher Fehler
 * faellt in den Unit-Tests also nicht auf, sondern erst im Betrieb.
 *
 * Geprueft werden alle SQL-Zeichenketten unter app/ (Vorbereitung mit
 * prepare()). Der Scan ist bewusst einfach: Er betrachtet einfache und doppelte
 * Zeichenketten, die mit SELECT, INSERT, UPDATE oder DELETE beginnen.
 */

/**
 * Sammelt SQL-Zeichenketten unterhalb eines Verzeichnisses.
 *
 * @return list<array{0:string,1:int,2:string}> Datei, Zeile, Anweisung
 */
function sqlPlaceholderStatements(string $root): array
{
    $patterns = [
        "~'((?:[^'\\\\]|\\\\.)*)'~s",
        '~"((?:[^"\\\\]|\\\\.)*)"~s',
    ];
    $statements = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php' || !$file->isFile()) {
            continue;
        }
        $text = (string) file_get_contents($file->getPathname());
        foreach ($patterns as $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[1] as $match) {
                $literal = $match[0];
                if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $literal) !== 1) {
                    continue;
                }
                $statements[] = [$file->getPathname(), substr_count($text, "\n", 0, $match[1]) + 1, $literal];
            }
        }
    }

    return $statements;
}

Runner::test('SQL: benannte Platzhalter stehen je Anweisung nur einmal', static function (): void {
    $root = dirname(__DIR__, 2) . '/app';
    $statements = sqlPlaceholderStatements($root);
    // Sicherung, dass der Scan ueberhaupt greift (Muster oder Pfad geaendert).
    Assert::true(count($statements) > 100, 'Der Scan hat kaum SQL-Anweisungen gefunden.');

    $offenders = [];
    $seen = [];
    foreach ($statements as [$file, $line, $sql]) {
        $relative = str_replace('\\', '/', substr($file, strlen(dirname($root)) + 1));
        $seen[$relative] = true;
        $matches = [];
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        foreach (array_count_values($matches[1]) as $name => $count) {
            if ($count > 1) {
                $offenders[] = sprintf('%s:%d (:%s %d×)', $relative, $line, $name, $count);
            }
        }
    }
    // Der lokale Kalender war der Ausloeser dieser Pruefung und muss dabei sein.
    Assert::true(isset($seen['app/Repositories/OrvantaCalendarRepository.php']), 'Das Kalender-Repository wurde nicht geprueft.');
    Assert::same([], $offenders, 'Mehrfach verwendete Platzhalter: ' . implode(', ', $offenders));
});
