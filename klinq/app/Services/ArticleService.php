<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\ArticleRepository;
use App\Repositories\AssetTypeRepository;
use App\Repositories\ManufacturerRepository;
use App\Support\ColognePhonetic;
use App\Support\Validator;

final class ArticleService
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ManufacturerRepository $manufacturers,
        private readonly AssetTypeRepository $assetTypes,
        private readonly AuditLogService $audit
    ) {}

    /**
     * Mögliche Dubletten zu einem Artikelnamen (phonetisch, normalisiert, Teilstring, Artikelnummer).
     * Gleicher Hersteller wird höher bewertet; andere Hersteller werden als Hinweis mitgeliefert.
     * @return array<int,array<string,mixed>> jeweils mit 'reason' und 'score'
     */
    public function findDuplicates(string $name, ?int $manufacturerId = null, ?string $articleNumber = null, ?int $excludeId = null): array
    {
        $normalized = ColognePhonetic::normalizedArticleName($name);
        if ($normalized === '') {
            return [];
        }
        $phonetic = ColognePhonetic::encode($normalized);
        $candidates = $this->articles->findCandidates($phonetic, $normalized, $articleNumber, $excludeId);

        return self::rankCandidates($normalized, $phonetic, $candidates, $manufacturerId, $articleNumber);
    }

    /**
     * Bewertet Kandidaten (auch ohne Datenbank testbar). Baut auf der Hersteller-Bewertung auf und
     * ergänzt Artikelnummer-Treffer sowie die Abwertung fremder Hersteller.
     * @param array<int,array<string,mixed>> $candidates Zeilen mit name, normalized_name, phonetic_key, manufacturer_id, article_number
     * @return array<int,array<string,mixed>>
     */
    public static function rankCandidates(string $normalized, string $phonetic, array $candidates, ?int $manufacturerId = null, ?string $articleNumber = null): array
    {
        $ranked = ManufacturerService::rankCandidates($normalized, $phonetic, array_map(static function (array $c): array {
            $c['normalized_name'] = (string) ($c['normalized_name'] ?? ColognePhonetic::normalizedArticleName((string) $c['name']));
            $c['phonetic_key'] = (string) ($c['phonetic_key'] ?? ColognePhonetic::encode($c['normalized_name']));

            return $c;
        }, $candidates));
        $byId = [];
        foreach ($ranked as $r) {
            $byId[(int) $r['id']] = $r;
        }

        $results = [];
        $number = $articleNumber !== null ? mb_strtolower(trim($articleNumber)) : '';
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            $entry = $byId[$id] ?? null;
            $sameNumber = $number !== '' && mb_strtolower(trim((string) ($candidate['article_number'] ?? ''))) === $number;
            if ($entry === null && !$sameNumber) {
                continue;
            }
            $entry ??= array_merge($candidate, ['score' => 0, 'reason' => '']);
            if ($sameNumber) {
                $entry['score'] = max((int) $entry['score'], 95);
                $entry['reason'] = 'Gleiche Artikelnummer' . ($entry['reason'] !== '' ? ' · ' . $entry['reason'] : '');
            }
            $otherManufacturer = $manufacturerId !== null && isset($candidate['manufacturer_id']) && (int) $candidate['manufacturer_id'] !== $manufacturerId;
            if ($otherManufacturer) {
                // Anderer Hersteller: nur als Hinweis, wenn wirklich stark ähnlich
                if ((int) $entry['score'] < 90) {
                    continue;
                }
                $entry['score'] = (int) $entry['score'] - 15;
                $entry['reason'] .= ' (anderer Hersteller)';
            }
            $results[] = $entry;
        }
        usort($results, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp((string) $a['name'], (string) $b['name']));

        return $results;
    }

    /** @param array<string,mixed> $input */
    public function create(array $input, bool $ignoreDuplicates = false): int
    {
        $data = $this->validate($input, null, $ignoreDuplicates);
        $id = $this->articles->create($data);
        $this->audit->log('create', 'article', $id, $data['name'], null, $data);

        return $id;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $existing, array $input, bool $ignoreDuplicates = false): void
    {
        $data = $this->validate($input, $id, $ignoreDuplicates);
        $this->articles->update($id, $data);
        $this->audit->log('update', 'article', $id, $data['name'], $existing, $data);
    }

    /** @param array<string,mixed> $existing */
    public function setActive(int $id, array $existing, bool $active): void
    {
        $this->articles->update($id, ['is_active' => $active ? 1 : 0]);
        $this->audit->log($active ? 'activate' : 'deactivate', 'article', $id, (string) $existing['name'], ['is_active' => $existing['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function validate(array $input, ?int $excludeId, bool $ignoreDuplicates): array
    {
        $data = (new Validator($input))
            ->id('manufacturer_id', 'Hersteller', true)
            ->id('asset_type_id', 'Assettyp', true)
            ->id('asset_category_id', 'Kategorie')
            ->string('name', 'Bezeichnung', true, 200)
            ->string('article_number', 'Artikelnummer', false, 100)
            ->text('description', 'Beschreibung')
            ->bool('is_active')
            ->bool('is_handover_relevant')
            ->bool('is_consumable')
            ->int('minimum_stock', 'Mindestbestand', false, 0)
            ->int('stock_quantity', 'Aktueller Bestand', true, 0)
            ->validated();

        if ($this->manufacturers->find((int) $data['manufacturer_id']) === null) {
            throw ValidationException::single('manufacturer_id', 'Hersteller nicht gefunden. Bitte zuerst den Hersteller anlegen.');
        }
        if ($this->assetTypes->find((int) $data['asset_type_id']) === null) {
            throw ValidationException::single('asset_type_id', 'Assettyp nicht gefunden.');
        }
        if ($data['asset_category_id'] !== null) {
            $category = $this->assetTypes->findCategory((int) $data['asset_category_id']);
            if ($category === null || (int) $category['asset_type_id'] !== (int) $data['asset_type_id']) {
                throw ValidationException::single('asset_category_id', 'Die Kategorie passt nicht zum gewählten Assettyp.');
            }
            if ((int) $data['is_consumable'] !== 1) {
                $data['minimum_stock'] = null;
                $data['stock_quantity'] = 0;
            }
        }

        $data['normalized_name'] = mb_substr(ColognePhonetic::normalizedArticleName($data['name']), 0, 200);
        $data['phonetic_key'] = mb_substr(ColognePhonetic::encode($data['normalized_name']), 0, 120);

        $manufacturerId = (int) $data['manufacturer_id'];
        $exact = $this->articles->findDuplicate($manufacturerId, $data['name'], $excludeId)
            ?? $this->articles->findByNormalizedName($manufacturerId, $data['normalized_name'], $excludeId);
        if ($exact !== null) {
            throw ValidationException::single('name', "Dieser Artikel existiert für den Hersteller bereits: „{$exact['name']}“.");
        }
        if (!$ignoreDuplicates) {
            $duplicates = $this->findDuplicates($data['name'], $manufacturerId, $data['article_number'], $excludeId);
            if ($duplicates !== []) {
                throw new DuplicateWarningException($duplicates);
            }
        }

        return $data;
    }
}
