<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV-Ausgabe für Exporte: UTF-8 mit BOM, Semikolon als Trenner (Excel im deutschen Sprachraum), CRLF.
 * Zellen, die als Formel interpretiert werden könnten, werden entschärft.
 */
final class CsvWriter
{
    public const MIME = 'text/csv; charset=UTF-8';

    /**
     * @param list<string> $headers
     * @param iterable<array<int|string,mixed>> $rows Zeilen in Spaltenreihenfolge der Header
     */
    public static function build(array $headers, iterable $rows, string $delimiter = ';'): string
    {
        $out = "\xEF\xBB\xBF" . self::line($headers, $delimiter);
        foreach ($rows as $row) {
            $out .= self::line(array_values($row), $delimiter);
        }

        return $out;
    }

    /** @param list<mixed> $cells */
    private static function line(array $cells, string $delimiter): string
    {
        return implode($delimiter, array_map(static fn (mixed $c): string => self::cell($c, $delimiter), $cells)) . "\r\n";
    }

    public static function cell(mixed $value, string $delimiter = ';'): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return number_format($value, 2, ',', '');
        }
        $text = (string) $value;
        if ($text === '') {
            return '';
        }
        // Formel-Injektion verhindern (=, +, -, @, Tab, CR); Zahlen wie -5 bleiben unverändert
        if (preg_match('/^[=+\-@\t\r]/', $text) === 1 && !is_numeric($text)) {
            $text = "'" . $text;
        }
        if (str_contains($text, $delimiter) || str_contains($text, '"') || str_contains($text, "\n") || str_contains($text, "\r") || str_starts_with($text, "'")) {
            $text = '"' . str_replace('"', '""', $text) . '"';
        }

        return $text;
    }

    /** Dateiname mit Datum, ohne problematische Zeichen. */
    public static function filename(string $base): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $base) ?? '', '-'));

        return ($slug !== '' ? $slug : 'export') . '-' . date('Y-m-d') . '.csv';
    }
}
