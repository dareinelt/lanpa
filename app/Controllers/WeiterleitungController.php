<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use Throwable;

/**
 * Oeffentliche Endpunkte rund um den Weiterleitungs-Proxy (Adressraum
 * /weiterleitung/<id>/, siehe docs/weiterleitung.md).
 *
 *   GET /weiterleitung-nicht-verfuegbar  Hinweisseite (Fehlerseite des Proxys)
 */
final class WeiterleitungController extends Controller
{
    public function unavailable(Request $request): Response
    {
        $response = null;

        try {
            $response = $this->view('weiterleitung.unavailable', [
                'pageTitle' => 'Anwendung nicht erreichbar',
                'activeNav' => '',
            ], 'layouts.minimal', 503);
        } catch (Throwable) {
            // Ohne Datenbank: statische Fassung.
            $response = Response::html(
                '<!doctype html><html lang="de"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Anwendung nicht erreichbar</title></head><body style="font-family:system-ui,sans-serif;padding:2rem">'
                . '<h1>Die Anwendung ist derzeit nicht erreichbar</h1>'
                . '<p>Die aufgerufene Anwendung antwortet nicht oder die Weiterleitung ist noch nicht eingerichtet.</p>'
                . '<p><a href="/">Zur Startseite</a></p></body></html>',
                503
            );
        }

        return $response
            ->withHeader('Retry-After', '60')
            ->withHeader('Cache-Control', 'no-store');
    }
}
