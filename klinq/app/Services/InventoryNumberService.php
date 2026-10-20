<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\BaseRepository;
use PDO;

/**
 * Vergibt Inventarnummern im Format PRÄFIX + YY + NNN (z. B. PC24001) ohne Trennzeichen.
 *
 * Transaktionssicherheit: Die Zeile in inventory_sequences wird per SELECT … FOR UPDATE
 * gesperrt, bis die umgebende Transaktion (inkl. INSERT des Assets) abgeschlossen ist.
 * Zwei gleichzeitige Anleger erhalten so garantiert verschiedene Nummern; die UNIQUE-
 * Beschränkung auf assets.inventory_number ist die letzte Sicherung.
 */
final class InventoryNumberService extends BaseRepository
{
    /** Jahrescode für nachinventarisierte Altbestände (PC88001 …). */
    public const LEGACY_YEAR_CODE = '88';

    public function __construct(PDO $pdo, private readonly ?\DateTimeImmutable $now = null)
    {
        parent::__construct($pdo);
    }

    /**
     * Reserviert die nächste Nummer für Präfix und Jahr. Muss innerhalb einer Transaktion
     * aufgerufen werden, damit die Sperre bis zum Commit hält (sonst wird eine eigene geöffnet).
     */
    public function next(string $prefix, bool $legacy = false): string
    {
        $prefix = self::normalizePrefix($prefix);
        $yearCode = $legacy ? self::LEGACY_YEAR_CODE : ($this->now ?? new \DateTimeImmutable())->format('y');

        return $this->transaction(function () use ($prefix, $yearCode): string {
            $this->execute(
                'INSERT IGNORE INTO inventory_sequences (prefix, year_code, last_number) VALUES (:p, :y, 0)',
                ['p' => $prefix, 'y' => $yearCode]
            );
            $current = (int) $this->fetchValue(
                'SELECT last_number FROM inventory_sequences WHERE prefix = :p AND year_code = :y FOR UPDATE',
                ['p' => $prefix, 'y' => $yearCode]
            );
            $next = $current + 1;
            // Falls Nummern importiert/manuell vergeben wurden: nie hinter den höchsten Bestand zurückfallen
            $highest = $this->highestExisting($prefix, $yearCode);
            if ($highest >= $next) {
                $next = $highest + 1;
            }
            $this->execute(
                'UPDATE inventory_sequences SET last_number = :n WHERE prefix = :p AND year_code = :y',
                ['n' => $next, 'p' => $prefix, 'y' => $yearCode]
            );

            return self::format($prefix, $yearCode, $next);
        });
    }

    /**
     * Liefert die zuletzt vergebene Nummer für Präfix (aktuelles Jahr), ohne die Sequenz zu verändern.
     * Null, wenn für dieses Präfix/Jahr noch keine Nummer vergeben wurde.
     */
    public function last(string $prefix, bool $legacy = false): ?string
    {
        $prefix = self::normalizePrefix($prefix);
        $yearCode = $legacy ? self::LEGACY_YEAR_CODE : ($this->now ?? new \DateTimeImmutable())->format('y');

        $current = (int) $this->fetchValue(
            'SELECT last_number FROM inventory_sequences WHERE prefix = :p AND year_code = :y',
            ['p' => $prefix, 'y' => $yearCode]
        );
        $highest = $this->highestExisting($prefix, $yearCode);
        $number = max($current, $highest);

        return $number > 0 ? self::format($prefix, $yearCode, $number) : null;
    }

    /**
     * Liefert die nächste freie Nummer für Präfix (aktuelles Jahr), ohne die Sequenz zu reservieren
     * (Vorschau für z. B. „Übernehmen?“-Dialoge). Wurde noch keine Nummer vergeben, ist das die erste (…001).
     */
    public function peekNext(string $prefix, bool $legacy = false): string
    {
        $prefix = self::normalizePrefix($prefix);
        $yearCode = $legacy ? self::LEGACY_YEAR_CODE : ($this->now ?? new \DateTimeImmutable())->format('y');

        $current = (int) $this->fetchValue(
            'SELECT last_number FROM inventory_sequences WHERE prefix = :p AND year_code = :y',
            ['p' => $prefix, 'y' => $yearCode]
        );
        $highest = $this->highestExisting($prefix, $yearCode);
        $number = max($current, $highest);

        return self::format($prefix, $yearCode, $number + 1);
    }

    /** Prüft eine manuell eingegebene Nummer (Import/Altbestand) gegen das Format. */
    public static function isValid(string $number): bool
    {
        return preg_match('/^[A-Z]{1,6}\d{2}\d{3,}$/', $number) === 1;
    }

    public static function normalizePrefix(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $prefix) ?? '');
        if ($prefix === '' || strlen($prefix) > 6) {
            throw new \InvalidArgumentException('Ungültiges Inventarnummern-Präfix.');
        }

        return $prefix;
    }

    public static function format(string $prefix, string $yearCode, int $number): string
    {
        // Dreistellig; ab 1000 wird die Nummer länger, bleibt aber eindeutig (kein Überlauf auf 000)
        return $prefix . $yearCode . sprintf('%03d', $number);
    }

    /** Höchste laufende Nummer, die für Präfix+Jahr bereits in assets existiert. */
    private function highestExisting(string $prefix, string $yearCode): int
    {
        $start = strlen($prefix) + 3; // Präfix (validiert, nur Buchstaben) + 2-stelliges Jahr
        $value = $this->fetchValue(
            "SELECT MAX(CAST(SUBSTRING(inventory_number, {$start}) AS UNSIGNED)) FROM assets
             WHERE inventory_number LIKE :like AND inventory_number REGEXP :re",
            [
                'like' => $prefix . $yearCode . '%',
                're' => '^' . $prefix . $yearCode . '[0-9]+$',
            ]
        );

        return (int) $value;
    }
}
