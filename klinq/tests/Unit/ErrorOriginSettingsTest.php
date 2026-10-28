<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Helpdesk\ErrorOriginSettingsService;
use Tests\Support\TestCase;

/**
 * Katalog der Fehlerursprünge: Die Liste wird zeilenweise aus dem Textfeld gelesen,
 * leere Zeilen und Duplikate entfallen.
 */
final class ErrorOriginSettingsTest extends TestCase
{
    public function testDefaultsMatchRequirement(): void
    {
        $this->assertSame(['Anwender', 'Hardware-Fehler', 'Software-Fehler', 'Netzwerk'], ErrorOriginSettingsService::DEFAULT_ORIGINS);
    }

    public function testParseTrimsAndDropsEmptyAndDuplicateLines(): void
    {
        $raw = "  Anwender \r\n\r\nHardware-Fehler\nAnwender\n\n  Netzwerk  \n";

        $this->assertSame(['Anwender', 'Hardware-Fehler', 'Netzwerk'], ErrorOriginSettingsService::parse($raw));
        $this->assertSame([], ErrorOriginSettingsService::parse("  \n \n"));
    }
}
