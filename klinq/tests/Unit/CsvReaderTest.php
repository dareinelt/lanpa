<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ImportService;
use App\Support\CsvReader;
use Tests\Support\TestCase;

final class CsvReaderTest extends TestCase
{
    public function testParsesSemicolonCsvWithHeaderAndBlankLines(): void
    {
        $parsed = CsvReader::parse("Inventarnummer;Bezeichnung\n\nPC26001;Notebook\n  \nMD26001;Handy\n");

        $this->assertSame(';', $parsed['delimiter']);
        $this->assertSame('UTF-8', $parsed['encoding']);
        $this->assertSame(['Inventarnummer', 'Bezeichnung'], $parsed['headers']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame(3, $parsed['rows'][0]['line']);
        $this->assertSame('PC26001', $parsed['rows'][0]['cells']['inventarnummer']);
        $this->assertSame('Handy', $parsed['rows'][1]['cells']['bezeichnung']);
        $this->assertSame(0, $parsed['skipped']);
    }

    public function testStripsBomAndDetectsCommaDelimiter(): void
    {
        $parsed = CsvReader::parse("\xEF\xBB\xBFAssettyp,Bezeichnung,Kaufpreis\nPC,\"Gerät, groß\",\"1.234,56\"\n");

        $this->assertSame(',', $parsed['delimiter']);
        $this->assertSame('UTF-8 (BOM)', $parsed['encoding']);
        $this->assertSame('Assettyp', $parsed['headers'][0]);
        $this->assertSame('Gerät, groß', $parsed['rows'][0]['cells']['bezeichnung']);
        $this->assertSame('1.234,56', $parsed['rows'][0]['cells']['kaufpreis']);
    }

    public function testConvertsWindows1252(): void
    {
        $content = mb_convert_encoding("Bezeichnung;Standort\nGerät;Gebäude A\n", 'Windows-1252', 'UTF-8');
        $parsed = CsvReader::parse($content);

        $this->assertSame('Windows-1252', $parsed['encoding']);
        $this->assertSame('Gerät', $parsed['rows'][0]['cells']['bezeichnung']);
        $this->assertSame('Gebäude A', $parsed['rows'][0]['cells']['standort']);
    }

    public function testDetectsTabAndPipeAndHonoursExplicitDelimiter(): void
    {
        $this->assertSame("\t", CsvReader::parse("a\tb\n1\t2\n")['delimiter']);
        $this->assertSame('|', CsvReader::parse("a|b\n1|2\n")['delimiter']);
        $this->assertSame(';', CsvReader::parse("a,b\n1,2\n", ';')['delimiter']);
        $this->assertSame(';', CsvReader::parse("a;b\n1;2\n", '#')['delimiter']);
    }

    public function testHandlesCrLfAndCrLineEndings(): void
    {
        $this->assertCount(2, CsvReader::parse("a;b\r\n1;2\r\n3;4\r\n")['rows']);
        $this->assertCount(2, CsvReader::parse("a;b\r1;2\r3;4")['rows']);
    }

    public function testMaxRowsCountsSkippedRows(): void
    {
        $parsed = CsvReader::parse("a;b\n1;2\n3;4\n5;6\n", null, 2);

        $this->assertCount(2, $parsed['rows']);
        $this->assertSame(1, $parsed['skipped']);
    }

    public function testNormalizeHeader(): void
    {
        $this->assertSame('mac_adresse', CsvReader::normalizeHeader(' MAC-Adresse '));
        $this->assertSame('garantie_bis', CsvReader::normalizeHeader('Garantie bis'));
        $this->assertSame('grosse_uebersicht', CsvReader::normalizeHeader('Große Übersicht'));
        $this->assertSame('seriennr', CsvReader::normalizeHeader('Seriennr.'));
        $this->assertSame('', CsvReader::normalizeHeader('###'));
    }

    public function testDelimiterLabel(): void
    {
        $this->assertSame('Semikolon', CsvReader::delimiterLabel(';'));
        $this->assertSame('Komma', CsvReader::delimiterLabel(','));
        $this->assertSame('Tabulator', CsvReader::delimiterLabel("\t"));
    }

    public function testMapColumnsUsesLabelsAndAliases(): void
    {
        $mapping = ImportService::mapColumns(['Inventar-Nr.', 'Typ', 'Name', 'Hersteller', 'Seriennr.', 'MAC', 'Unbekannt', 'Kaufdatum']);

        $this->assertSame('inventar_nr', $mapping['inventory_number'] ?? null);
        $this->assertSame('typ', $mapping['asset_type'] ?? null);
        $this->assertSame('name', $mapping['name'] ?? null);
        $this->assertSame('hersteller', $mapping['manufacturer'] ?? null);
        $this->assertSame('seriennr', $mapping['serial_number'] ?? null);
        $this->assertSame('mac', $mapping['mac_address'] ?? null);
        $this->assertSame('kaufdatum', $mapping['purchase_date'] ?? null);
        $this->assertFalse(in_array('unbekannt', $mapping, true));
    }

    public function testMapColumnsMapsEveryTemplateHeader(): void
    {
        $headers = array_map(static fn (array $c): string => $c[0], ImportService::COLUMNS);
        $mapping = ImportService::mapColumns(array_values($headers));

        $this->assertCount(count(ImportService::COLUMNS), $mapping);
        foreach (array_keys(ImportService::COLUMNS) as $key) {
            $this->assertTrue(isset($mapping[$key]), "Spalte {$key} nicht zugeordnet");
        }
    }

    public function testTemplateCsvRoundTrip(): void
    {
        $parsed = CsvReader::parse(ImportService::templateCsv());

        $this->assertSame('UTF-8 (BOM)', $parsed['encoding']);
        $this->assertCount(count(ImportService::COLUMNS), $parsed['headers']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame('MD', $parsed['rows'][1]['cells']['assettyp']);
        $this->assertCount(count(ImportService::COLUMNS), $parsed['rows'][1]['cells']);
    }

    public function testParseBool(): void
    {
        $this->assertTrue(ImportService::parseBool('Ja'));
        $this->assertTrue(ImportService::parseBool(' x '));
        $this->assertTrue(ImportService::parseBool('1'));
        $this->assertFalse(ImportService::parseBool('nein'));
        $this->assertFalse(ImportService::parseBool(''));
        $this->assertNull(ImportService::parseBool('vielleicht'));
    }

    public function testCounts(): void
    {
        $counts = ImportService::counts([['status' => 'valid'], ['status' => 'error'], ['status' => 'valid'], ['status' => 'duplicate']]);

        $this->assertSame(2, $counts['valid']);
        $this->assertSame(1, $counts['error']);
        $this->assertSame(1, $counts['duplicate']);
        $this->assertSame(0, $counts['warning']);
    }
}
