<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Security\Session;
use App\Support\Validator;

final class DesignController extends AdminController
{
    private const COLOR_KEYS = [
        'color_primary' => 'Primärfarbe',
        'color_secondary' => 'Sekundärfarbe',
        'color_accent' => 'Akzentfarbe',
        'color_background' => 'Hintergrundfarbe (hell)',
        'color_text' => 'Textfarbe (hell)',
        'color_background_dark' => 'Hintergrundfarbe (dunkel)',
        'color_text_dark' => 'Textfarbe (dunkel)',
    ];

    public function index(Request $request): Response
    {
        return $this->adminView('admin.design', [
            'pageTitle' => 'Design',
            'activeNav' => 'design',
            'colorKeys' => self::COLOR_KEYS,
            'theme' => Container::settings()->theme(),
            'hasLogo' => Container::logo()->current() !== null,
            'maxLogoKb' => (int) ((int) Config::get('app.max_logo_bytes', 512 * 1024) / 1024),
            'errors' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $this->requireValidCsrf($request);

        $values = [];
        $errors = [];

        foreach (array_keys(self::COLOR_KEYS) as $key) {
            $raw = (string) $request->input($key, '');
            $normalized = Validator::normalizeHexColor($raw);

            if ($normalized === null) {
                $errors[$key] = 'Bitte einen gültigen Hex-Farbwert angeben (z. B. #1f4e79).';
                continue;
            }

            $values[$key] = $normalized;
        }

        if ($errors !== []) {
            Session::flash('error', 'Bitte prüfen Sie die Farbwerte.');

            return $this->adminView('admin.design', [
                'pageTitle' => 'Design',
                'activeNav' => 'design',
                'colorKeys' => self::COLOR_KEYS,
                'theme' => array_merge(Container::settings()->theme(), $values),
                'hasLogo' => Container::logo()->current() !== null,
                'maxLogoKb' => (int) ((int) Config::get('app.max_logo_bytes', 512 * 1024) / 1024),
                'errors' => $errors,
            ], 422);
        }

        Container::settings()->update($values);
        app_logger()->info('Farbschema geändert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Farbschema wurde gespeichert.');

        return $this->redirect('/admin/design');
    }

    public function uploadLogo(Request $request): Response
    {
        $this->requireValidCsrf($request);

        /** @var array<string,mixed> $file */
        $file = $request->files['logo'] ?? ['error' => UPLOAD_ERR_NO_FILE];

        try {
            Container::logo()->store($file);
        } catch (ValidationException $exception) {
            Session::flash('error', implode(' ', $exception->errors()));

            return $this->redirect('/admin/design');
        }

        app_logger()->info('Logo aktualisiert.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Logo wurde gespeichert.');

        return $this->redirect('/admin/design');
    }

    public function removeLogo(Request $request): Response
    {
        $this->requireValidCsrf($request);
        Container::logo()->remove();
        app_logger()->info('Logo entfernt.', ['admin' => Container::auth()->username()]);
        Session::flash('success', 'Das Logo wurde entfernt.');

        return $this->redirect('/admin/design');
    }
}
