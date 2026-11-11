<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Services\Helpdesk\HelpdeskContactService;
use App\Support\FuzzySearch;
use Tests\Support\TestCase;

/**
 * Kontaktverzeichnis des Help Desk ohne Datenbank: Ähnlichkeitssuche, Pflichtregeln (Firma, Vor-/Nachname,
 * Rufnummer oder E-Mail), Stichwort-Zerlegung und Filterung einer alphabetischen Liste.
 */
final class HelpdeskContactTest extends TestCase
{
    /** @return list<string> */
    private function rpdoc(): array
    {
        return ['RPDoc', 'Max', 'Mustermann', '0531 / 123-45', '', 'max@rpdoc.de', 'Wartung Röntgen', 'Hotline'];
    }

    public function testExactMatchesAcrossAllFields(): void
    {
        foreach (['rpdoc', 'RPD', 'max mustermann', 'Mustermann Max', 'hotline', 'röntgen', 'roentgen', '0531', 'max@rpdoc'] as $q) {
            $this->assertSame(FuzzySearch::EXACT, FuzzySearch::match($q, $this->rpdoc()), $q);
        }
        $this->assertSame(FuzzySearch::EXACT, FuzzySearch::match('', $this->rpdoc()), 'Ohne Suchbegriff passt alles');
    }

    public function testSimilarityFindsSpacingAndTypos(): void
    {
        $this->assertNotNull(FuzzySearch::match('rp doc', ['RPDoc']), 'Leerzeichen im Suchbegriff');
        $this->assertSame(FuzzySearch::SIMILAR, FuzzySearch::match('rpdok', ['RPDoc']), 'Tippfehler c/k');
        $this->assertSame(FuzzySearch::SIMILAR, FuzzySearch::match('rp dok', ['RPDoc']), 'Leerzeichen und Tippfehler');
        $this->assertSame(FuzzySearch::SIMILAR, FuzzySearch::match('Mustermsnn', $this->rpdoc()));
        $this->assertSame(FuzzySearch::SIMILAR, FuzzySearch::match('Maier', ['Meyer']), 'Gleicher Klang (Kölner Phonetik)');
        $this->assertSame(FuzzySearch::SIMILAR, FuzzySearch::match('053112345', $this->rpdoc()), 'Rufnummer ohne Trennzeichen');
        $this->assertSame(FuzzySearch::SIMILAR, FuzzySearch::match('Rp-Doc', ['R P Doc GmbH']));
    }

    public function testSimilarityStaysStrictWhereItMustBe(): void
    {
        $this->assertNull(FuzzySearch::match('siemens', $this->rpdoc()));
        $this->assertNull(FuzzySearch::match('12346', $this->rpdoc()), 'Ziffern nie unscharf');
        $this->assertNull(FuzzySearch::match('rpx', ['RPDoc']), 'Kurze Wörter ohne Tippfehler-Toleranz');
        $this->assertNull(FuzzySearch::match('rpdoc siemens', ['RPDoc']), 'Alle Wörter müssen passen');
        $this->assertNull(FuzzySearch::match('rpdoc', ['', null]));
        // Keine Treffer über kurze Füllwörter in der Bemerkung (phonetisch bzw. per Tippfehler gleich)
        $this->assertNull(FuzzySearch::match('Bauer', ['Erreichbar per E-Mail']), '„per“ klingt wie „Bauer“');
        $this->assertNull(FuzzySearch::match('Maier', ['nur vormittags']), '„nur“ klingt wie „Maier“');
        $this->assertNull(FuzzySearch::match('Haas', ['Support aus Kiel']), '„aus“ klingt wie „Haas“');
        $this->assertNull(FuzzySearch::match('desk', ['Leiter des Labors']), '„des“ ist kein Tippfehler von „desk“');
        $this->assertNull(FuzzySearch::match('Maier', ['mehr Infos']), 'Kurzer Phonetikcode nur bei ähnlicher Schreibweise');
        $this->assertSame(0, FuzzySearch::tolerance(3));
        $this->assertSame(1, FuzzySearch::tolerance(5));
        $this->assertSame(2, FuzzySearch::tolerance(9));
    }

    public function testValidateRequiresNamesAndOneWayToReach(): void
    {
        $errors = $this->errors([]);
        $this->assertSame(['company', 'first_name', 'last_name', 'reach'], array_keys($errors));
        $this->assertStringContains('Rufnummer oder eine E-Mail-Adresse', $errors['reach']);

        foreach ([['phone' => '0531 123456'], ['mobile' => '+49 171 1234567'], ['email' => 'a@b.de']] as $reach) {
            [$data] = HelpdeskContactService::validate(['company' => ' RPDoc ', 'first_name' => 'Max', 'last_name' => 'Mustermann'] + $reach);
            $this->assertSame('RPDoc', $data['company']);
        }

        $base = ['company' => 'X', 'first_name' => 'A', 'last_name' => 'B'];
        $this->assertSame(['email'], array_keys($this->errors($base + ['email' => 'kein-mail'])));
        $this->assertSame(['phone'], array_keys($this->errors($base + ['phone' => 'abc', 'email' => 'a@b.de'])));
        $this->assertSame(['mobile'], array_keys($this->errors($base + ['mobile' => '12', 'email' => 'a@b.de'])), 'Mindestens drei Ziffern');
        $this->assertSame(['company'], array_keys($this->errors(['company' => str_repeat('x', 201)] + $base + ['email' => 'a@b.de'])));
    }

    public function testParseTagsNormalizesAndDeduplicates(): void
    {
        $this->assertSame(['Drucker', 'Hotline', 'Wartung Vertrag'], HelpdeskContactService::parseTags(' Drucker, #Hotline;  drucker ,, Wartung   Vertrag'));
        $this->assertSame([], HelpdeskContactService::parseTags(''));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => HelpdeskContactService::parseTags(str_repeat('x', 51)));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => HelpdeskContactService::parseTags(implode(',', range(1, 21))));
        $this->assertSame(['tags'], array_keys($this->errors(['company' => 'X', 'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.de', 'tags' => str_repeat('x', 60)])));
    }

    public function testFilterKeepsOrderMarksSimilarAndFiltersByTag(): void
    {
        $contacts = [
            ['id' => 1, 'company' => 'Agfa', 'first_name' => 'Anna', 'last_name' => 'Alt', 'email' => 'anna@agfa.de', 'tags' => ['PACS']],
            ['id' => 2, 'company' => 'RPDoc', 'first_name' => 'Max', 'last_name' => 'Mustermann', 'phone' => '0531 12345', 'tags' => ['Hotline', 'Röntgen']],
            ['id' => 3, 'company' => 'RPDoc', 'first_name' => 'Erika', 'last_name' => 'Muster', 'email' => 'e@rpdoc.de', 'tags' => []],
            ['id' => 4, 'company' => 'Siemens', 'first_name' => 'Sven', 'last_name' => 'Sommer', 'phone' => '0800 1', 'tags' => ['hotline']],
        ];

        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => $r['id'], $rows);
        $this->assertSame([1, 2, 3, 4], $ids(HelpdeskContactService::filter($contacts, '')));

        $found = HelpdeskContactService::filter($contacts, 'rpdok');
        $this->assertSame([2, 3], $ids($found));
        $this->assertSame(FuzzySearch::SIMILAR, $found[0]['match']);
        $this->assertSame([2, 3], $ids(HelpdeskContactService::filter($contacts, 'rp doc')));

        $this->assertSame([2, 4], $ids(HelpdeskContactService::filter($contacts, '', 'HOTLINE')), 'Stichwortfilter ohne Groß-/Kleinschreibung');
        $this->assertSame([2], $ids(HelpdeskContactService::filter($contacts, 'rpdoc', 'hotline')));
        $this->assertSame([1], $ids(HelpdeskContactService::filter($contacts, 'pacs')), 'Stichworte sind durchsuchbar');
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    private function errors(array $input): array
    {
        try {
            HelpdeskContactService::validate($input);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
