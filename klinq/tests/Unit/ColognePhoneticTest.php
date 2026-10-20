<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ColognePhonetic;
use Tests\Support\TestCase;

final class ColognePhoneticTest extends TestCase
{
    public function testKnownCodes(): void
    {
        // Referenzbeispiel aus der Definition der Kölner Phonetik
        $this->assertSame('65752682', ColognePhonetic::encodeWord('Müller-Lüdenscheidt'));
        $this->assertSame('17863', ColognePhonetic::encodeWord('Breschnew'));
        $this->assertSame('3412', ColognePhonetic::encodeWord('Wikipedia'));
    }

    public function testSpellingVariantsShareTheSameKey(): void
    {
        $reference = ColognePhonetic::encode('Hewlett Packard');
        $this->assertSame($reference, ColognePhonetic::encode('Hewlet Packard'));
        $this->assertSame($reference, ColognePhonetic::encode('Hewlett-Packard'));
        $this->assertSame($reference, ColognePhonetic::encode(ColognePhonetic::normalizedName('HEWLETT PACKARD GmbH')));
        $this->assertSame(ColognePhonetic::encode('Maier'), ColognePhonetic::encode('Meyer'));
    }

    public function testDifferentNamesProduceDifferentKeys(): void
    {
        $this->assertTrue(ColognePhonetic::encode('Lenovo') !== ColognePhonetic::encode('Dell'));
    }

    public function testNormalizedNameStripsLegalFormsAndPunctuation(): void
    {
        $this->assertSame('hewlett packard', ColognePhonetic::normalizedName('Hewlett-Packard GmbH & Co. KG'));
        $this->assertSame('hewlett packard', ColognePhonetic::normalizedName('  HEWLETT   Packard, Inc. '));
        $this->assertSame('mueller', ColognePhonetic::normalizedName('Müller AG'));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame('', ColognePhonetic::encode(''));
        $this->assertSame('', ColognePhonetic::encode('---'));
    }
}
