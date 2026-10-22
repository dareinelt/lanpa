<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Support\SvgSanitizer;

/**
 * Verarbeitet den Logo-Upload.
 *
 * Die Klasse verwaltet zwei getrennte Dateien: das Logo (auch fuer die
 * E-Mail-Signaturen) und das optionale komplette Logo der Kopfzeile (SVG).
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

    /** Nur SVG – fuer das komplette Logo der Kopfzeile. */
    public const SVG_ONLY = ['image/svg+xml' => 'svg'];

    /** @var array<string,string> */
    private readonly array $allowedMimes;

    /**
     * @param array<string,string>|null $allowedMimes MIME-Typ => Dateiendung; null = alle Logo-Typen
     */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $uploadPath,
        private readonly int $maxBytes,
        private readonly bool $allowSvg = true,
        private readonly string $fileSetting = 'logo_file',
        private readonly string $mimeSetting = 'logo_mime',
        private readonly string $filenamePrefix = 'logo',
        ?array $allowedMimes = null
    ) {
        $this->allowedMimes = $allowedMimes ?? self::ALLOWED;
    }

    /**
     * @param array<string,mixed> $file Eintrag aus $_FILES
     *
     * @return array<string,string>
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
        if (!isset($this->allowedMimes[$mime])) {
            throw new ValidationException(['logo' => sprintf('Erlaubt sind ausschließlich %s.', $this->allowedDescription())]);
        }

        $extension = $this->allowedMimes[$mime];

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
        $filename = $this->filenamePrefix . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $target = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;

        $moved = is_uploaded_file($tmpName)
            ? move_uploaded_file($tmpName, $target)
            : rename($tmpName, $target);

        if ($moved !== true) {
            throw new ValidationException(['logo' => 'Die Datei konnte nicht gespeichert werden.']);
        }

        @chmod($target, 0o644);

        $previous = $this->settings->get($this->fileSetting);
        $this->settings->update([$this->fileSetting => $filename, $this->mimeSetting => $mime]);
        $this->deleteFile($previous);

        return [$this->fileSetting => $filename, $this->mimeSetting => $mime];
    }

    public function remove(): void
    {
        $this->deleteFile($this->settings->get($this->fileSetting));
        $this->settings->update([$this->fileSetting => '', $this->mimeSetting => '']);
    }

    /**
     * @return array{path:string,mime:string}|null
     */
    public function current(): ?array
    {
        $file = $this->settings->get($this->fileSetting);
        $mime = $this->settings->get($this->mimeSetting);

        if ($file === '' || !$this->isValidFilename($file) || !array_key_exists($mime, $this->allowedMimes)) {
            return null;
        }

        $path = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $file;

        return is_readable($path) ? ['path' => $path, 'mime' => $mime] : null;
    }

    /**
     * Schreibt ein Logo aus einer Sicherung. Dateiname, Typ und Inhalt stammen
     * aus der Exportdatei und werden unveraendert uebernommen.
     */
    public function writeFromImport(string $filename, string $contents, string $mime): void
    {
        if (!$this->isValidFilename($filename)) {
            throw new ValidationException(['logo' => 'Ungültiger Logo-Dateiname in der Sicherung.']);
        }

        $extension = $this->allowedMimes[$mime] ?? null;
        if ($extension === null) {
            throw new ValidationException(['logo' => sprintf('Erlaubt sind ausschließlich %s.', $this->allowedDescription())]);
        }

        $validExtensions = $extension === 'jpg' ? ['jpg', 'jpeg'] : [$extension];
        if (!in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), $validExtensions, true)) {
            throw new ValidationException(['logo' => 'Logo-Dateiendung und -Typ passen nicht zusammen.']);
        }

        if (strlen($contents) <= 0 || strlen($contents) > $this->maxBytes) {
            throw new ValidationException(['logo' => sprintf('Die Datei darf maximal %d KB groß sein.', (int) ($this->maxBytes / 1024))]);
        }

        if ($extension === 'svg') {
            if (!$this->allowSvg) {
                throw new ValidationException(['logo' => 'SVG-Uploads sind in dieser Installation deaktiviert.']);
            }
            if (!$this->isSafeSvg($contents)) {
                throw new ValidationException(['logo' => 'Die SVG-Datei enthält nicht erlaubte aktive Inhalte.']);
            }
        } elseif (@getimagesizefromstring($contents) === false) {
            throw new ValidationException(['logo' => 'Die Bilddatei ist ungültig.']);
        }

        $this->writeContents($filename, $contents);
    }

    public function isSafeSvg(string $contents): bool
    {
        return SvgSanitizer::isSafe($contents);
    }

    private function isValidFilename(string $filename): bool
    {
        $extensions = array_map(
            static fn (string $extension): string => preg_quote($extension, '/'),
            array_values($this->allowedMimes)
        );

        $pattern = '/^' . preg_quote($this->filenamePrefix, '/') . '-[a-f0-9]{16}\.(' . implode('|', $extensions) . ')$/';

        return preg_match($pattern, $filename) === 1;
    }

    /**
     * Aufzaehlung der erlaubten Dateitypen fuer Fehlermeldungen.
     */
    private function allowedDescription(): string
    {
        $labels = ['png' => 'PNG', 'jpg' => 'JPEG', 'webp' => 'WebP', 'svg' => 'SVG'];

        $names = [];
        foreach (array_values($this->allowedMimes) as $extension) {
            $names[] = $labels[$extension] ?? strtoupper($extension);
        }

        if ($names === []) {
            return '';
        }

        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' und ' . $last;
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

    private function writeContents(string $filename, string $contents): void
    {
        if (!is_dir($this->uploadPath) && !@mkdir($this->uploadPath, 0o775, true) && !is_dir($this->uploadPath)) {
            throw new ValidationException(['logo' => 'Das Upload-Verzeichnis ist nicht beschreibbar.']);
        }

        $target = rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $tmp = $target . '.tmp';

        if (@file_put_contents($tmp, $contents) === false) {
            throw new ValidationException(['logo' => 'Die Datei konnte nicht gespeichert werden.']);
        }

        @chmod($tmp, 0o644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);

            throw new ValidationException(['logo' => 'Die Datei konnte nicht gespeichert werden.']);
        }
    }

    private function detectMime(string $path, string $originalName): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
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
