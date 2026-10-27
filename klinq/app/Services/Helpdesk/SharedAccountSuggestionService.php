<?php

declare(strict_types=1);

namespace App\Services\Helpdesk;

use App\Repositories\EmployeeRepository;
use App\Repositories\UserRepository;
use App\Services\Lanpa\LanpaBridge;

/**
 * Vorschläge für das Feld „Sammelkonten“ in den Help-Desk-Einstellungen.
 *
 * Quelle sind die Anmeldenamen aus dem Intranet-Telefonbuch (alle
 * Identitätsquellen), den aktiven Mitarbeitern und den lokalen KLINQ-Konten.
 * Damit lassen sich auch Dienstkonten eintragen, die keinem Mitarbeiter
 * zugeordnet sind. Ungültige Anmeldenamen werden wie beim Speichern verworfen.
 */
final class SharedAccountSuggestionService
{
    public const LIMIT = 10;

    public function __construct(
        private readonly LanpaBridge $lanpa,
        private readonly EmployeeRepository $employees,
        private readonly UserRepository $users
    ) {}

    /**
     * @return list<array{id:string,name:string,label:string,meta:string}>
     */
    public function suggest(string $term, int $limit = self::LIMIT): array
    {
        $term = trim($term);
        if (strlen($term) < 2) {
            return [];
        }
        $limit = max(1, min(25, $limit));

        $rows = [];
        foreach ($this->lanpa->phonebook($term, $limit) ?? [] as $entry) {
            $rows[] = [
                'username' => (string) ($entry['username'] ?? ''),
                'detail' => self::detail((string) ($entry['display_name'] ?? ''), (string) ($entry['department'] ?? '')),
            ];
        }
        foreach ($this->employees->suggestAccounts($term, $limit) as $row) {
            $rows[] = [
                'username' => (string) $row['username'],
                'detail' => self::detail((string) ($row['display_name'] ?? ''), (string) ($row['department'] ?? '')),
            ];
        }
        foreach ($this->users->suggestAccounts($term, $limit) as $row) {
            $rows[] = [
                'username' => (string) $row['username'],
                'detail' => self::detail((string) ($row['display_name'] ?? ''), (string) ($row['role_label'] ?? '')),
            ];
        }

        return self::merge($rows, $term, $limit);
    }

    /**
     * Rohzeilen zusammenführen, normalisieren, entdoppeln und sortieren.
     * @param list<array{username:string,detail?:string}> $rows
     * @return list<array{id:string,name:string,label:string,meta:string}>
     */
    public static function merge(array $rows, string $term, int $limit = self::LIMIT): array
    {
        $items = [];
        foreach ($rows as $row) {
            $name = ReporterIdentityService::normalize($row['username'] ?? '');
            if ($name === null || isset($items[$name])) {
                continue;
            }
            $items[$name] = [
                'id' => $name,
                'name' => $name,
                'label' => $name,
                'meta' => trim((string) ($row['detail'] ?? '')),
            ];
        }

        $prefix = strtolower(trim($term));
        $sorted = array_values($items);
        usort($sorted, static fn (array $a, array $b): int => [
            str_starts_with($a['name'], $prefix) ? 0 : 1,
            $a['name'],
        ] <=> [
            str_starts_with($b['name'], $prefix) ? 0 : 1,
            $b['name'],
        ]);

        return array_slice($sorted, 0, max(1, $limit));
    }

    private static function detail(string $displayName, string $extra): string
    {
        return implode(' · ', array_filter(
            [trim($displayName), trim($extra)],
            static fn (string $value): bool => $value !== ''
        ));
    }
}
