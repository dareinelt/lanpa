<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MailFrame;
use Tests\Support\TestCase;

/**
 * Isolierter Anzeigerahmen für eingehende HTML-E-Mails: eigenes Dokument mit
 * erhaltenen Stylesheets und einer CSP ohne Skripte und Fremdverweise.
 */
final class MailFrameTest extends TestCase
{
    public function testDocumentKeepsMailLayoutAndOpensLinksInNewTab(): void
    {
        $doc = MailFrame::document('<html><head><style>td{padding:4px}</style></head><body><table><tr><td style="width:50%">A</td></tr></table></body></html>');

        $this->assertStringContains('<!DOCTYPE html>', $doc);
        $this->assertStringContains('<base target="_blank">', $doc);
        $this->assertStringContains('<style>td{padding:4px}</style>', $doc);
        $this->assertStringContains('style="width:50%"', $doc);
    }

    public function testDocumentRemovesScripts(): void
    {
        $doc = MailFrame::document('<p onclick="x()">Text</p><script>alert(1)</script>');

        $this->assertFalse(str_contains($doc, 'alert'));
        $this->assertFalse(str_contains($doc, 'onclick'));
    }

    public function testResponseIsSandboxedAndFramableOnlyBySameOrigin(): void
    {
        $headers = MailFrame::response('<p>Text</p>')->headers();
        $csp = $headers['Content-Security-Policy'] ?? '';

        $this->assertStringContains("default-src 'none'", $csp);
        $this->assertStringContains("frame-ancestors 'self'", $csp);
        $this->assertStringContains('sandbox allow-same-origin', $csp);
        $this->assertFalse(str_contains($csp, 'script-src'), 'Keine Skriptquelle erlaubt');
        $this->assertFalse(str_contains($csp, 'allow-scripts'), 'Sandbox ohne Skripte');
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
    }
}
