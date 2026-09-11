<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Validator;

/**
 * Uebersetzt AD-Rohdaten anhand des konfigurierbaren Mappings in das interne Format.
 * Bewusst frei von LDAP-Funktionen, damit die Logik testbar bleibt.
 */
final class LdapAttributeMapper
{
    /**
     * Bit im userAccountControl-Attribut, das ein deaktiviertes AD-Konto kennzeichnet.
     */
    private const UAC_ACCOUNTDISABLE = 0x0002;

    /**
     * Fest verdrahtetes AD-Attribut, um deaktivierte Konten beim Import auszuschliessen.
     */
    private const ATTRIBUTE_ACCOUNT_CONTROL = 'userAccountControl';

    /**
     * @param array<string,string> $mapping interner Schluessel => AD-Attribut
     */
    public function __construct(private readonly array $mapping)
    {
    }

    /**
     * @return list<string> Liste der tatsaechlich abzufragenden AD-Attribute
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->mapping as $attribute) {
            $attribute = trim($attribute);
            if ($attribute !== '' && Validator::isLdapAttribute($attribute)) {
                $attributes[strtolower($attribute)] = $attribute;
            }
        }

        $attributes[strtolower(self::ATTRIBUTE_ACCOUNT_CONTROL)] = self::ATTRIBUTE_ACCOUNT_CONTROL;

        return array_values($attributes);
    }

    /**
     * @param array<string,mixed> $entry Rohdatensatz (ldap_get_entries-Format oder einfaches Array)
     *
     * @return array<string,string|null>|null null, wenn der Datensatz unbrauchbar ist
     */
    public function map(array $entry, ?string $dn = null): ?array
    {
        if ($this->isAccountDisabled($entry)) {
            // Im AD deaktivierte Nutzer werden nicht (mehr) importiert.
            return null;
        }

        $uniqueAttribute = $this->mapping['unique_id'] ?? '';
        $externalId = $uniqueAttribute === '' ? null : $this->value($entry, $uniqueAttribute);

        if ($externalId !== null && $this->looksBinary($externalId)) {
            $externalId = bin2hex($externalId);
        }

        $dn = $dn ?? (isset($entry['dn']) && is_string($entry['dn']) ? $entry['dn'] : null);

        if ($externalId === null || trim($externalId) === '') {
            // Fallback: stabiler Schluessel aus dem DN.
            $externalId = $dn === null ? null : 'dn:' . hash('sha256', strtolower($dn));
        }

        if ($externalId === null) {
            return null;
        }

        $displayName = $this->value($entry, $this->mapping['display_name'] ?? '');
        $firstName = $this->value($entry, $this->mapping['first_name'] ?? '');
        $lastName = $this->value($entry, $this->mapping['last_name'] ?? '');

        if ($displayName === null || trim($displayName) === '') {
            $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
        }

        if (trim($displayName) === '') {
            // Ohne Namen ist der Eintrag fuer die Telefonliste wertlos.
            return null;
        }

        $modified = $this->value($entry, $this->mapping['modified'] ?? '');

        return [
            'external_id' => Validator::cleanText($externalId, 190),
            'display_name' => Validator::cleanText($displayName, 190),
            'first_name' => $this->clean($firstName, 100),
            'last_name' => $this->clean($lastName, 100),
            'phone' => $this->clean($this->value($entry, $this->mapping['phone'] ?? ''), 64),
            'mobile' => $this->clean($this->value($entry, $this->mapping['mobile'] ?? ''), 64),
            'email' => $this->cleanEmail($this->value($entry, $this->mapping['email'] ?? '')),
            'department' => $this->clean($this->value($entry, $this->mapping['department'] ?? ''), 120),
            'ad_modified' => self::parseAdTimestamp($modified),
        ];
    }

    /**
     * Wandelt AD-Zeitstempel (z. B. 20260902120000.0Z) in ein MySQL-DATETIME.
     */
    public static function parseAdTimestamp(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:\.\d+)?Z?$/', $value, $matches) === 1) {
            return sprintf(
                '%s-%s-%s %s:%s:%s',
                $matches[1],
                $matches[2],
                $matches[3],
                $matches[4],
                $matches[5],
                $matches[6]
            );
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Prueft anhand des userAccountControl-Attributs, ob das AD-Konto deaktiviert ist.
     *
     * @param array<string,mixed> $entry
     */
    private function isAccountDisabled(array $entry): bool
    {
        $raw = $this->value($entry, self::ATTRIBUTE_ACCOUNT_CONTROL);
        if ($raw === null || trim($raw) === '' || !is_numeric($raw)) {
            return false;
        }

        return ((int) $raw & self::UAC_ACCOUNTDISABLE) === self::UAC_ACCOUNTDISABLE;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function value(array $entry, string $attribute): ?string
    {
        $attribute = trim($attribute);
        if ($attribute === '') {
            return null;
        }

        $key = strtolower($attribute);
        /** @var mixed $raw */
        $raw = $entry[$key] ?? $entry[$attribute] ?? null;

        if (is_array($raw)) {
            // ldap_get_entries liefert ['count' => n, 0 => 'wert', ...]
            $raw = $raw[0] ?? null;
        }

        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }

        return is_string($raw) ? $raw : null;
    }

    private function clean(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = Validator::cleanText($value, $max);

        return $value === '' ? null : $value;
    }

    private function cleanEmail(?string $value): ?string
    {
        $value = $this->clean($value, 190);
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : $value;
    }

    private function looksBinary(string $value): bool
    {
        return preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0E-\x1F]/', $value) === 1;
    }
}
