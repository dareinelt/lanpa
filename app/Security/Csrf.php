<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Html;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function isValid(?string $token): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . Html::e(self::token()) . '">';
    }

    public static function rotate(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
