<?php

declare(strict_types=1);

namespace App\Security;

final class HtmlEscaper
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
