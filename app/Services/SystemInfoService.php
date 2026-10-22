<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Sammelt die Systeminformationen fuer den Adminbereich „System → Information“:
 * PHP-Laufzeit, Datenbank, Migrationsstand, Laufzeit des Servers und Speicher.
 */
final class SystemInfoService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $basePath
    ) {
    }

    /**
     * @return array{
     *     php: array<string,mixed>,
     *     database: array<string,mixed>,
     *     migrations: array<string,mixed>,
     *     system: array<string,mixed>,
     *     disk: array<string,mixed>
     * }
     */
    public function report(): array
    {
        return [
            'php' => self::phpInfo(),
            'database' => $this->databaseInfo(),
            'migrations' => $this->migrationInfo(),
            'system' => $this->systemInfo(),
            'disk' => self::diskInfo($this->basePath),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function phpInfo(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'architecture' => php_uname('m'),
            'operating_system' => trim(php_uname('s') . ' ' . php_uname('r')),
            'timezone' => date_default_timezone_get(),
            'memory_limit' => self::memoryLimitLabel((string) ini_get('memory_limit')),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'extensions' => count(get_loaded_extensions()),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'opcache' => function_exists('opcache_get_status'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function databaseInfo(): array
    {
        $info = [
            'connected' => false,
            'version' => null,
            'name' => null,
            'charset' => null,
            'size_bytes' => null,
            'tables' => null,
            'uptime_seconds' => null,
        ];

        try {
            $statement = $this->pdo->query('SELECT VERSION() AS version, DATABASE() AS name, @@character_set_database AS charset');
            $row = $statement?->fetch();
            if (is_array($row)) {
                $info['connected'] = true;
                $info['version'] = self::stringOrNull($row['version'] ?? null);
                $info['name'] = self::stringOrNull($row['name'] ?? null);
                $info['charset'] = self::stringOrNull($row['charset'] ?? null);
            }
        } catch (Throwable $exception) {
            app_logger()->warning('Systeminformationen: Datenbankversion nicht lesbar.', ['error' => $exception->getMessage()]);

            return $info;
        }

        try {
            $statement = $this->pdo->query(
                'SELECT COUNT(*) AS tables_count, COALESCE(SUM(data_length + index_length), 0) AS size_bytes'
                . ' FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
            $row = $statement?->fetch();
            if (is_array($row)) {
                $info['tables'] = (int) ($row['tables_count'] ?? 0);
                $info['size_bytes'] = (int) ($row['size_bytes'] ?? 0);
            }
        } catch (Throwable $exception) {
            app_logger()->warning('Systeminformationen: Datenbankgröße nicht lesbar.', ['error' => $exception->getMessage()]);
        }

        try {
            $statement = $this->pdo->query("SHOW GLOBAL STATUS LIKE 'Uptime'");
            $row = $statement?->fetch();
            if (is_array($row) && isset($row['Value']) && is_numeric($row['Value'])) {
                $info['uptime_seconds'] = (float) $row['Value'];
            }
        } catch (Throwable $exception) {
            app_logger()->warning('Systeminformationen: Datenbanklaufzeit nicht lesbar.', ['error' => $exception->getMessage()]);
        }

        return $info;
    }

    /**
     * @return array<string,mixed>
     */
    public function migrationInfo(): array
    {
        $files = glob($this->basePath . '/database/migrations/*.sql') ?: [];
        $available = array_map('basename', $files);
        sort($available);

        $applied = [];
        $last = null;
        $error = null;

        try {
            $statement = $this->pdo->query('SELECT migration, applied_at FROM schema_migrations ORDER BY id');
            $rows = $statement?->fetchAll() ?: [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $applied[] = (string) ($row['migration'] ?? '');
                $last = [
                    'migration' => (string) ($row['migration'] ?? ''),
                    'applied_at' => isset($row['applied_at']) ? (string) $row['applied_at'] : null,
                ];
            }
        } catch (Throwable $exception) {
            $error = 'Die Tabelle schema_migrations ist nicht lesbar. Bitte `php scripts/migrate.php` ausführen.';
            app_logger()->warning('Systeminformationen: Migrationsstand nicht lesbar.', ['error' => $exception->getMessage()]);
        }

        $state = self::migrationState($applied, $available);

        return [
            'available' => count($available),
            'applied' => $state['applied'],
            'pending' => $state['pending'],
            'complete' => $error === null && $state['complete'],
            'last' => $last,
            'error' => $error,
        ];
    }

    /**
     * @param list<string> $applied  Bereits angewendete Migrationen aus `schema_migrations`.
     * @param list<string> $available Vorhandene Migrationsdateien.
     *
     * @return array{applied:int,pending:list<string>,complete:bool}
     */
    public static function migrationState(array $applied, array $available): array
    {
        $appliedNames = [];
        foreach ($applied as $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $appliedNames[$name] = true;
            }
        }

        $pending = [];
        foreach ($available as $name) {
            $name = (string) $name;
            if (!isset($appliedNames[$name])) {
                $pending[] = $name;
            }
        }
        sort($pending);

        return [
            'applied' => count($appliedNames),
            'pending' => $pending,
            'complete' => $pending === [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function systemInfo(): array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return [
            'hostname' => (string) (gethostname() ?: '–'),
            'uptime_seconds' => $this->uptimeSeconds(),
            'load' => is_array($load) ? array_map(static fn ($value): float => (float) $value, $load) : null,
            'time' => date('d.m.Y H:i:s'),
        ];
    }

    public function uptimeSeconds(): ?float
    {
        $path = '/proc/uptime';
        if (!is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return is_string($content) ? self::parseUptime($content) : null;
    }

    /**
     * Liest die Laufzeit in Sekunden aus dem Inhalt von /proc/uptime.
     */
    public static function parseUptime(string $content): ?float
    {
        $parts = preg_split('/\s+/', trim($content)) ?: [];
        $value = $parts[0] ?? '';

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Formatiert Sekunden als lesbare Laufzeit, z. B. "3 Tage, 4 Stunden, 12 Minuten".
     */
    public static function formatUptime(?float $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        $total = (int) max(0, floor($seconds));
        $days = intdiv($total, 86400);
        $hours = intdiv($total % 86400, 3600);
        $minutes = intdiv($total % 3600, 60);

        if ($days > 0) {
            return self::plural($days, 'Tag', 'Tage') . ', '
                . self::plural($hours, 'Stunde', 'Stunden') . ', '
                . self::plural($minutes, 'Minute', 'Minuten');
        }

        if ($hours > 0) {
            return self::plural($hours, 'Stunde', 'Stunden') . ', '
                . self::plural($minutes, 'Minute', 'Minuten');
        }

        if ($minutes > 0) {
            return self::plural($minutes, 'Minute', 'Minuten');
        }

        return 'weniger als eine Minute';
    }

    /**
     * @return array{total:?int,free:?int,used:?int}
     */
    public static function diskInfo(string $path): array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return [
            'total' => is_float($total) ? (int) $total : null,
            'free' => is_float($free) ? (int) $free : null,
            'used' => is_float($total) && is_float($free) ? (int) max(0, $total - $free) : null,
        ];
    }

    private static function memoryLimitLabel(string $value): string
    {
        return $value === '-1' ? 'unbegrenzt' : $value;
    }

    private static function plural(int $value, string $singular, string $plural): string
    {
        return $value . ' ' . ($value === 1 ? $singular : $plural);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
