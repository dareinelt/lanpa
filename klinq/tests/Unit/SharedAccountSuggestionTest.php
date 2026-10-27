<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\SharedAccountSuggestionService;
use Tests\Support\TestCase;

/**
 * Vorschläge für das Feld „Sammelkonten“: Normalisierung, Entdopplung,
 * Sortierung nach Präfix und Begrenzung der Trefferzahl.
 */
final class SharedAccountSuggestionTest extends TestCase
{
    public function testAccountsAreNormalizedAndDeduplicated(): void
    {
        $items = SharedAccountSuggestionService::merge([
            ['username' => 'FIRMA\\Empfang', 'detail' => 'Empfang · Verwaltung'],
            ['username' => 'empfang@firma.local', 'detail' => 'Doppelt'],
            ['username' => 'besprechungsraum1', 'detail' => ''],
        ], 'empf');

        $this->assertSame(['empfang', 'besprechungsraum1'], array_column($items, 'name'));
        $this->assertSame('Empfang · Verwaltung', $items[0]['meta']);
    }

    public function testInvalidEntriesAreDropped(): void
    {
        $items = SharedAccountSuggestionService::merge([
            ['username' => 'kein gueltiger name'],
            ['username' => ''],
            ['username' => 'FIRMA\\'],
            ['username' => 'mmuster'],
        ], 'mm');

        $this->assertSame(['mmuster'], array_column($items, 'name'));
    }

    public function testPrefixMatchesAreListedFirst(): void
    {
        $items = SharedAccountSuggestionService::merge([
            ['username' => 'zz-empfang'],
            ['username' => 'empfang'],
            ['username' => 'empfang-2'],
        ], 'empf');

        $this->assertSame(['empfang', 'empfang-2', 'zz-empfang'], array_column($items, 'name'));
    }

    public function testResultIsLimited(): void
    {
        $rows = [];
        foreach (range(1, 30) as $i) {
            $rows[] = ['username' => 'konto' . $i];
        }

        $this->assertCount(5, SharedAccountSuggestionService::merge($rows, 'konto', 5));
    }
}
