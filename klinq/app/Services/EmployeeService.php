<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\EmployeeRepository;
use App\Support\ColognePhonetic;
use App\Support\Validator;

/** Manuelle Pflege von Mitarbeitern (AD-Mitarbeiter: nur lokale Zuordnungen änderbar). */
final class EmployeeService
{
    /** Felder, die bei AD-Mitarbeitern lokal gepflegt werden dürfen. */
    private const LOCAL_FIELDS = ['location_id', 'cost_center_id'];

    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly AuditLogService $audit
    ) {}

    /** @param array<string,mixed> $input */
    public function create(array $input): int
    {
        $data = $this->validate($input, null);
        $data['source'] = 'manual';
        $id = $this->employees->create($data);
        $this->audit->log('create', 'employee', $id, $data['display_name'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input): void
    {
        $data = $this->validate($input, $id);
        if ($existing['source'] === 'ad') {
            $data = array_intersect_key($data, array_flip(self::LOCAL_FIELDS));
        }
        $this->employees->update($id, $data);
        $this->audit->log('update', 'employee', $id, (string) $existing['display_name'], $existing, $data);
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->employees->update($id, ['is_active' => $active ? 1 : 0, 'deactivated_at' => $active ? null : gmdate('Y-m-d H:i:s')]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'employee', $id, (string) $existing['display_name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * Autovervollständigung für die Melder-Auswahl im Help Desk: findet Treffer per Teilstring
     * (Name, Benutzername, Personalnummer, Abteilung) sowie phonetisch (Kölner Phonetik), damit
     * z. B. „Meier“ auch „Maier“ oder „Mayer“ findet. Teilstring-Treffer werden zuerst gelistet.
     * @return array<int,array<string,mixed>>
     */
    public function searchAutocomplete(string $term, int $limit = 30): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $termLower = mb_strtolower($term);
        $termCodes = self::phoneticCodes($term);

        $direct = [];
        $phonetic = [];
        foreach ($this->employees->activeForAutocomplete() as $row) {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $row['display_name'] ?? '',
                $row['username'] ?? '',
                $row['personnel_number'] ?? '',
                $row['department'] ?? '',
            ])));
            if (str_contains($haystack, $termLower)) {
                $direct[] = $row;
                continue;
            }
            if ($termCodes !== [] && array_intersect($termCodes, self::phoneticCodes((string) $row['display_name'])) !== []) {
                $phonetic[] = $row;
            }
        }

        $byName = static fn (array $a, array $b): int => strcmp((string) $a['display_name'], (string) $b['display_name']);
        usort($direct, $byName);
        usort($phonetic, $byName);

        return array_slice(array_merge($direct, $phonetic), 0, $limit);
    }

    /** Phonetische Codes der einzelnen Wörter eines Namens (Kölner Phonetik). @return array<int,string> */
    private static function phoneticCodes(string $text): array
    {
        $words = preg_split('/\s+/', trim(ColognePhonetic::normalize($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map([ColognePhonetic::class, 'encodeWord'], $words))));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId): array
    {
        $v = (new Validator($input))
            ->string('first_name', 'Vorname', false, 120)
            ->string('last_name', 'Nachname', true, 120)
            ->string('display_name', 'Anzeigename', false, 250)
            ->pattern('username', 'Benutzername', '/^[a-zA-Z0-9._\-]{1,120}$/', 'Der Benutzername darf nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich enthalten.')
            ->email('email', 'E-Mail')
            ->string('personnel_number', 'Personalnummer', false, 50)
            ->string('department', 'Abteilung', false, 150)
            ->string('position', 'Position', false, 150)
            ->string('phone', 'Telefon', false, 60)
            ->id('location_id', 'Standort')
            ->id('cost_center_id', 'Kostenstelle')
            ->bool('is_active');
        $data = $v->validated();
        $data['first_name'] ??= '';
        $data['display_name'] = $data['display_name'] ?: trim($data['first_name'] . ' ' . $data['last_name']);

        if ($data['username'] !== null) {
            $other = $this->employees->findByUsername($data['username']);
            if ($other !== null && (int) $other['id'] !== $excludeId) {
                throw ValidationException::single('username', 'Dieser Benutzername ist bereits einem Mitarbeiter zugeordnet.');
            }
        }
        if ($data['personnel_number'] !== null) {
            $other = $this->employees->findByPersonnelNumber($data['personnel_number']);
            if ($other !== null && (int) $other['id'] !== $excludeId) {
                throw ValidationException::single('personnel_number', 'Diese Personalnummer ist bereits vergeben.');
            }
        }

        return $data;
    }
}
