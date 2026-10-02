<?php

declare(strict_types=1);

namespace App\Services\Storage;

/**
 * Einstellungen des Speicher-Tierings (Tabelle settings, Praefix storage_).
 *
 * Wird sowohl vom Adminbereich als auch vom Container storage-sync gelesen.
 */
final class StorageSettings
{
    public const MIB = 1048576;

    /**
     * @var array<string,array{default:string,min:int,max:int}>
     */
    public const NUMERIC = [
        'storage_local_days' => ['default' => '30', 'min' => 1, 'max' => 3650],
        'storage_hot_access_days' => ['default' => '3', 'min' => 1, 'max' => 30],
        'storage_local_max_file_mb' => ['default' => '0', 'min' => 0, 'max' => 10485760],
        'storage_local_limit_mb' => ['default' => '0', 'min' => 0, 'max' => 1073741824],
        'storage_full_scan_minutes' => ['default' => '60', 'min' => 5, 'max' => 1440],
        'storage_db_dump_minutes' => ['default' => '60', 'min' => 5, 'max' => 1440],
        'storage_lag_warn_minutes' => ['default' => '15', 'min' => 1, 'max' => 1440],
        'storage_fill_warn_percent' => ['default' => '85', 'min' => 50, 'max' => 99],
        'storage_fill_crit_percent' => ['default' => '95', 'min' => 50, 'max' => 100],
        'storage_recall_timeout' => ['default' => '600', 'min' => 30, 'max' => 7200],
    ];

    public const BOOLEAN = [
        'storage_enabled' => '0',
        'storage_eviction_enabled' => '1',
    ];

    /** Dateien mit Aktivitaet in diesem Zeitraum werden nie verdraengt (laufende Bearbeitung). */
    public const GRACE_SECONDS = 600;

    /** @var array<string,string> */
    private array $values;

    /**
     * @param array<string,string> $settings Einstellungen (settings-Tabelle)
     */
    public function __construct(array $settings)
    {
        $known = self::defaults() + ['storage_instance_id' => ''];
        $this->values = array_merge($known, array_intersect_key($settings, $known));
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = self::BOOLEAN;
        foreach (self::NUMERIC as $key => $meta) {
            $defaults[$key] = $meta['default'];
        }

        return $defaults;
    }

    /**
     * Prueft Formulareingaben.
     *
     * @param array<string,mixed> $input
     *
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $values = [];
        $errors = [];
        foreach (array_keys(self::BOOLEAN) as $key) {
            $values[$key] = !empty($input[$key]) && $input[$key] !== '0' ? '1' : '0';
        }
        foreach (self::NUMERIC as $key => $meta) {
            $raw = trim((string) ($input[$key] ?? $meta['default']));
            if (preg_match('/^\d{1,10}$/', $raw) !== 1 || (int) $raw < $meta['min'] || (int) $raw > $meta['max']) {
                $errors[$key] = sprintf('Bitte eine ganze Zahl zwischen %d und %d angeben.', $meta['min'], $meta['max']);
                $values[$key] = $raw;
                continue;
            }
            $values[$key] = (string) (int) $raw;
        }
        if (!isset($errors['storage_fill_warn_percent']) && !isset($errors['storage_fill_crit_percent'])
            && (int) $values['storage_fill_warn_percent'] >= (int) $values['storage_fill_crit_percent']) {
            $errors['storage_fill_crit_percent'] = 'Der kritische Wert muss über der Warnschwelle liegen.';
        }

        return ['values' => $values, 'errors' => $errors];
    }

    public function enabled(): bool
    {
        return $this->values['storage_enabled'] === '1';
    }

    public function evictionEnabled(): bool
    {
        return $this->values['storage_eviction_enabled'] === '1';
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? self::NUMERIC[$key]['default'] ?? 0);
    }

    public function localDays(): int
    {
        return $this->int('storage_local_days');
    }

    public function hotAccessDays(): int
    {
        return $this->int('storage_hot_access_days');
    }

    /** 0 = keine Begrenzung. */
    public function maxLocalFileBytes(): int
    {
        return $this->int('storage_local_max_file_mb') * self::MIB;
    }

    /** 0 = automatisch (nach freiem Platz des lokalen Datentraegers). */
    public function localLimitBytes(): int
    {
        return $this->int('storage_local_limit_mb') * self::MIB;
    }

    public function fullScanSeconds(): int
    {
        return $this->int('storage_full_scan_minutes') * 60;
    }

    public function dbDumpSeconds(): int
    {
        return $this->int('storage_db_dump_minutes') * 60;
    }

    public function lagWarnSeconds(): int
    {
        return $this->int('storage_lag_warn_minutes') * 60;
    }

    public function fillWarnPercent(): int
    {
        return $this->int('storage_fill_warn_percent');
    }

    public function fillCritPercent(): int
    {
        return $this->int('storage_fill_crit_percent');
    }

    public function recallTimeout(): int
    {
        return $this->int('storage_recall_timeout');
    }

    public function instanceId(): string
    {
        return $this->values['storage_instance_id'];
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
