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
        $ssoUser = Container::sso()->resolve($request);
        $item = $this->resolve($request, 'subpage', $ssoUser);

        $gate = $this->accessGate($item);
        if ($gate !== null) {
            return $gate;
        }

        return $this->view('pages.subpage', [
            'pageTitle' => (string) $item['title'],
            'item' => $item,
            'items' => Container::officeApps()->filterNavigation(
                Container::navigation()->activeChildrenFor((int) $item['id'], $ssoUser),
                $ssoUser
            ),
            'breadcrumb' => Container::navigation()->breadcrumb((int) $item['id']),
            'descriptionMode' => Container::settings()->descriptionMode(),
            'activeNav' => '',
            'pageScript' => 'landing.js',
        ]);
    }

    public function page(Request $request): Response
    {
        $ssoUser = Container::sso()->resolve($request);
        $item = $this->resolve($request, 'page', $ssoUser);

        $gate = $this->accessGate($item);
        if ($gate !== null) {
            return $gate;
        }

        return $this->view('pages.page', [
            'pageTitle' => (string) $item['title'],
            'item' => $item,
            'breadcrumb' => Container::navigation()->breadcrumb((int) $item['id']),
            'activeNav' => '',
        ]);
    }

    /**
     * @param array{id:int,username:string,display_name:string,groups:list<string>}|null $ssoUser
     *
     * @return array<string,mixed>
     */
    private function resolve(Request $request, string $type, ?array $ssoUser): array
    {
        $item = Container::navigation()->findActive($request->queryInt('id', 0));

        if ($item === null || (string) $item['type'] !== $type) {
            throw new HttpException(404, 'Die Seite wurde nicht gefunden.');
        }

        // Serverseitige Schranke: fuer den Benutzer nicht freigeschaltete
        // Kacheln werden wie nicht vorhanden behandelt (kein Informationsleck).
        if (!Container::navigation()->isAccessible((int) $item['id'], $ssoUser)) {
            throw new HttpException(404, 'Die Seite wurde nicht gefunden.');
        }

        return $item;
    }

    /**
     * Liefert eine Zugriffssperre, wenn das Element geschuetzt ist und der
     * Zugriff in der aktuellen Sitzung noch nicht per SMS-Code freigeschaltet
     * wurde. Andernfalls null (Zugriff erlaubt).
     *
     * @param array<string,mixed> $item
     */
    private function accessGate(array $item): ?Response
    {
        if (empty($item['protected_access']) || Container::smsCode()->isVerified((int) $item['id'])) {
            return null;
        }

        $id = (int) $item['id'];
        $href = (string) $item['type'] === 'subpage' ? '/unterseite?id=' . $id : '/seite?id=' . $id;

        return $this->view('pages.access_required', [
            'pageTitle' => 'Geschützter Zugriff',
            'item' => $item,
            'breadcrumb' => Container::navigation()->breadcrumb($id),
            'href' => $href,
            'pageScript' => 'landing.js',
        ]);
    }
}
