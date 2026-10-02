<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

/**
 * Welche Dateien synchronisiert und welche zwischen Hot-Tier (lokales
 * Storage) und Cold-Tier (SMB-Tier) verschoben werden duerfen.
 *
 * Pfade sind relativ zur jeweiligen Quelle und nutzen "/" als Trenner.
 */
final class PathRules
{
    public const SOURCE_NEXTCLOUD_DATA = 'nextcloud-data';
    public const SOURCE_NEXTCLOUD_CONFIG = 'nextcloud-config';
    public const SOURCE_EUROOFFICE_DATA = 'eurooffice-data';
    public const SOURCE_NEXTCLOUD_DB = 'nextcloud-db';

    /** Temporaeres Verzeichnis fuer Rueckholungen im Nextcloud-Datenverzeichnis. */
    public const RECALL_DIR = '.lanpa-recall';

    /** Kennungsdatei auf jedem Speicherziel des Cold-Tiers. */
    public const TARGET_MARKER = '.lanpa-storage.json';

    /** Endung temporaerer Dateien waehrend einer Uebertragung. */
    public const TEMP_SUFFIX = '.lanpa-tmp';

    /** Nur Benutzerdateien, Versionen und Papierkorb wandern in den Cold-Tier. */
    private const TIERED = '#^[^/]+/(files|files_versions|files_trashbin)/#';

    /** Dateien unter dieser Groesse bleiben immer im Hot-Tier (kein Platzgewinn). */
    public const MIN_TIER_SIZE = 65536;

    public static function isExcluded(string $source, string $path): bool
    {
        if ($path === '' || str_contains('/' . $path . '/', '/../')) {
            return true;
        }
        $name = basename($path);
        if (str_ends_with($name, self::TEMP_SUFFIX) || $name === 'lost+found' || str_starts_with($path, 'lost+found/')) {
            return true;
        }

        return match ($source) {
            self::SOURCE_NEXTCLOUD_DATA => self::excludedNextcloud($path, $name),
            self::SOURCE_EUROOFFICE_DATA => $path === '.private' || str_starts_with($path, '.private/'),
            self::SOURCE_NEXTCLOUD_CONFIG => !str_ends_with($name, '.php') && !str_ends_with($name, '.json'),
            default => false,
        };
    }

    public static function isTiered(string $source, string $path): bool
    {
        return $source === self::SOURCE_NEXTCLOUD_DATA && preg_match(self::TIERED, $path) === 1;
    }

    /**
     * Relativer Pfad einer Datei unterhalb eines Basisverzeichnisses (oder null).
     */
    public static function relative(string $base, string $absolute): ?string
    {
        $base = rtrim($base, '/') . '/';
        if (!str_starts_with($absolute, $base)) {
            return null;
        }
        $rel = substr($absolute, strlen($base));

        return $rel === '' ? null : $rel;
    }

    private static function excludedNextcloud(string $path, string $name): bool
    {
        if ($path === self::RECALL_DIR || str_starts_with($path, self::RECALL_DIR . '/')) {
            return true;
        }
        if (str_ends_with($name, '.part') || str_starts_with($name, '.ocTransferId')) {
            return true;
        }
        if (in_array($path, ['nextcloud.log', 'audit.log', 'updater.log', '.ocdata'], true) || str_starts_with($path, 'updater-')) {
            return true;
        }
        // Vorschaubilder, Zwischenspeicher und unvollstaendige Uploads lassen sich neu erzeugen.
        if (preg_match('#^appdata_[^/]+/(preview|css|js|theming/[^/]+/cache)(/|$)#', $path) === 1) {
            return true;
        }

        return preg_match('#^[^/]+/(cache|uploads)(/|$)#', $path) === 1;
    }
}
