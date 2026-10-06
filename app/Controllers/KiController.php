<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Oeffentliche Endpunkte rund um LLMInt (KI-Oberflaeche unter /ki/, eigener
 * Stack hinter dem auth-Proxy, siehe docs/llmint.md).
 *
 *   GET /ki-nicht-verfuegbar  Hinweisseite (Fehlerseite des auth-Proxys)
 */
final class KiController extends Controller
{
    public function unavailable(Request $request): Response
    {
        $response = null;

        try {
            $response = $this->view('ki.unavailable', [
                'pageTitle' => 'KI nicht verfügbar',
                'activeNav' => '',
            ], 'layouts.minimal', 503);
        } catch (Throwable) {
            // Ohne Datenbank: statische Fassung.
            $response = Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>KI nicht verfügbar</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
                . '<h1>Die KI-Oberfläche ist derzeit nicht verfügbar</h1>'
                . '<p>Bitte versuchen Sie es in einigen Minuten erneut.</p>'
                . '<p><a href="/">Zur Startseite</a></p></body></html>',
                503
            );
        }

        return $response
            ->withHeader('Retry-After', '60')
            ->withHeader('Cache-Control', 'no-store');
    }
}
