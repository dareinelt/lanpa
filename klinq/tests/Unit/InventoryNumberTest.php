<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InventoryNumberService;
use Tests\Support\TestCase;

final class InventoryNumberTest extends TestCase
{
    public function testFormatPadsToThreeDigitsAndGrowsBeyond(): void
    {
        $this->assertSame('PC26001', InventoryNumberService::format('PC', '26', 1));
        $this->assertSame('NET88042', InventoryNumberService::format('NET', '88', 42));
        $this->assertSame('PC26999', InventoryNumberService::format('PC', '26', 999));
        $this->assertSame('PC261000', InventoryNumberService::format('PC', '26', 1000));
    }

    public function testValidFormat(): void
    {
        $this->assertTrue(InventoryNumberService::isValid('PC26001'));
        $this->assertTrue(InventoryNumberService::isValid('NET88010'));
        $this->assertTrue(InventoryNumberService::isValid('ZUB261234'));
        $this->assertFalse(InventoryNumberService::isValid('pc26001'));
        $this->assertFalse(InventoryNumberService::isValid('PC2601'));
        $this->assertFalse(InventoryNumberService::isValid('26001'));
        $this->assertFalse(InventoryNumberService::isValid('PC-26-001'));
        $this->assertFalse(InventoryNumberService::isValid('TOOLONGX26001'));
    }

    public function testPrefixIsNormalised(): void
    {
        $this->assertSame('PC', InventoryNumberService::normalizePrefix(' pc '));
        $this->assertSame('NET', InventoryNumberService::normalizePrefix('n-e-t'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => InventoryNumberService::normalizePrefix('123'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => InventoryNumberService::normalizePrefix('ABCDEFG'));
    }
}
