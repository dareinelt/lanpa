<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Contracts\ArchiveStorageInterface;
use App\Services\Office\NextcloudFilesService;

/**
 * Produktive Archivablage: legt die Containerdateien des Orvanta-Langzeit-
 * archivs im Nextcloud-Bereich des Benutzers ab (intranet_integration-API).
 * Fehler werden als RuntimeException gemeldet, damit der Archivdienst den
 * betroffenen Schritt sauber abbrechen kann (nie loeschen ohne Verifikation).
 */
final class NextcloudArchiveStorage implements ArchiveStorageInterface
{
    public function __construct(private readonly NextcloudFilesService $files)
    {
    }

    public function put(string $uid, string $folder, string $name, string $bytes): void
    {
        $result = $this->files->upload($uid, $folder, $name, $bytes);
        if (!$result['ok']) {
            throw new \RuntimeException('Archivablage fehlgeschlagen: ' . $result['message']);
        }
    }

    public function get(string $uid, string $folder, string $name): string
    {
        $result = $this->files->fetch($uid, $folder, $name);
        if (!$result['ok']) {
            throw new \RuntimeException('Archivdatei nicht lesbar: ' . $result['message']);
        }

        return $result['content'];
    }
}
