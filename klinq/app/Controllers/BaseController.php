<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\NotFoundException;
use App\Security\CurrentUser;
use App\Support\Url;

abstract class BaseController
{
    public function __construct(
        protected readonly View $view,
        protected readonly CurrentUser $currentUser
    ) {}

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    /** @param array<mixed> $data */
    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    protected function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** Merkt sich Eingaben + Fehler für die erneute Anzeige eines Formulars. */
    protected function withOldInput(Request $request, array $errors): void
    {
        $input = $request->all();
        unset($input['_csrf'], $input['password'], $input['password_confirmation'], $input['signature_password']);
        $_SESSION['_old_input'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    /** @return array<string,mixed> */
    protected function findOrFail(?array $row, string $message = 'Datensatz nicht gefunden'): array
    {
        if ($row === null) {
            throw new NotFoundException($message);
        }

        return $row;
    }

    protected function backUrl(Request $request, string $fallback): string
    {
        $referer = $request->header('Referer');
        if ($referer !== null) {
            $path = parse_url($referer, PHP_URL_PATH);
            if (is_string($path)) {
                $query = parse_url($referer, PHP_URL_QUERY);
                $candidate = Url::safeLocalPath($path . ($query ? '?' . $query : ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $fallback;
    }
}
