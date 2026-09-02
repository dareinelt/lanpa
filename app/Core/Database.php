<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Zentraler, einziger Ort fuer den Aufbau der Datenbankverbindung.
 */
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        /** @var array<string,mixed> $config */
        $config = Config::get('database', []);

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($config['driver'] ?? 'mysql'),
            (string) ($config['host'] ?? 'localhost'),
            (int) ($config['port'] ?? 3306),
            (string) ($config['database'] ?? ''),
            (string) ($config['charset'] ?? 'utf8mb4')
        );

        try {
            self::$connection = new PDO(
                $dsn,
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]
            );
        } catch (PDOException $exception) {
            // Details bleiben im Log, niemals in der HTTP-Ausgabe.
            throw new RuntimeException('Datenbankverbindung fehlgeschlagen.', 0, $exception);
        }

        return self::$connection;
    }

    public static function set(?PDO $pdo): void
    {
        self::$connection = $pdo;
    }
}
