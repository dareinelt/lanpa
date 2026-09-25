<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;

final class LandingController extends Controller
{
    public function index(Request $request): Response
    {
        $ssoUser = Container::sso()->resolve($request);
        $items = Container::navigation()->activeTopLevelFor($ssoUser);

        return $this->view('landing.index', [
            'pageTitle' => Container::settings()->get('site_title'),
            'items' => $items,
            'ssoUser' => $ssoUser,
            'importantLinks' => Container::importantLinks()->activeItems(),
            'descriptionMode' => Container::settings()->descriptionMode(),
            'activeNav' => 'home',
            'pageScript' => 'landing.js',
        ]);
    }
}
