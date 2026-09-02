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
        $items = Container::navigation()->activeItems();

        return $this->view('landing.index', [
            'pageTitle' => Container::settings()->get('site_title'),
            'items' => $items,
            'descriptionMode' => Container::settings()->descriptionMode(),
            'activeNav' => 'home',
            'pageScript' => 'landing.js',
        ]);
    }
}
