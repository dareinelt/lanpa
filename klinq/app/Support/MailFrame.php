<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Response;

/**
 * Isolierte Anzeige eingehender HTML-E-Mails. Die Nachricht wird als eigenes Dokument
 * ausgeliefert und in der Ticketansicht per `<iframe sandbox>` eingebettet. So wirken
 * weder die Styles der Anwendung auf die Mail noch die Styles der Mail auf die Anwendung,
 * und die Mail behält ihr ursprüngliches Layout (eigene `<style>`-Blöcke, Inline-Styles).
 */
final class MailFrame
{
    /** Sandbox-Rechte des Rahmens: keine Skripte, keine Formulare; Links öffnen in neuem Tab. */
    public const SANDBOX = 'allow-same-origin allow-popups allow-popups-to-escape-sandbox';

    /**
     * CSP des Mail-Dokuments: nur eingebettete Styles und Bilder (eigener Ursprung, data:),
     * keine Skripte, keine Fremdverweise (auch keine Tracking-Pixel), einbettbar nur in der Anwendung.
     */
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox " . self::SANDBOX;

    /** Vollständiges HTML-Dokument für den Anzeigerahmen. */
    public static function document(string $html): string
    {
        $body = HtmlSanitizer::mailDocument($html, 0);

        return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer"><base target="_blank">'
            . '<style>html{color-scheme:light}body{margin:0;padding:0;background:transparent;color:#1f2933;'
            . 'font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;overflow-wrap:break-word}'
            . 'img{max-width:100%;height:auto}'
            . 'details.' . MailHistory::CSS_CLASS . '{margin:12px 0;padding:6px 10px;border:1px solid #d9dee3;border-radius:6px}'
            . 'details.' . MailHistory::CSS_CLASS . '>summary{cursor:pointer;color:#52606d;font-weight:600}'
            . 'details.' . MailHistory::CSS_CLASS . '[open]>summary{margin-bottom:8px}</style>'
            . '</head><body>' . $body . '</body></html>';
    }

    /** HTTP-Antwort mit dem Mail-Dokument und der strengen CSP des Rahmens. */
    public static function response(string $html): Response
    {
        return Response::html(self::document($html))
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
