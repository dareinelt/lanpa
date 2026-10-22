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

    /**
     * Prueft, ob der Wert ein Alias aus dem Icon-Katalog sein kann.
     *
     * Katalog-Grafiken werden unveraendert dargestellt; sie erhalten keinen
     * Rahmen in Designfarbe.
     */
    public static function isCatalogAlias(string $alias): bool
    {
        return preg_match('/^[a-z0-9-]{1,32}$/', $alias) === 1 && !self::isBuiltin($alias);
    }
}
