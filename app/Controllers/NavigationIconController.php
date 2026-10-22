<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\NavigationIconService;

/**
 * Liefert ein Katalog-Icon aus dem nicht-oeffentlichen Upload-Verzeichnis aus.
 */
final class NavigationIconController extends Controller
{
    public function show(Request $request): Response
    {
        $alias = (string) $request->query('alias', '');
        if (preg_match(NavigationIconService::ALIAS_PATTERN, $alias) !== 1) {
            throw new HttpException(404, 'Icon nicht gefunden.');
        }

        $file = Container::navigationIcons()->fileByAlias($alias);
        if ($file === null) {
            throw new HttpException(404, 'Icon nicht gefunden.');
        }

        $contents = file_get_contents($file['path']);
        if ($contents === false) {
            throw new HttpException(404, 'Icon nicht gefunden.');
        }

        $etag = '"' . md5($contents) . '"';
        if (($request->server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return (new Response('', 304))->withHeader('ETag', $etag);
        }

        return (new Response($contents, 200))
            ->withHeader('Content-Type', $file['mime'])
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('ETag', $etag)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
}
