<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Liest CSV-Dateien für den Import: erkennt BOM, Zeichensatz (UTF-8 / Windows-1252) und Trennzeichen (; , Tab | ),
 * liefert Kopfzeile und Datenzeilen als assoziative Arrays (Schlüssel = normalisierter Spaltenname).
 */
final class CsvReader
{
    public const DELIMITERS = [';', ',', "\t", '|'];

    /**
     * @return array{delimiter:string, encoding:string, headers:list<string>, rows:list<array{line:int, cells:array<string,string>}>, skipped:int}
     */
    public static function parse(string $content, ?string $delimiter = null, int $maxRows = 10000): array
    {
        $encoding = 'UTF-8';
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
            $encoding = 'UTF-8 (BOM)';
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
            $encoding = 'Windows-1252';
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $delimiter = $delimiter !== null && in_array($delimiter, self::DELIMITERS, true) ? $delimiter : self::detectDelimiter($content);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('CSV konnte nicht gelesen werden.');
        }
        fwrite($handle, $content);
        rewind($handle);

        $headers = [];
        $rows = [];
        $skipped = 0;
        $line = 0;
        while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if ($record === [null] || self::isBlank($record)) {
                continue;
            }
            if ($headers === []) {
                $headers = array_map(static fn (mixed $h): string => trim((string) $h), $record);
                continue;
            }
            if (count($rows) >= $maxRows) {
                $skipped++;
                continue;
            }
            $cells = [];
            foreach ($headers as $i => $header) {
                if ($header === '') {
                    continue;
                }
                $cells[self::normalizeHeader($header)] = trim((string) ($record[$i] ?? ''));
            }
            $rows[] = ['line' => $line, 'cells' => $cells];
        }
        fclose($handle);

        return [
            'delimiter' => $delimiter,
            'encoding' => $encoding,
            'headers' => array_values(array_filter($headers, static fn (string $h): bool => $h !== '')),
            'rows' => $rows,
            'skipped' => $skipped,
        ];
    }

    /** Spaltenname vergleichbar machen: klein, ohne Umlaute/Sonderzeichen. */
    public static function normalizeHeader(string $header): string
    {
        $h = mb_strtolower(trim($header));
        $h = strtr($h, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e']);
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? '';

        return trim($h, '_');
    }

    public static function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n");
        if ($firstLine === false) {
            return ';';
        }
        $best = ';';
        $bestCount = -1;
        foreach (self::DELIMITERS as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @param array<int,mixed> $record */
    private static function isBlank(array $record): bool
    {
        foreach ($record as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    public static function delimiterLabel(string $delimiter): string
    {
        return match ($delimiter) {
            "\t" => 'Tabulator',
            ',' => 'Komma',
            '|' => 'Senkrechter Strich',
            default => 'Semikolon',
        };
    }
}
