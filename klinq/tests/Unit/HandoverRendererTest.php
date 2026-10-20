<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HandoverRenderer;
use App\Services\HandoverService;
use Tests\Support\TestCase;

final class HandoverRendererTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function blocks(): array
    {
        return [
            ['type' => 'heading', 'text' => 'Übergabeprotokoll {protokollnummer}', 'level' => 1],
            ['type' => 'meta', 'fields' => ['protocol_number', 'version', 'company']],
            ['type' => 'employee', 'title' => 'Mitarbeiter', 'fields' => ['display_name', 'personnel_number', 'cost_center']],
            ['type' => 'assets', 'title' => 'Arbeitsmittel', 'columns' => ['inventory_number', 'article', 'serial_number'], 'empty_text' => 'Nichts da.'],
            ['type' => 'text', 'text' => "Hallo {vorname} <b>x</b>\nZeile 2", 'style' => 'small'],
            ['type' => 'confirmation', 'text' => 'Erhalten', 'required' => true],
            ['type' => 'confirmation', 'text' => 'Optional gelesen', 'required' => false],
            ['type' => 'signature', 'party' => 'employee', 'label' => 'Unterschrift {mitarbeiter}'],
            ['type' => 'spacer', 'height' => 20],
        ];
    }

    /** @return array<string,mixed> */
    private function context(array $overrides = []): array
    {
        return array_replace([
            'protocol' => ['protocol_number' => 'UP-4711-02', 'version' => 2, 'signed_at' => '2026-03-04 10:30:00', 'issuer_name' => 'Admin', 'status' => 'signed'],
            'employee' => ['display_name' => 'Erika <Muster>', 'first_name' => 'Erika', 'personnel_number' => '4711', 'cost_center_number' => '100', 'cost_center_name' => 'IT'],
            'items' => [
                ['id' => 5, 'inventory_number' => 'IT-000005', 'article_name' => 'ThinkPad', 'manufacturer_name' => 'Lenovo', 'serial_number' => 'S&N'],
            ],
            'company' => 'Musterfirma & Co.',
            'signature' => null,
            'interactive' => false,
        ], $overrides);
    }

    public function testRendersAllBlocksEscapedAndWithPlaceholders(): void
    {
        $html = HandoverRenderer::render($this->blocks(), $this->context());

        $this->assertStringContains('<h1 class="hp-heading">Übergabeprotokoll UP-4711-02</h1>', $html);
        $this->assertStringContains('Musterfirma &amp; Co.', $html);
        $this->assertStringContains('Erika &lt;Muster&gt;', $html);
        $this->assertStringContains('<th>Kostenstelle</th><td>100 IT</td>', $html);
        $this->assertStringContains('IT-000005', $html);
        $this->assertStringContains('Lenovo ThinkPad', $html);
        $this->assertStringContains('S&amp;N', $html);
        $this->assertStringContains('Hallo Erika &lt;b&gt;x&lt;/b&gt;<br />', $html);
        $this->assertStringContains('hp-spacer-24', $html);
        $this->assertStringContains('Unterschrift Erika &lt;Muster&gt;', $html);
        $this->assertStringContains('04.03.2026 10:30', $html);
        $this->assertFalse(str_contains($html, 'style='), 'Kein Inline-Style (CSP)');
    }

    public function testEmptyAssetsShowEmptyText(): void
    {
        $html = HandoverRenderer::render($this->blocks(), $this->context(['items' => []]));
        $this->assertStringContains('Nichts da.', $html);
        $this->assertFalse(str_contains($html, '<table class="hp-table">'));
    }

    public function testInteractiveModeRendersFormControls(): void
    {
        $html = HandoverRenderer::render($this->blocks(), $this->context(['interactive' => true, 'protocol' => ['protocol_number' => 'UP-1', 'version' => 1, 'status' => 'draft']]));
        $this->assertStringContains('<canvas id="signature-pad"', $html);
        $this->assertStringContains('name="confirmations[]" value="0" required data-required-confirmation', $html);
        $this->assertStringContains('name="confirmations[]" value="1">', $html);
    }

    public function testStaticModeShowsSignatureImageAndCheckedBoxes(): void
    {
        $html = HandoverRenderer::render($this->blocks(), $this->context(['signature' => 'data:image/png;base64,AAAA']));
        $this->assertStringContains('<img class="hp-signature-image" src="data:image/png;base64,AAAA"', $html);
        $this->assertStringContains('hp-checkbox is-checked', $html);
    }

    public function testRequiredConfirmationsIndexes(): void
    {
        $this->assertSame([0], HandoverRenderer::requiredConfirmations($this->blocks()));
    }

    public function testNormalizeBlocksDropsUnknownAndClampsValues(): void
    {
        $blocks = HandoverRenderer::normalizeBlocks(json_encode([
            ['type' => 'heading', 'text' => str_repeat('x', 300), 'level' => 9],
            ['type' => 'unknown', 'text' => 'x'],
            ['type' => 'assets', 'columns' => ['inventory_number', 'bogus']],
            ['type' => 'employee', 'fields' => 'display_name,email,nope'],
            ['type' => 'confirmation', 'text' => 'ok', 'required' => 'false'],
            ['type' => 'signature', 'party' => 'someone'],
            ['type' => 'spacer', 'height' => 500],
        ]));

        $this->assertCount(6, $blocks);
        $this->assertSame(200, mb_strlen($blocks[0]['text']));
        $this->assertSame(2, $blocks[0]['level']);
        $this->assertSame(['inventory_number'], $blocks[1]['columns']);
        $this->assertSame(['display_name', 'email'], $blocks[2]['fields']);
        $this->assertFalse($blocks[3]['required']);
        $this->assertSame('employee', $blocks[4]['party']);
        $this->assertSame(80, $blocks[5]['height']);
    }

    public function testNormalizeBlocksRejectsEmptyOrInvalid(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, static fn () => HandoverRenderer::normalizeBlocks('nicht json'));
        $this->assertThrows(\InvalidArgumentException::class, static fn () => HandoverRenderer::normalizeBlocks([['type' => 'foo']]));
    }

    public function testRenderDocumentIsStandaloneHtml(): void
    {
        $html = HandoverRenderer::renderDocument($this->blocks(), $this->context());
        $this->assertStringContains('<!DOCTYPE html>', $html);
        $this->assertStringContains('<title>Übergabeprotokoll UP-4711-02</title>', $html);
        $this->assertStringContains('@page', $html);
    }

    public function testFingerprintIsOrderIndependent(): void
    {
        $a = HandoverService::fingerprint([['id' => 3], ['id' => 1], ['id' => 2]]);
        $b = HandoverService::fingerprint([['id' => '1'], ['id' => 2], ['id' => 3]]);
        $this->assertSame($a, $b);
        $this->assertFalse($a === HandoverService::fingerprint([['id' => 1], ['id' => 2]]));
        $this->assertSame(64, strlen($a));
    }

    public function testStateTransitions(): void
    {
        $fp = HandoverService::fingerprint([['id' => 1]]);
        $this->assertSame('none', HandoverService::state(false, null, null, $fp));
        $this->assertSame('missing', HandoverService::state(true, null, null, $fp));
        $this->assertSame('draft', HandoverService::state(true, null, ['id' => 9], $fp));
        $this->assertSame('ok', HandoverService::state(true, ['asset_fingerprint' => $fp], null, $fp));
        $this->assertSame('outdated', HandoverService::state(true, ['asset_fingerprint' => 'other'], null, $fp));
    }

    public function testDecodeSignatureValidatesPng(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\0", 300);
        $decoded = HandoverService::decodeSignature('data:image/png;base64,' . base64_encode($png));
        $this->assertSame($png, $decoded);

        $this->assertThrows(\App\Exceptions\ValidationException::class, static fn () => HandoverService::decodeSignature(''));
        $this->assertThrows(\App\Exceptions\ValidationException::class, static fn () => HandoverService::decodeSignature('data:image/jpeg;base64,AAAA'));
        $this->assertThrows(\App\Exceptions\ValidationException::class, static fn () => HandoverService::decodeSignature('data:image/png;base64,' . base64_encode('kurz')));
    }
}
