<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Anzeige von Speichergroessen (Byte) in der Doku-Sprache des Projekts.
 */
final class Bytes
{
    /** Einheitenschritte in Byte (Zweierpotenzen wie in der Speicheranzeige ueblich). */
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /**
     * Formatiert Byte als lesbare Groesse, z. B. 1610612736 => "1,5 GB".
     */
    public static function format(?int $bytes): string
    {
        if ($bytes === null) {
            return '–';
        }

        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024.0 && $unit < count(self::UNITS) - 1) {
            $value /= 1024.0;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1, ',', '.') . ' ' . self::UNITS[$unit];
    }

    /**
     * Formatiert "belegt / gesamt", z. B. "1,5 GB / 2,0 GB".
     */
    public static function formatPair(?int $used, ?int $total): string
    {
        if ($used === null || $total === null) {
            return '–';
        }

        return self::format($used) . ' / ' . self::format($total);
    }
}
