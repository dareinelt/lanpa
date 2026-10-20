<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CsvWriter;
use Tests\Support\TestCase;

final class CsvWriterTest extends TestCase
{
    public function testBuildStartsWithBomAndUsesSemicolonAndCrlf(): void
    {
        $csv = CsvWriter::build(['A', 'B'], [[1, 'x'], [2, 'y']]);

        $this->assertTrue(str_starts_with($csv, "\xEF\xBB\xBF"));
        $this->assertSame("\xEF\xBB\xBFA;B\r\n1;x\r\n2;y\r\n", $csv);
    }

    public function testBuildAcceptsGeneratorsAndCustomDelimiter(): void
    {
        $rows = (static function (): \Generator {
            yield ['k' => 'a', 'v' => 'b'];
        })();
        $csv = CsvWriter::build(['K', 'V'], $rows, ',');
        $this->assertSame("\xEF\xBB\xBFK,V\r\na,b\r\n", $csv);
    }

    public function testCellFormatsScalars(): void
    {
        $this->assertSame('', CsvWriter::cell(null));
        $this->assertSame('', CsvWriter::cell(''));
        $this->assertSame('Ja', CsvWriter::cell(true));
        $this->assertSame('Nein', CsvWriter::cell(false));
        $this->assertSame('42', CsvWriter::cell(42));
        $this->assertSame('1299,50', CsvWriter::cell(1299.5));
        $this->assertSame('Text', CsvWriter::cell('Text'));
    }

    public function testCellQuotesDelimiterQuotesAndLineBreaks(): void
    {
        $this->assertSame('"a;b"', CsvWriter::cell('a;b'));
        $this->assertSame('"Zeile 1' . "\n" . 'Zeile 2"', CsvWriter::cell("Zeile 1\nZeile 2"));
        $this->assertSame('"Er sagte ""Hallo"""', CsvWriter::cell('Er sagte "Hallo"'));
        $this->assertSame('a,b', CsvWriter::cell('a,b'), 'Komma ist bei Semikolon-Trenner kein Sonderzeichen');
        $this->assertSame('"a,b"', CsvWriter::cell('a,b', ','));
    }

    public function testCellNeutralisesFormulaInjection(): void
    {
        $this->assertSame('"\'=SUM(A1:A3)"', CsvWriter::cell('=SUM(A1:A3)'));
        $this->assertSame('"\'+cmd|\' /C calc\'!A0"', CsvWriter::cell("+cmd|' /C calc'!A0"));
        $this->assertSame('"\'@import"', CsvWriter::cell('@import'));
        $this->assertSame('"\'-foo"', CsvWriter::cell('-foo'));
        $this->assertSame('"\'' . "\t" . 'tab"', CsvWriter::cell("\ttab"));
        // Negative Zahlen bleiben Zahlen
        $this->assertSame('-5', CsvWriter::cell('-5'));
        $this->assertSame('-12.5', CsvWriter::cell('-12.5'));
        $this->assertSame('"\'+49 5171 1234"', CsvWriter::cell('+49 5171 1234'), 'Telefonnummern mit + werden entschärft');
    }

    public function testFilenameIsSluggedAndDated(): void
    {
        $name = CsvWriter::filename('Inventarliste Büro/IT');
        $this->assertMatches('/^inventarliste-b-ro-it-\d{4}-\d{2}-\d{2}\.csv$/', $name);
        $this->assertMatches('/^export-\d{4}-\d{2}-\d{2}\.csv$/', CsvWriter::filename('###'));
    }

    public function testMimeType(): void
    {
        $this->assertSame('text/csv; charset=UTF-8', CsvWriter::MIME);
    }
}
