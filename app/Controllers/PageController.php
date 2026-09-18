<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Rendert oeffentliche Unterseiten und formatierte Textseiten.
 */
final class PageController extends Controller
{
    public function subpage(Request $request): Response
    {
        $item = $this->resolve($request, 'subpage');

        return $this->view('pages.subpage', [
            'pageTitle' => (string) $item['title'],
            'item' => $item,
            'items' => Container::navigation()->activeChildren((int) $item['id']),
            'breadcrumb' => Container::navigation()->breadcrumb((int) $item['id']),
            'descriptionMode' => Container::settings()->descriptionMode(),
            'activeNav' => '',
            'pageScript' => 'landing.js',
        ]);
    }

    public function page(Request $request): Response
    {
        $item = $this->resolve($request, 'page');

        return $this->view('pages.page', [
            'pageTitle' => (string) $item['title'],
            'item' => $item,
            'breadcrumb' => Container::navigation()->breadcrumb((int) $item['id']),
            'activeNav' => '',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolve(Request $request, string $type): array
    {
        $item = Container::navigation()->findActive($request->queryInt('id', 0));

        if ($item === null || (string) $item['type'] !== $type) {
            throw new HttpException(404, 'Die Seite wurde nicht gefunden.');
        }

        return $item;
    }
}
