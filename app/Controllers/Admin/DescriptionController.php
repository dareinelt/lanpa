<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Security\Session;
use App\Support\Validator;

/**
 * Globale Einstellungen zur Darstellung der Beschreibungen.
 */
final class DescriptionController extends AdminController
{
    public function index(Request $request): Response
    {
        $settings = Container::settings();

        return $this->adminView('admin.descriptions', [
            'pageTitle' => 'Beschreibungen',
            'activeNav' => 'descriptions',
            'descriptionMode' => $settings->descriptionMode(),
            'siteTitle' => $settings->get('site_title'),
            'siteSubtitle' => $settings->get('site_subtitle'),
            'siteSubtitleVisible' => $settings->bool('site_subtitle_visible'),
            'landingIntroVisible' => $settings->bool('landing_intro_visible'),
            'footerText' => $settings->get('footer_text'),
            'items' => Container::navigation()->allItems(),
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $mode = (string) $request->input('description_mode', 'both');
        $title = Validator::cleanText((string) $request->input('site_title', ''), 120);
        $subtitle = Validator::cleanText((string) $request->input('site_subtitle', ''), 200);
        $subtitleVisible = $request->input('site_subtitle_visible') !== null;
        $landingIntroVisible = $request->input('landing_intro_visible') !== null;
        $footerText = Validator::cleanText((string) $request->input('footer_text', ''), 200);

        $errors = [];
        if (!Validator::isDescriptionMode($mode)) {
            $errors['description_mode'] = 'Ungültiger Darstellungsmodus.';
        }
        if (!Validator::isNotEmpty($title, 120)) {
            $errors['site_title'] = 'Bitte einen Seitentitel angeben.';
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie Ihre Eingaben.');

            return $this->adminView('admin.descriptions', [
                'pageTitle' => 'Beschreibungen',
                'activeNav' => 'descriptions',
                'descriptionMode' => $mode,
                'siteTitle' => $title,
                'siteSubtitle' => $subtitle,
                'siteSubtitleVisible' => $subtitleVisible,
                'landingIntroVisible' => $landingIntroVisible,
                'footerText' => $footerText,
                'items' => Container::navigation()->allItems(),
                'errors' => $errors,
            ], 422);
        }

        Container::settings()->update([
            'description_mode' => $mode,
            'site_title' => $title,
            'site_subtitle' => $subtitle,
            'site_subtitle_visible' => $subtitleVisible ? '1' : '0',
            'landing_intro_visible' => $landingIntroVisible ? '1' : '0',
            'footer_text' => $footerText,
        ]);

        app_logger()->info('Darstellungseinstellungen geändert.', [
            'admin' => Container::auth()->username(),
            'description_mode' => $mode,
        ]);
        Session::flash('success', 'Die Einstellungen wurden gespeichert.');

        return $this->redirect('/admin/beschreibungen');
    }
}
