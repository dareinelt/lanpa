<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ManufacturerService;
use App\Support\ColognePhonetic;
use Tests\Support\TestCase;

final class ManufacturerRankTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function candidates(): array
    {
        $rows = [];
        foreach (['Hewlett Packard', 'HP Inc.', 'Lenovo', 'Dell Technologies', 'Hewlett-Packard Enterprise'] as $i => $name) {
            $normalized = ColognePhonetic::normalizedName($name);
            $rows[] = ['id' => $i + 1, 'name' => $name, 'normalized_name' => $normalized, 'phonetic_key' => ColognePhonetic::encode($normalized)];
        }

        return $rows;
    }

    private function rank(string $input): array
    {
        $normalized = ColognePhonetic::normalizedName($input);

        return ManufacturerService::rankCandidates($normalized, ColognePhonetic::encode($normalized), $this->candidates());
    }

    public function testExactNormalizedMatchScoresHighest(): void
    {
        $result = $this->rank('HEWLETT-PACKARD GmbH');
        $this->assertSame('Hewlett Packard', $result[0]['name']);
        $this->assertSame(100, $result[0]['score']);
    }

    public function testPhoneticMatchIsRecognised(): void
    {
        $result = $this->rank('Hewlet Packart');
        $this->assertSame('Hewlett Packard', $result[0]['name']);
        $this->assertSame(90, $result[0]['score']);
        $this->assertSame('Klingt gleich', $result[0]['reason']);
    }

    public function testContainmentScores70(): void
    {
        $result = $this->rank('Hewlett Packard Enterprise');
        $names = array_column($result, 'name');
        $this->assertContains('Hewlett Packard', $names);
        $hp = array_values(array_filter($result, static fn (array $r): bool => $r['name'] === 'Hewlett Packard'))[0];
        $this->assertSame(70, $hp['score']);
    }

    public function testUnrelatedNamesAreDropped(): void
    {
        $result = $this->rank('Apple');
        $this->assertCount(0, $result);
    }

    public function testResultsSortedByScoreDescending(): void
    {
        $result = $this->rank('Hewlett Packard');
        $scores = array_column($result, 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }
}
