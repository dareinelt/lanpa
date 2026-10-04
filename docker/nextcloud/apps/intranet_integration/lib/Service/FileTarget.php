<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Service;

/**
 * Prueft Zielordner und Dateinamen fuer die Dateiablage aus dem Intranet
 * (synchron zu App\Services\Office\NextcloudFilesService::isSafeSegment).
 */
class FileTarget {
    public const MAX_SEGMENTS = 4;

    public static function isSafeSegment(string $segment): bool {
        return $segment !== '' && mb_check_encoding($segment, 'UTF-8') && mb_strlen($segment) <= 120
            && trim($segment) === $segment && !str_starts_with($segment, '.') && !str_ends_with($segment, '.')
            && preg_match('/[\x00-\x1F\x7F\/\\\\<>:"|?*]/u', $segment) !== 1;
    }

    /**
     * @return list<string>|null Ordnersegmente oder null bei unzulaessigem Ziel
     */
    public static function folder(string $folder): ?array {
        $segments = explode('/', $folder);
        if (count($segments) > self::MAX_SEGMENTS) {
            return null;
        }
        foreach ($segments as $segment) {
            if (!self::isSafeSegment($segment)) {
                return null;
            }
        }
        return $segments;
    }
}
