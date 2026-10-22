<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Eingebaute Icon-Schluessel der Navigationskacheln.
 *
 * Die zugehoerigen SVG-Pfade liegen in views/partials/icon.php. Eigene Icons
 * werden zusaetzlich im Icon-Katalog (Tabelle navigation_icons) gepflegt.
 */
final class NavigationIcons
{
    /** @var list<string> */
    public const BUILTIN = [
        'document',
        'app',
        'phone',
        'alert',
        'tools',
        'robot',
        'link',
        'clock',
        'helmet',
        'wrench',
        'snail',
        'beacon',
        'ekg',
        'warning',
        'spreadsheet',
        'presentation',
        'pdf',
        'folder',
        'mail',
        'orvanta',
        'siren',
    ];

    public static function isBuiltin(string $alias): bool
    {
        return in_array($alias, self::BUILTIN, true);
    }
}
