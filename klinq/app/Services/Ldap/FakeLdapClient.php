<?php

declare(strict_types=1);

namespace App\Services\Ldap;

use RuntimeException;

/**
 * LDAP-Ersatz für Entwicklung und Tests: liefert Einträge aus einer JSON-Datei
 * (Liste von Objekten "Attribut → Wert"). Aktiviert über AD_DRIVER=fake.
 */
final class FakeLdapClient implements LdapClientInterface
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $entries;

    /** @param array<int,array<string,mixed>>|null $entries Direkt gesetzte Einträge (Tests) */
    public function __construct(private readonly string $file = '', ?array $entries = null)
    {
        $this->entries = $entries;
    }

    public function bind(string $dn, string $password): bool
    {
        // Simulierte Anmeldung: jedes nicht-leere Passwort wird akzeptiert
        return $dn !== '' && $password !== '';
    }

    public function search(string $baseDn, string $filter, array $attributes): array
    {
        $wanted = array_map('strtolower', $attributes);
        $results = [];
        foreach ($this->load() as $entry) {
            $row = ['dn' => (string) ($entry['dn'] ?? '')];
            foreach ($entry as $key => $value) {
                $lower = strtolower((string) $key);
                if ($lower === 'dn' || ($wanted !== [] && !in_array($lower, $wanted, true))) {
                    continue;
                }
                $row[$lower] = $value;
            }
            $results[] = $row;
        }

        return $results;
    }

    public function close(): void {}

    /** @return array<int,array<string,mixed>> */
    private function load(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        if ($this->file === '' || !is_file($this->file)) {
            throw new RuntimeException('Fake-AD-Datei nicht gefunden: ' . $this->file);
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($data)) {
            throw new RuntimeException('Fake-AD-Datei enthält kein gültiges JSON-Array: ' . $this->file);
        }

        return $this->entries = array_values($data);
    }
}
