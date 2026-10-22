<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\ValidationException;
use App\Repositories\NavigationIconRepository;
use App\Support\NavigationIcons;
use App\Support\SvgSanitizer;

/**
 * Verwaltet den Icon-Katalog der Navigationskacheln.
 *
 * Der Alias entspricht dem Wert in navigation_items.icon. Die SVG-Dateien
 * liegen ausserhalb des DocumentRoot und werden ueber einen Controller
 * ausgeliefert.
 */
final class NavigationIconService
{
    public const ALIAS_PATTERN = '/^[a-z0-9-]{1,32}$/';

    private const FILE_PATTERN = '/^nav-icon-[a-f0-9]{16}\.svg$/';

    public function __construct(
        private readonly NavigationIconRepository $repository,
        private readonly string $uploadPath,
        private readonly int $maxBytes
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            new NavigationIconRepository(),
            (string) Config::get('app.upload_path'),
            (int) Config::get('app.max_nav_icon_bytes', 256 * 1024)
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->repository->all();
    }

    /**
     * @return list<string>
     */
    public function aliases(): array
    {
        return array_values(array_map(
            static fn (array $icon): string => (string) $icon['alias'],
            $this->repository->all()
        ));
    }

    public function exists(string $alias): bool
    {
        return $this->repository->findByAlias($alias) !== null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->repository->find($id);
    }

    /**
     * Liefert Pfad und Typ der gespeicherten SVG-Datei zu einem Alias.
     *
     * @return array{path:string,mime:string}|null
     */
    public function fileByAlias(string $alias): ?array
    {
        $icon = $this->repository->findByAlias($alias);
        if ($icon === null) {
            return null;
        }

        $fileName = (string) ($icon['file_name'] ?? '');
        $mime = (string) ($icon['mime_type'] ?? '');
        if ($mime !== 'image/svg+xml' || !$this->isValidFileName($fileName)) {
            return null;
        }

        $path = $this->pathFor($fileName);

        return is_readable($path) ? ['path' => $path, 'mime' => $mime] : null;
    }

    /**
     * @param array<string,mixed> $file Eintrag aus $_FILES
     */
    public function create(string $alias, array $file): int
    {
        $alias = $this->validateAlias($alias);
        $fileName = $this->writeSvg($this->readSvgUpload($file));

        try {
            return $this->repository->create($alias, $fileName, 'image/svg+xml');
        } catch (\PDOException $exception) {
            $this->deleteFile($fileName);
            if ($this->repository->findByAlias($alias) !== null) {
                throw new ValidationException(['alias' => 'Dieser Alias ist bereits vergeben.']);
            }

            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $file Eintrag aus $_FILES
     */
    public function replaceFile(int $id, array $file): void
    {
        $icon = $this->repository->find($id);
        if ($icon === null) {
            throw new ValidationException(['svg' => 'Das Icon wurde nicht gefunden.']);
        }

        $fileName = $this->writeSvg($this->readSvgUpload($file));
        $this->repository->updateFile($id, $fileName, 'image/svg+xml');
        $this->deleteFile((string) ($icon['file_name'] ?? ''));
    }

    public function rename(int $id, string $alias): void
    {
        $icon = $this->repository->find($id);
        if ($icon === null) {
            throw new ValidationException(['alias' => 'Das Icon wurde nicht gefunden.']);
        }

        $newAlias = $this->validateAlias($alias, $id);
        $oldAlias = (string) $icon['alias'];
        if ($oldAlias === $newAlias) {
            return;
        }

        $this->repository->rename($id, $newAlias);
        $this->repository->replaceAlias($oldAlias, $newAlias);
    }

    public function delete(int $id): void
    {
        $icon = $this->repository->find($id);
        if ($icon === null) {
            return;
        }

        $alias = (string) $icon['alias'];
        if ($this->repository->usageCount($alias) > 0) {
            throw new ValidationException([
                'icon' => 'Das Icon wird noch von Navigationskacheln verwendet. Bitte dort zuerst ein anderes Icon wählen.',
            ]);
        }

        $this->repository->delete($id);
        $this->deleteFile((string) ($icon['file_name'] ?? ''));
    }

    private function validateAlias(string $alias, ?int $ignoreId = null): string
    {
        $alias = strtolower(trim($alias));
        if ($alias === '') {
            throw new ValidationException(['alias' => 'Bitte einen Alias angeben.']);
        }

        if (preg_match(self::ALIAS_PATTERN, $alias) !== 1) {
            throw new ValidationException([
                'alias' => 'Der Alias darf nur Kleinbuchstaben, Ziffern und Bindestriche enthalten (max. 32 Zeichen).',
            ]);
        }

        if (NavigationIcons::isBuiltin($alias)) {
            throw new ValidationException(['alias' => 'Dieser Alias ist bereits für ein Standard-Icon vergeben.']);
        }

        $existing = $this->repository->findByAlias($alias);
        if ($existing !== null && (int) $existing['id'] !== $ignoreId) {
            throw new ValidationException(['alias' => 'Dieser Alias ist bereits vergeben.']);
        }

        return $alias;
    }

    /**
     * @param array<string,mixed> $file Eintrag aus $_FILES
     */
    private function readSvgUpload(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException(['svg' => 'Es wurde keine Datei ausgewählt.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['svg' => 'Der Upload ist fehlgeschlagen.']);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > $this->maxBytes) {
            throw new ValidationException([
                'svg' => sprintf('Die Datei darf maximal %d KB groß sein.', (int) ($this->maxBytes / 1024)),
            ]);
        }

        if ($tmpName === '' || !is_readable($tmpName)) {
            throw new ValidationException(['svg' => 'Die hochgeladene Datei konnte nicht gelesen werden.']);
        }

        $originalName = (string) ($file['name'] ?? '');
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'svg') {
            throw new ValidationException(['svg' => 'Erlaubt sind ausschließlich SVG-Dateien.']);
        }

        $contents = (string) file_get_contents($tmpName);
        if ($contents === '' || !SvgSanitizer::isSafe($contents)) {
            throw new ValidationException(['svg' => 'Die SVG-Datei enthält nicht erlaubte aktive Inhalte.']);
        }

        return $contents;
    }

    private function writeSvg(string $contents): string
    {
        if (!is_dir($this->uploadPath) && !@mkdir($this->uploadPath, 0o775, true) && !is_dir($this->uploadPath)) {
            throw new ValidationException(['svg' => 'Das Upload-Verzeichnis ist nicht beschreibbar.']);
        }

        $fileName = 'nav-icon-' . bin2hex(random_bytes(8)) . '.svg';
        $target = $this->pathFor($fileName);

        if (@file_put_contents($target, $contents) === false) {
            throw new ValidationException(['svg' => 'Die Datei konnte nicht gespeichert werden.']);
        }

        @chmod($target, 0o644);

        return $fileName;
    }

    private function deleteFile(string $fileName): void
    {
        if (!$this->isValidFileName($fileName)) {
            return;
        }

        $path = $this->pathFor($fileName);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function isValidFileName(string $fileName): bool
    {
        return preg_match(self::FILE_PATTERN, $fileName) === 1;
    }

    private function pathFor(string $fileName): string
    {
        return rtrim($this->uploadPath, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    }
}
