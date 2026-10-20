<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\ManufacturerRepository;
use App\Support\ColognePhonetic;
use App\Support\Validator;

final class ManufacturerService
{
    public function __construct(
        private readonly ManufacturerRepository $manufacturers,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Mögliche Dubletten zu einem Namen (phonetisch, normalisiert, Teilstring), sortiert nach Ähnlichkeit.
     * @return array<int,array<string,mixed>> jeweils mit 'reason' und 'score'
     */
    public function findDuplicates(string $name, ?int $excludeId = null): array
    {
        $normalized = ColognePhonetic::normalizedName($name);
        if ($normalized === '') {
            return [];
        }
        $phonetic = ColognePhonetic::encode($normalized);
        $candidates = $this->manufacturers->findCandidates($phonetic, $normalized, $excludeId);

        return self::rankCandidates($normalized, $phonetic, $candidates);
    }

    /**
     * Bewertet Kandidaten (auch ohne Datenbank testbar).
     * @param array<int,array<string,mixed>> $candidates Zeilen mit name, normalized_name, phonetic_key
     * @return array<int,array<string,mixed>>
     */
    public static function rankCandidates(string $normalized, string $phonetic, array $candidates): array
    {
        $results = [];
        foreach ($candidates as $candidate) {
            $candNormalized = (string) ($candidate['normalized_name'] ?? ColognePhonetic::normalizedName((string) $candidate['name']));
            $candPhonetic = (string) ($candidate['phonetic_key'] ?? ColognePhonetic::encode($candNormalized));

            if ($candNormalized === $normalized) {
                $score = 100;
                $reason = 'Gleicher Name (abweichende Schreibweise)';
            } elseif ($candPhonetic === $phonetic) {
                $score = 90;
                $reason = 'Klingt gleich';
            } elseif (str_contains($candNormalized, $normalized) || str_contains($normalized, $candNormalized)) {
                $score = 70;
                $reason = 'Enthält den Namen';
            } else {
                $shared = array_intersect(explode(' ', $phonetic), explode(' ', $candPhonetic));
                $total = max(count(explode(' ', $phonetic)), count(explode(' ', $candPhonetic)));
                $ratio = $total > 0 ? count($shared) / $total : 0;
                $lev = levenshtein(substr($normalized, 0, 60), substr($candNormalized, 0, 60));
                $maxLen = max(strlen($normalized), strlen($candNormalized), 1);
                $similarity = 1 - $lev / $maxLen;
                if ($ratio < 0.5 && $similarity < 0.75) {
                    continue;
                }
                $score = (int) round(max($ratio, $similarity) * 80);
                $reason = 'Ähnlicher Name';
            }
            $results[] = array_merge($candidate, ['score' => $score, 'reason' => $reason]);
        }
        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp((string) $a['name'], (string) $b['name']));

        return $results;
    }

    /**
     * @param array<string,mixed> $input
     * @return int ID
     */
    public function create(array $input, bool $ignoreDuplicates = false): int
    {
        $data = $this->validate($input, null, $ignoreDuplicates);
        $id = $this->manufacturers->create($data);
        $this->audit->log('create', 'manufacturer', $id, $data['name'], null, $data);

        return $id;
    }

    /** @param array<string,mixed> $input */
    public function update(int $id, array $existing, array $input, bool $ignoreDuplicates = false): void
    {
        $data = $this->validate($input, $id, $ignoreDuplicates);
        $this->manufacturers->update($id, $data);
        $this->audit->log('update', 'manufacturer', $id, $data['name'], $existing, $data);
    }

    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->manufacturers->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'manufacturer', $id, (string) $existing['name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId, bool $ignoreDuplicates): array
    {
        $v = (new Validator($input))
            ->string('name', 'Name', true, 150, 2)
            ->string('short_name', 'Kurzname', false, 50)
            ->url('website', 'Webseite')
            ->string('contact', 'Kontakt', false, 255)
            ->text('note', 'Bemerkung')
            ->bool('is_active');
        $data = $v->validated();

        $data['normalized_name'] = ColognePhonetic::normalizedName($data['name']);
        $data['phonetic_key'] = ColognePhonetic::encode($data['normalized_name']);

        $exact = $this->manufacturers->findByNormalizedName($data['normalized_name'], $excludeId);
        if ($exact !== null) {
            throw ValidationException::single('name', "Ein Hersteller mit diesem Namen existiert bereits: „{$exact['name']}“.");
        }
        if (!$ignoreDuplicates) {
            $duplicates = self::rankCandidates($data['normalized_name'], $data['phonetic_key'], $this->manufacturers->findCandidates($data['phonetic_key'], $data['normalized_name'], $excludeId));
            if ($duplicates !== []) {
                throw new DuplicateWarningException($duplicates);
            }
        }

        return $data;
    }
}
