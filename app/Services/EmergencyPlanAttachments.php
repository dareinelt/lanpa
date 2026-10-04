<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;

/**
 * Anhänge (Bilder und PDF) an Schritten eines Notfallplans.
 *
 * Inhalte liegen base64-kodiert und inhaltsadressiert (SHA-256) in `emergency_plan_attachments`;
 * Elemente referenzieren sie über `node.attachments[] = {id, name, mime, size}`. Da Inhalte nie
 * verändert werden, bleiben veröffentlichte Fassungen und Ereignis-Snapshots unverändert gültig.
 */
final class EmergencyPlanAttachments
{
    /** Elementtypen, die Anhänge haben dürfen. */
    public const TYPES = ['action', 'contact', 'decision', 'note'];
    public const MAX_PER_NODE = 10;
    /** Höchstgröße je Datei (Rohdaten); entspricht upload_max_filesize in docker/php/php.ini. */
    public const MAX_BYTES = 20971520;
    public const MIMES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Erkennt den Dateityp anhand der Signatur (nie anhand von Dateiname oder Browserangabe). */
    public static function detectMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** Prüft eine hochgeladene bzw. importierte Datei und liefert den erkannten MIME-Typ. */
    public static function check(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            self::fail('Anhänge dürfen höchstens ' . self::maxLabel() . ' groß sein.');
        }
        $mime = self::detectMime($bytes);
        if ($mime === null) {
            self::fail('Als Anhang sind nur PDF-Dateien und Bilder (PNG, JPEG, GIF, WebP) erlaubt.');
        }

        return $mime;
    }

    /** Bereinigter Anzeigename (ohne Pfad und Steuerzeichen, höchstens 190 Zeichen). */
    public static function name(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = '';
        }

        return $name === '' ? 'Anhang' : mb_substr($name, 0, 190);
    }

    /**
     * Validiert die Anhangsliste eines Elements (Teil von EmergencyPlanDefinition::validate()).
     *
     * @return list<array{id:string,name:string,mime:string,size:int}>
     */
    public static function validateList(mixed $list, string $type): array
    {
        if ($list === null) {
            return [];
        }
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_PER_NODE) {
            self::fail('Je Schritt sind höchstens ' . self::MAX_PER_NODE . ' Anhänge möglich.');
        }
        if ($list !== [] && !in_array($type, self::TYPES, true)) {
            self::fail('Anhänge sind nur bei Maßnahme, Kontakt, Entscheidung und Hinweis möglich.');
        }
        $result = [];
        $seen = [];
        foreach ($list as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $size = is_array($item) ? filter_var($item['size'] ?? null, FILTER_VALIDATE_INT) : false;
            if (!is_string($id) || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1 || isset($seen[$id])
                || !in_array($item['mime'] ?? null, self::MIMES, true)
                || $size === false || $size < 1 || $size > self::MAX_BYTES) {
                self::fail('Ungültiger Anhang. Bitte Anhang entfernen und erneut hochladen.');
            }
            $result[] = ['id' => $id, 'name' => EmergencyPlanDefinition::text($item['name'] ?? '', 190, 'Name des Anhangs', true),
                'mime' => $item['mime'], 'size' => $size];
            $seen[$id] = true;
        }

        return $result;
    }

    /** @return list<string> alle in einer Definition referenzierten Anhangs-IDs */
    public static function ids(array $definition): array
    {
        $ids = [];
        foreach ($definition['nodes'] ?? [] as $node) {
            foreach (is_array($node) && is_array($node['attachments'] ?? null) ? $node['attachments'] : [] as $attachment) {
                if (is_array($attachment) && is_string($attachment['id'] ?? null)) {
                    $ids[$attachment['id']] = $attachment['id'];
                }
            }
        }

        return array_values($ids);
    }

    public static function maxLabel(): string
    {
        return (self::MAX_BYTES / 1048576) . ' MB';
    }

    private static function fail(string $message): never
    {
        throw new ValidationException(['attachment' => $message]);
    }
}
