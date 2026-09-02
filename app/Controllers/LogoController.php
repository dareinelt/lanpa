<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Liefert das administrativ hinterlegte Logo aus dem nicht-oeffentlichen Upload-Verzeichnis aus.
 */
final class LogoController extends Controller
{
    public function show(Request $request): Response
    {
        $logo = Container::logo()->current();
        if ($logo === null) {
            throw new HttpException(404, 'Kein Logo hinterlegt.');
        }

        $contents = file_get_contents($logo['path']);
        if ($contents === false) {
            throw new HttpException(404, 'Kein Logo hinterlegt.');
        }

        $etag = '"' . md5($contents) . '"';
        if (($request->server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return (new Response('', 304))->withHeader('ETag', $etag);
        }

        return (new Response($contents, 200))
            ->withHeader('Content-Type', $logo['mime'])
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('ETag', $etag)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
}
