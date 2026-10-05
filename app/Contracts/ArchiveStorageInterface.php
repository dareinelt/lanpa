<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Ablage der Orvanta-Archivcontainer (Chunks und Manifest) im persoenlichen
 * Speicherbereich eines Benutzers. Produktiv steht dahinter Nextcloud
 * (NextcloudArchiveStorage); Tests nutzen eine In-Memory-Implementierung
 * mit Fehler- und Korruptions-Injektion.
 */
interface ArchiveStorageInterface
{
    /**
     * Legt eine Datei im Archivordner des Benutzers ab (ueberschreibt eine
     * vorhandene Datei gleichen Namens).
     *
     * @throws \RuntimeException wenn die Ablage fehlschlaegt
     */
    public function put(string $uid, string $folder, string $name, string $bytes): void;

    /**
     * Liest eine Datei aus dem Archivordner des Benutzers zurueck.
     *
     * @throws \RuntimeException wenn die Datei fehlt oder nicht lesbar ist
     */
    public function get(string $uid, string $folder, string $name): string;
}
