<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Office\NetworkDriveService;

/**
 * Einstellungen des Snapshot-Speichers (Dateiversionen) – eine eigene
 * SMB-Freigabe, unabhaengig vom Cold-Tier (Tabelle settings, Praefix
 * storage_snapshot_).
 *
 * Das Kennwort liegt verschluesselt (SecretBox) in den Einstellungen; ein
 * leeres Kennwortfeld im Formular behaelt das gespeicherte Kennwort.
 */
final class SnapshotSettings
{
    /**
     * @var array<string,array{default:string,min:int,max:int}>
     */
    public const NUMERIC = [
        // 0 = unbegrenzt aufbewahren
        'storage_snapshot_retention_days' => ['default' => '90', 'min' => 0, 'max' => 3650],
        // 0 = Anzahl je Datei nicht begrenzen
        'storage_snapshot_max_versions' => ['default' => '20', 'min' => 0, 'max' => 10000],
    ];

    public const BOOLEAN = [
        'storage_snapshot_enabled' => '0',
    ];

    public const TEXT = [
        'storage_snapshot_unc_path' => '',
        'storage_snapshot_username' => '',
        'storage_snapshot_domain' => '',
        'storage_snapshot_password' => '',
        'storage_snapshot_smb_version' => 'auto',
    ];

    /** @var array<string,string> */
    private array $values;

    /**
     * @param array<string,string> $settings
     */
    public function __construct(array $settings)
    {
        $known = self::defaults();
        $this->values = array_merge($known, array_intersect_key($settings, $known));
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = self::BOOLEAN + self::TEXT;
        foreach (self::NUMERIC as $key => $meta) {
            $defaults[$key] = $meta['default'];
        }

        return $defaults;
    }

    /**
     * Prueft Formulareingaben. Das Kennwort wird unveraendert (Klartext)
     * zurueckgegeben und vom Dienst verschluesselt; leer = beibehalten.
     *
     * @param array<string,mixed> $input
     * @param list<string> $coldUncs UNC-Pfade der Cold-Tier-Ziele (duerfen nicht gleich sein)
     *
     * @return array{values:array<string,string>,errors:array<string,string>}
     */
    public static function validate(array $input, array $coldUncs = []): array
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

        $enabled = $values['storage_snapshot_enabled'] === '1';
        $rawUnc = trim((string) ($input['storage_snapshot_unc_path'] ?? ''));
        $parsed = $rawUnc === '' ? null : NetworkDriveService::parseUnc($rawUnc);
        if ($rawUnc === '' && $enabled) {
            $errors['storage_snapshot_unc_path'] = 'Bitte den UNC-Pfad der Snapshot-Freigabe angeben (z. B. \\\\snapshot01\\versionen).';
        } elseif ($rawUnc !== '' && $parsed === null) {
            $errors['storage_snapshot_unc_path'] = 'Bitte einen UNC-Pfad wie \\\\server\\freigabe oder \\\\server\\freigabe\\ordner angeben.';
        } elseif ($parsed !== null) {
            foreach ($coldUncs as $cold) {
                if (strcasecmp(str_replace('/', '\\', rtrim($cold, '\\/')), rtrim($parsed['unc'], '\\')) === 0) {
                    $errors['storage_snapshot_unc_path'] = 'Der Snapshot-Speicher muss eine eigene Freigabe sein und darf kein Cold-Tier-Ziel sein.';
                    break;
                }
            }
        }
        $values['storage_snapshot_unc_path'] = $parsed['unc'] ?? $rawUnc;

        $username = trim((string) ($input['storage_snapshot_username'] ?? ''));
        if ($username !== '' && preg_match(StorageService::USERNAME_PATTERN, $username) !== 1) {
            $errors['storage_snapshot_username'] = 'Benutzername ohne Domäne angeben (Domäne im eigenen Feld), keine Steuerzeichen, Komma oder Schrägstriche.';
        }
        $values['storage_snapshot_username'] = $username;

        $domain = trim((string) ($input['storage_snapshot_domain'] ?? ''));
        if ($domain !== '' && preg_match(StorageService::DOMAIN_PATTERN, $domain) !== 1) {
            $errors['storage_snapshot_domain'] = 'Ungültige Domäne (z. B. FIRMA oder firma.local).';
        }
        $values['storage_snapshot_domain'] = $domain;

        $password = (string) ($input['storage_snapshot_password'] ?? '');
        if ($password !== '' && (strlen($password) > 256 || preg_match('/[\x00\r\n]/', $password) === 1)) {
            $errors['storage_snapshot_password'] = 'Das Kennwort darf höchstens 256 Zeichen und keine Zeilenumbrüche enthalten.';
        }
        $values['storage_snapshot_password'] = $password;

        $version = (string) ($input['storage_snapshot_smb_version'] ?? 'auto');
        if (!array_key_exists($version, StorageService::SMB_VERSIONS)) {
            $errors['storage_snapshot_smb_version'] = 'Ungültige SMB-Version.';
        }
        $values['storage_snapshot_smb_version'] = $version;

        return ['values' => $values, 'errors' => $errors];
    }

    public function enabled(): bool
    {
        return $this->values['storage_snapshot_enabled'] === '1' && $this->uncPath() !== '';
    }

    public function uncPath(): string
    {
        return $this->values['storage_snapshot_unc_path'];
    }

    public function username(): string
    {
        return $this->values['storage_snapshot_username'];
    }

    public function domain(): string
    {
        return $this->values['storage_snapshot_domain'];
    }

    /** Verschluesseltes Kennwort (SecretBox) oder leer. */
    public function encryptedPassword(): string
    {
        return $this->values['storage_snapshot_password'];
    }

    public function hasPassword(): bool
    {
        return $this->values['storage_snapshot_password'] !== '';
    }

    public function smbVersion(): string
    {
        return $this->values['storage_snapshot_smb_version'];
    }

    public function retentionDays(): int
    {
        return (int) $this->values['storage_snapshot_retention_days'];
    }

    public function maxVersions(): int
    {
        return (int) $this->values['storage_snapshot_max_versions'];
    }

    /**
     * Zeile im Format der Speicherziele fuer den Mounter (Kennung 0).
     *
     * @return array<string,mixed>
     */
    public function mountRow(): array
    {
        return [
            'id' => 0,
            'label' => 'Snapshot-Speicher',
            'kind' => StorageService::KIND_SMB,
            'active' => $this->enabled() ? 1 : 0,
            'unc_path' => $this->uncPath(),
            'username' => $this->username(),
            'domain' => $this->domain(),
            'password' => $this->hasPassword() ? $this->encryptedPassword() : null,
            'smb_version' => $this->smbVersion(),
        ];
    }

    /**
     * @return array<string,string>
     */
    public function all(): array
    {
        return $this->values;
    }
}
