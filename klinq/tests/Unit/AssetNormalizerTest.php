<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AssetService;
use Tests\Support\TestCase;

final class AssetNormalizerTest extends TestCase
{
    public function testSerialNumbersAreNormalisedForDuplicateChecks(): void
    {
        $this->assertSame('PF3AB12X', AssetService::normalizeSerial('pf-3ab 12x'));
        $this->assertSame('PF3AB12X', AssetService::normalizeSerial('PF3AB12X'));
        $this->assertSame('SN00123', AssetService::normalizeSerial(' sn_001.23/ '));
        $this->assertNull(AssetService::normalizeSerial('   '));
    }

    public function testMacAddressesAcceptCommonNotations(): void
    {
        $this->assertSame('A1:B2:C3:D4:E5:F6', AssetService::normalizeMac('a1:b2:c3:d4:e5:f6'));
        $this->assertSame('A1:B2:C3:D4:E5:F6', AssetService::normalizeMac('a1-b2-c3-d4-e5-f6'));
        $this->assertSame('A1:B2:C3:D4:E5:F6', AssetService::normalizeMac('a1b2.c3d4.e5f6'));
        $this->assertSame('A1:B2:C3:D4:E5:F6', AssetService::normalizeMac('A1B2C3D4E5F6'));
        $this->assertNull(AssetService::normalizeMac('A1:B2:C3'));
        $this->assertNull(AssetService::normalizeMac('ZZ:B2:C3:D4:E5:F6'));
    }

    public function testImeiAcceptsFourteenToSixteenDigits(): void
    {
        $this->assertSame('352099001761481', AssetService::normalizeImei('35 209900 176148 1'));
        $this->assertSame('35209900176148', AssetService::normalizeImei('35209900176148'));
        $this->assertSame('3520990017614812', AssetService::normalizeImei('3520990017614812'));
        $this->assertNull(AssetService::normalizeImei('12345'));
        $this->assertNull(AssetService::normalizeImei('35209900176148123'));
    }
}
