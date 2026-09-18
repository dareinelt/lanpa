<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ValidationException;

/**
 * Verarbeitet den Upload des dezenten Hintergrundbilds (Wasserzeichen).
 *
 * Es sind ausschliesslich PNG-Dateien erlaubt. Die Dateien werden wie beim
 * Logo ausserhalb des DocumentRoot gespeichert und ueber einen Controller
 * ausgeliefert; damit kann nichts direkt ausgefuehrt werden.
 */
final class BackgroundImageService
{
    private const ALLOWED = [
        'image/png' => 'png',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $uploadPath,
        private readonly int $maxBytes
    ) {
    }

    /**
     * @param array<string,mixed> $file Eintrag aus $_FILES
     *
     * @return array{background_file:string,background_mime:string}
     */
    public function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['background' => 'Es wurde keine Datei ausgewählt.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['background' => 'Der Upload ist fehlgeschlagen.']);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $this->maxBytes) {
            throw new ValidationException([
                'background' => sprintf('Die Datei darf maximal %d KB groß sein.', (int) ($this->maxBytes / 1024)),
            ]);
        }

        if ($tmpName === '' || !is_readable($tmpName)) {
            throw new ValidationException(['background' => 'Die hochgeladene Datei konnte nicht gelesen werden.']);
        }

        $mime = $this->detectMime($tmpName);
        if (!isset(self::ALLOWED[$mime])) {
            throw new ValidationException(['background' => 'Erlaubt ist ausschließlich das PNG-Format.']);
        }

        $extension = self::ALLOWED[$mime];

        $originalExtension = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($originalExtension !== '' && $originalExtension !== $extension) {
            throw new ValidationException(['background' => 'Dateiendung und Dateiinhalt passen nicht zusammen.']);
        }

        if (@getimagesize($tmpName) === false) {
            throw new ValidationException(['background' => 'Die Bilddatei ist ungültig.']);
        }

        if (!is_dir($this->uploadPath) && !@mkdir($this->uploadPath, 0o775, true) && !is_dir($this->uploadPath)) {
            throw new ValidationException(['background' => 'Das Upload-Verzeichnis ist nicht beschreibbar.']);
        }

        // Der Originalname wird nie uebernommen.
        $filename = 'background-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $target)
            : rename($tmpName, $target);

        if ($moved !== true) {
            throw new ValidationException(['background' => 'Die Datei konnte nicht gespeichert werden.']);
        }

        @chmod($target, 0o644);

        $previous = $this->settings->get('background_file');
        $this->settings->update(['background_file' => $filename, 'background_mime' => $mime]);
        $this->deleteFile($previous);

        return ['background_file' => $filename, 'background_mime' => $mime];
    }

    public function remove(): void
    {
        $this->deleteFile($this->settings->get('background_file'));
        $this->settings->update(['background_file' => '', 'background_mime' => '']);
    }

    /**
     * @return array{path:string,mime:string}|null
     */
    public function current(): ?array
    {
        $file = $this->settings->get('background_file');
        $mime = $this->settings->get('background_mime');

        if ($file === '' || !$this->isValidFilename($file) || !array_key_exists($mime, self::ALLOWED)) {
            return null;
        }

        $path = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $file;

        return is_readable($path) ? ['path' => $path, 'mime' => $mime] : null;
    }

    private function isValidFilename(string $filename): bool
    {
        return preg_match('/^background-[a-f0-9]{16}\.png$/', $filename) === 1;
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

    private function detectMime(string $path): string
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

        return $mime;
    }

    public static function maxBytesFromConfig(): int
    {
        return (int) Config::get('app.max_background_bytes', 2 * 1024 * 1024);
    }
}
