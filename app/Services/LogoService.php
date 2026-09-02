<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ValidationException;

/**
 * Verarbeitet den Logo-Upload.
 *
 * Die Dateien werden ausserhalb des DocumentRoot gespeichert und ueber einen
 * Controller ausgeliefert; damit kann nichts direkt ausgefuehrt werden.
 */
final class LogoService
{
    private const ALLOWED = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $uploadPath,
        private readonly int $maxBytes,
        private readonly bool $allowSvg = true
    ) {
    }

    /**
     * @param array<string,mixed> $file Eintrag aus $_FILES
     *
     * @return array{logo_file:string,logo_mime:string}
     */
    public function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['logo' => 'Es wurde keine Datei ausgewählt.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['logo' => 'Der Upload ist fehlgeschlagen.']);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $this->maxBytes) {
            throw new ValidationException([
                'logo' => sprintf('Die Datei darf maximal %d KB groß sein.', (int) ($this->maxBytes / 1024)),
            ]);
        }

        if ($tmpName === '' || !is_readable($tmpName)) {
            throw new ValidationException(['logo' => 'Die hochgeladene Datei konnte nicht gelesen werden.']);
        }

        $mime = $this->detectMime($tmpName, (string) ($file['name'] ?? ''));
        if (!isset(self::ALLOWED[$mime])) {
            throw new ValidationException(['logo' => 'Erlaubt sind ausschließlich PNG, JPEG, WebP und SVG.']);
        }

        $extension = self::ALLOWED[$mime];

        // Dateiendung muss zum erkannten Typ passen.
        $originalExtension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $validExtensions = $extension === 'jpg' ? ['jpg', 'jpeg'] : [$extension];
        if ($originalExtension !== '' && !in_array($originalExtension, $validExtensions, true)) {
            throw new ValidationException(['logo' => 'Dateiendung und Dateiinhalt passen nicht zusammen.']);
        }

        if ($extension === 'svg') {
            if (!$this->allowSvg) {
                throw new ValidationException(['logo' => 'SVG-Uploads sind in dieser Installation deaktiviert.']);
            }

            $contents = (string) file_get_contents($tmpName);
            if (!$this->isSafeSvg($contents)) {
                throw new ValidationException(['logo' => 'Die SVG-Datei enthält nicht erlaubte aktive Inhalte.']);
            }
        } elseif (@getimagesize($tmpName) === false) {
            throw new ValidationException(['logo' => 'Die Bilddatei ist ungültig.']);
        }

        if (!is_dir($this->uploadPath) && !@mkdir($this->uploadPath, 0o775, true) && !is_dir($this->uploadPath)) {
            throw new ValidationException(['logo' => 'Das Upload-Verzeichnis ist nicht beschreibbar.']);
        }

        // Der Originalname wird nie uebernommen.
        $filename = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $target)
            : rename($tmpName, $target);

        if ($moved !== true) {
            throw new ValidationException(['logo' => 'Die Datei konnte nicht gespeichert werden.']);
        }

        @chmod($target, 0o644);

        $previous = $this->settings->get('logo_file');
        $this->settings->update(['logo_file' => $filename, 'logo_mime' => $mime]);
        $this->deleteFile($previous);

        return ['logo_file' => $filename, 'logo_mime' => $mime];
    }

    public function remove(): void
    {
        $this->deleteFile($this->settings->get('logo_file'));
        $this->settings->update(['logo_file' => '', 'logo_mime' => '']);
    }

    /**
     * @return array{path:string,mime:string}|null
     */
    public function current(): ?array
    {
        $file = $this->settings->get('logo_file');
        $mime = $this->settings->get('logo_mime');

        if ($file === '' || !$this->isValidFilename($file) || !array_key_exists($mime, self::ALLOWED)) {
            return null;
        }

        $path = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $file;

        return is_readable($path) ? ['path' => $path, 'mime' => $mime] : null;
    }

    public function isSafeSvg(string $contents): bool
    {
        $normalized = strtolower($contents);

        $forbidden = ['<script', '<foreignobject', 'javascript:', '<iframe', '<embed', '<object', '<use ', '<handler', '<set ', '<audio', '<video'];
        foreach ($forbidden as $needle) {
            if (str_contains($normalized, $needle)) {
                return false;
            }
        }

        // Event-Handler-Attribute (onload, onclick, ...)
        if (preg_match('/\son[a-z]+\s*=/i', $contents) === 1) {
            return false;
        }

        // Externe Referenzen und Entities
        if (preg_match('/<!entity/i', $contents) === 1 || preg_match('/<!doctype[^>]*\[/i', $contents) === 1) {
            return false;
        }

        return str_contains($normalized, '<svg');
    }

    private function isValidFilename(string $filename): bool
    {
        return preg_match('/^logo-[a-f0-9]{16}\.(png|jpg|svg|webp)$/', $filename) === 1;
    }

    private function deleteFile(string $filename): void
    {
        if ($filename === '' || !$this->isValidFilename($filename)) {
            return;
        }

        $path = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function detectMime(string $path, string $originalName): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                $mime = is_string($detected) ? $detected : '';
            }
        }

        // SVG wird haeufig als text/plain oder text/xml erkannt.
        if (in_array($mime, ['text/plain', 'text/xml', 'application/xml', 'text/html', ''], true)) {
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            $contents = (string) file_get_contents($path, false, null, 0, 4096);
            if ($extension === 'svg' && stripos($contents, '<svg') !== false) {
                return 'image/svg+xml';
            }
        }

        return $mime;
    }

    public static function maxBytesFromConfig(): int
    {
        return (int) Config::get('app.max_logo_bytes', 512 * 1024);
    }
}
