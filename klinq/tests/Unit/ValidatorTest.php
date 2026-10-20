<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Support\Validator;
use Tests\Support\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredStringAndTrimming(): void
    {
        $data = (new Validator(['name' => '  Lenovo  ']))->string('name', 'Name', true, 50)->validated();
        $this->assertSame('Lenovo', $data['name']);

        $this->assertThrows(ValidationException::class, static function (): void {
            (new Validator(['name' => '   ']))->string('name', 'Name', true)->validated();
        });
    }

    public function testMaxLengthIsEnforced(): void
    {
        $this->assertThrows(ValidationException::class, static function (): void {
            (new Validator(['code' => str_repeat('x', 11)]))->string('code', 'Code', false, 10)->validated();
        });
    }

    public function testOptionalEmptyValuesBecomeNull(): void
    {
        $data = (new Validator(['website' => '', 'location_id' => '']))
            ->url('website', 'Webseite')
            ->id('location_id', 'Standort')
            ->validated();
        $this->assertNull($data['website']);
        $this->assertNull($data['location_id']);
    }

    public function testIdAndIntRanges(): void
    {
        $data = (new Validator(['location_id' => '42', 'sort_order' => '-5']))
            ->id('location_id', 'Standort')
            ->int('sort_order', 'Sortierung', false, -9999, 9999)
            ->validated();
        $this->assertSame(42, $data['location_id']);
        $this->assertSame(-5, $data['sort_order']);

        $this->assertThrows(ValidationException::class, static function (): void {
            (new Validator(['location_id' => '0']))->id('location_id', 'Standort')->validated();
        });
        $this->assertThrows(ValidationException::class, static function (): void {
            (new Validator(['location_id' => 'abc']))->id('location_id', 'Standort')->validated();
        });
    }

    public function testEmailUrlPatternAndIn(): void
    {
        $data = (new Validator([
            'email' => 'Max.Mustermann@Example.com',
            'website' => 'www.example.com',
            'number' => '12345',
            'type' => 'room',
        ]))
            ->email('email', 'E-Mail')
            ->url('website', 'Webseite')
            ->pattern('number', 'Nummer', '/^\d{5}$/', 'Fünf Ziffern')
            ->in('type', 'Typ', ['site', 'room'])
            ->validated();
        $this->assertSame('Max.Mustermann@Example.com', $data['email']);
        $this->assertSame('https://www.example.com', $data['website']);
        $this->assertSame('12345', $data['number']);
        $this->assertSame('room', $data['type']);

        foreach ([
            ['email' => 'keine-mail'],
            ['number' => '1234'],
            ['type' => 'planet'],
        ] as $bad) {
            $this->assertThrows(ValidationException::class, static function () use ($bad): void {
                (new Validator($bad))
                    ->email('email', 'E-Mail')
                    ->pattern('number', 'Nummer', '/^\d{5}$/', 'Fünf Ziffern')
                    ->in('type', 'Typ', ['site', 'room'])
                    ->validated();
            });
        }
    }

    public function testBoolAndDate(): void
    {
        $data = (new Validator(['is_active' => '1', 'bought' => '24.12.2025', 'iso' => '2025-01-31']))
            ->bool('is_active')->date('bought', 'Kaufdatum')->date('iso', 'ISO')->validated();
        $this->assertSame(1, $data['is_active']);
        $this->assertSame('2025-12-24', $data['bought']);
        $this->assertSame('2025-01-31', $data['iso']);

        $data = (new Validator([]))->bool('is_active')->validated();
        $this->assertSame(0, $data['is_active']);

        $this->assertThrows(ValidationException::class, static function (): void {
            (new Validator(['d' => '31.02.2025']))->date('d', 'Datum')->validated();
        });
    }

    public function testCollectsAllErrors(): void
    {
        try {
            (new Validator([]))->string('a', 'A', true)->string('b', 'B', true)->validated();
            $this->assertTrue(false, 'Exception erwartet');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->errors());
            $this->assertTrue(isset($e->errors()['a']) && isset($e->errors()['b']));
        }
    }

    public function testDecimalAcceptsGermanEnglishAndTechnicalFormats(): void
    {
        $this->assertSame('1299.00', Validator::normalizeDecimal('1.299,00'));
        $this->assertSame('1299.00', Validator::normalizeDecimal('1299.00'));
        $this->assertSame('1299.50', Validator::normalizeDecimal('1,299.50'));
        $this->assertSame('1299', Validator::normalizeDecimal('1.299'));
        $this->assertSame('749.90', Validator::normalizeDecimal('749,90 €'));
        $this->assertSame('12.5', Validator::normalizeDecimal('12.5'));
        $this->assertSame('1234567.89', Validator::normalizeDecimal('1.234.567,89'));
        $this->assertNull(Validator::normalizeDecimal('abc'));

        $data = (new Validator(['p' => '1.299,00']))->decimal('p', 'Preis', false, 0.0)->validated();
        $this->assertSame(1299.0, $data['p']);
        $this->assertThrows(ValidationException::class, static function (): void {
            (new Validator(['p' => '-5']))->decimal('p', 'Preis', false, 0.0)->validated();
        });
    }
}
