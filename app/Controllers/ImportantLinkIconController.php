<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Liefert das automatisch ermittelte Favicon eines "Wichtigen Links" aus
 * dem nicht-oeffentlichen Upload-Verzeichnis aus.
 */
final class ImportantLinkIconController extends Controller
{
    private const MIME_MAP = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];

    public function show(Request $request): Response
    {
        $id = $request->queryInt('id', 0);
        $item = Container::importantLinks()->find($id);

        $iconFile = is_array($item) ? (string) ($item['icon_file'] ?? '') : '';
        if ($iconFile === '') {
            throw new HttpException(404, 'Kein Icon hinterlegt.');
        }

        $path = Container::favicons()->iconPath($iconFile);
        if ($path === null) {
            throw new HttpException(404, 'Kein Icon hinterlegt.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new HttpException(404, 'Kein Icon hinterlegt.');
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_MAP[$extension] ?? 'application/octet-stream';

        $etag = '"' . md5($contents) . '"';
        if (($request->server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            return (new Response('', 304))->withHeader('ETag', $etag);
        }

        return (new Response($contents, 200))
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=86400')
            ->withHeader('ETag', $etag)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
}
