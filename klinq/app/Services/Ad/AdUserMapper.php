<?php

declare(strict_types=1);

namespace App\Services\Ad;

/**
 * Übersetzt einen LDAP-Eintrag anhand des konfigurierbaren Attribut-Mappings
 * in die Felder der Tabelle employees.
 */
final class AdUserMapper
{
    private const ACCOUNT_DISABLE = 0x0002;

    /** @param array<string,string> $attributes Feld → LDAP-Attribut */
    public function __construct(private readonly array $attributes) {}

    /** @return array<int,string> LDAP-Attribute, die abgefragt werden müssen */
    public function ldapAttributes(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', $this->attributes))));
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>|null null, wenn keine GUID vorhanden ist
     */
    public function map(array $entry): ?array
    {
        $guid = self::formatGuid($this->value($entry, 'guid'));
        if ($guid === null) {
            return null;
        }
        $first = $this->value($entry, 'first_name') ?? '';
        $last = $this->value($entry, 'last_name') ?? '';
        $display = $this->value($entry, 'display_name') ?: trim($first . ' ' . $last);
        $uac = $this->value($entry, 'account_control');
        $active = $uac === null || ((int) $uac & self::ACCOUNT_DISABLE) === 0;

        return [
            'ad_object_guid' => $guid,
            'username' => $this->value($entry, 'username'),
            'first_name' => $first,
            'last_name' => $last !== '' ? $last : ($display !== '' ? $display : ($this->value($entry, 'username') ?? $guid)),
            'display_name' => $display !== '' ? $display : ($this->value($entry, 'username') ?? $guid),
            'email' => self::lower($this->value($entry, 'email')),
            'personnel_number' => $this->value($entry, 'personnel_number'),
            'department' => $this->value($entry, 'department'),
            'position' => $this->value($entry, 'position'),
            'phone' => $this->value($entry, 'phone'),
            'ad_location' => $this->value($entry, 'location'),
            'ad_cost_center' => $this->value($entry, 'cost_center'),
            'is_active' => $active ? 1 : 0,
        ];
    }

    /** Wandelt eine binäre objectGUID (16 Byte, Mixed-Endian) oder Textform in die kanonische GUID um. */
    public static function formatGuid(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/^\{?([0-9a-f]{8})-([0-9a-f]{4})-([0-9a-f]{4})-([0-9a-f]{4})-([0-9a-f]{12})\}?$/i', $raw, $m)) {
            return strtolower("{$m[1]}-{$m[2]}-{$m[3]}-{$m[4]}-{$m[5]}");
        }
        if (strlen($raw) === 16) {
            $hex = bin2hex($raw);
            $swap = static fn (string $h): string => implode('', array_reverse(str_split($h, 2)));

            return strtolower(sprintf(
                '%s-%s-%s-%s-%s',
                $swap(substr($hex, 0, 8)),
                $swap(substr($hex, 8, 4)),
                $swap(substr($hex, 12, 4)),
                substr($hex, 16, 4),
                substr($hex, 20, 12)
            ));
        }
        if (preg_match('/^[0-9a-f]{32}$/i', $raw)) {
            return strtolower(sprintf('%s-%s-%s-%s-%s', substr($raw, 0, 8), substr($raw, 8, 4), substr($raw, 12, 4), substr($raw, 16, 4), substr($raw, 20)));
        }

        return null;
    }

    /** @param array<string,mixed> $entry */
    private function value(array $entry, string $field): ?string
    {
        $attribute = strtolower((string) ($this->attributes[$field] ?? ''));
        if ($attribute === '' || !array_key_exists($attribute, $entry)) {
            return null;
        }
        $value = $entry[$attribute];
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function lower(?string $value): ?string
    {
        return $value === null ? null : mb_strtolower($value);
    }
}
