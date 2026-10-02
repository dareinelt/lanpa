<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Storage;

use OC\Files\Storage\Local;
use OC\Files\Storage\Wrapper\Jail;
use OC\Files\Storage\Wrapper\Wrapper;
use OCP\Files\ForbiddenException;
use OCP\Files\Storage\IStorage;
use OCP\Files\StorageNotAvailableException;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Server;

/**
 * Speicher-Tiering (Hot-Tier lokales Storage / Cold-Tier SMB-/S3-Tier) fuer die
 * lokalen Nextcloud-Speicher (Home-Verzeichnisse im Datenverzeichnis).
 *
 * - Lesen einer ausgelagerten Datei (Platzhalter) loest die Rueckholung durch
 *   storage-sync aus und wartet darauf; der Browser zeigt den Fortschritt
 *   (js/recall.js, /api/recall).
 * - Ueberschreiben, Verschieben und Loeschen halten die Kennzeichen
 *   (stubs/<pfad>.json) aktuell.
 * - Kopien auf Dateisystemebene (copy, copyFromStorage) wuerden aus einem
 *   Platzhalter Nullen lesen - die Quelle wird daher vorher zurueckgeholt.
 * - Lesezugriffe landen im Zugriffsprotokoll ("haeufig genutzt").
 * - Vorschaubilder holen keine Dateien zurueck (sonst wuerde schon das
 *   Durchblaettern eines Ordners alles in den Hot-Tier laden).
 * - Sicherheitsvorfall (auffaelliges Ueberschreiben, erkannt von
 *   storage-sync): Der betroffene Benutzer darf bis zur Erledigung nur
 *   lesen; Schreiben, Hochladen, Umbenennen und Loeschen werden abgewiesen.
 * - Schreibvorgaenge werden mit Benutzer, IP-Adresse und Client
 *   protokolliert (Zuordnung eines Vorfalls).
 */
class TieringWrapper extends Wrapper {
    private TieringClient $tiering;
    private Local $local;
    private ?string $uid = null;

    public function __construct(array $parameters) {
        parent::__construct($parameters);
        $this->tiering = $parameters['tiering'];
        $this->local = $parameters['local'];
    }

    /**
     * Callback fuer Filesystem::addStorageWrapper.
     */
    public static function wrap(TieringClient $tiering, IStorage $storage): IStorage {
        if ($storage->instanceOfStorage(Jail::class)) {
            return $storage;
        }
        $local = $storage->getInstanceOfStorage(Local::class);
        if (!$local instanceof Local) {
            return $storage;
        }
        $root = rtrim($local->getSourcePath(''), '/');
        if ($root !== $tiering->dataDir() && !str_starts_with($root . '/', $tiering->dataDir() . '/')) {
            return $storage;
        }

        return new self(['storage' => $storage, 'tiering' => $tiering, 'local' => $local]);
    }

    public function fopen(string $path, string $mode) {
        $write = strpbrk($mode, 'waxc+') !== false;
        if ($write) {
            $this->guardWrite($path);
        }
        if (!$write || strpbrk($mode, 'ac') !== false || str_starts_with($mode, 'r')) {
            // Lesen, Anhaengen und Aendern brauchen den echten Inhalt.
            $this->prepareRead($path);
        }
        $result = parent::fopen($path, $mode);
        if ($result !== false && $write) {
            $this->dropMarker($path);
            $this->noteWrite($path);
        }

        return $result;
    }

    public function file_get_contents(string $path): string|false {
        $this->prepareRead($path);

        return parent::file_get_contents($path);
    }

    public function file_put_contents(string $path, mixed $data): int|float|false {
        $this->guardWrite($path);
        $result = parent::file_put_contents($path, $data);
        if ($result !== false) {
            $this->dropMarker($path);
            $this->noteWrite($path);
        }

        return $result;
    }

    public function writeStream(string $path, $stream, ?int $size = null): int {
        $this->guardWrite($path);
        $result = parent::writeStream($path, $stream, $size);
        $this->dropMarker($path);
        $this->noteWrite($path);

        return $result;
    }

    public function hash(string $type, string $path, bool $raw = false): string|false {
        $this->prepareRead($path);

        return parent::hash($type, $path, $raw);
    }

    public function getLocalFile(string $path): string|false {
        $this->prepareRead($path);

        return parent::getLocalFile($path);
    }

    public function touch(string $path, ?int $mtime = null): bool {
        $this->guardWrite($path);

        return parent::touch($path, $mtime);
    }

    public function mkdir(string $path): bool {
        $this->guardWrite($path);

        return parent::mkdir($path);
    }

    public function copy(string $source, string $target): bool {
        $this->guardWrite($target);
        $this->prepareCopy($this->rel($source));
        $result = parent::copy($source, $target);
        if ($result) {
            $this->dropMarker($target, true);
            $this->noteWrite($target);
        }

        return $result;
    }

    public function rename(string $source, string $target): bool {
        $this->guardWrite($source, $target);
        $from = $this->rel($source);
        $to = $this->rel($target);
        $result = parent::rename($source, $target);
        if ($result && $from !== null && $to !== null) {
            $this->tiering->move($from, $to);
        }
        if ($result) {
            $this->noteWrite($target);
        }

        return $result;
    }

    public function unlink(string $path): bool {
        $this->guardWrite($path);
        $result = parent::unlink($path);
        if ($result) {
            $this->dropMarker($path, true);
        }

        return $result;
    }

    public function rmdir(string $path): bool {
        $this->guardWrite($path);
        $result = parent::rmdir($path);
        if ($result && ($rel = $this->rel($path)) !== null) {
            $this->tiering->removeTree($rel);
        }

        return $result;
    }

    public function copyFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        if ($sourceStorage === $this) {
            return $this->copy($sourceInternalPath, $targetInternalPath);
        }
        $this->guardWrite($targetInternalPath);
        // Local kopiert zwischen lokalen Speichern direkt auf Dateisystemebene.
        $this->prepareCopy($this->foreignRel($sourceStorage, $sourceInternalPath));
        $result = parent::copyFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
        if ($result) {
            $this->dropMarker($targetInternalPath, true);
            $this->noteWrite($targetInternalPath);
        }

        return $result;
    }

    public function moveFromStorage(IStorage $sourceStorage, string $sourceInternalPath, string $targetInternalPath): bool {
        if ($sourceStorage === $this) {
            return $this->rename($sourceInternalPath, $targetInternalPath);
        }
        $this->guardWrite($targetInternalPath);
        $from = $this->foreignRel($sourceStorage, $sourceInternalPath);
        if ($from !== null && self::isUserFile($from) && $this->tiering->isRestricted($this->uid())) {
            throw new ForbiddenException($this->tiering->restrictionMessage(), false);
        }
        $to = $this->rel($targetInternalPath);
        $result = parent::moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
        if ($result && $to !== null) {
            if ($from !== null) {
                $this->tiering->move($from, $to);
            } else {
                $this->dropMarker($targetInternalPath, true);
            }
            $this->noteWrite($targetInternalPath);
        }

        return $result;
    }

    private function prepareRead(string $path): void {
        $rel = $this->rel($path);
        if ($rel === null || !TieringClient::isTiered($rel)) {
            return;
        }
        $preview = self::isPreviewRequest();
        if ($this->tiering->isStub($rel)) {
            if ($preview) {
                throw new StorageNotAvailableException('Für ausgelagerte Dateien (Cold-Tier) wird keine Vorschau erzeugt.');
            }
            $this->recall($rel);
        }
        if (!$preview) {
            $this->tiering->logAccess($rel);
        }
    }

    /**
     * Quelle einer Kopie vollstaendig in den Hot-Tier holen (Datei oder Ordner).
     */
    private function prepareCopy(?string $rel): void {
        if ($rel === null) {
            return;
        }
        if (TieringClient::isTiered($rel) && $this->tiering->isStub($rel)) {
            $this->recall($rel);

            return;
        }
        $dir = $this->tiering->base() . '/stubs/' . $rel;
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) {
                $sub = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($dir) + 1, -5));
                $child = $rel . '/' . $sub;
                if ($this->tiering->isStub($child)) {
                    $this->recall($child);
                }
            }
        }
    }

    private function recall(string $rel): void {
        // Die Wartezeit darf andere Anfragen derselben Sitzung (z. B. den
        // Fortschrittsbalken) nicht blockieren.
        try {
            Server::get(ISession::class)->close();
        } catch (\Throwable) {
        }
        try {
            $this->tiering->recall($rel, $this->uid());
        } catch (TieringException $exception) {
            throw new StorageNotAvailableException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Sicherheitsvorfall: Schreibzugriffe des betroffenen Benutzers auf
     * Benutzerdateien abweisen (Lesen bleibt moeglich).
     *
     * @throws ForbiddenException
     */
    private function guardWrite(string ...$paths): void {
        $uid = $this->uid();
        if ($uid === '' || !$this->tiering->isRestricted($uid)) {
            return;
        }
        foreach ($paths as $path) {
            $rel = $this->rel($path);
            if ($rel !== null && self::isUserFile($rel)) {
                throw new ForbiddenException($this->tiering->restrictionMessage(), false);
            }
        }
    }

    /**
     * Dateien, Versionen, Papierkorb und Uploads der Benutzer (nicht appdata).
     */
    private static function isUserFile(string $rel): bool {
        return preg_match('#^[^/]+/(files|files_versions|files_trashbin|uploads)(/|$)#', $rel) === 1;
    }

    private function noteWrite(string $path): void {
        $uid = $this->uid();
        $rel = $uid === '' ? null : $this->rel($path);
        if ($rel === null) {
            return;
        }
        $ip = '';
        $agent = '';
        if (PHP_SAPI !== 'cli') {
            try {
                $request = Server::get(IRequest::class);
                $ip = $request->getRemoteAddress();
                $agent = (string) $request->getHeader('User-Agent');
            } catch (\Throwable) {
            }
        }
        $this->tiering->logWrite($rel, $uid, $ip, $agent);
    }

    private function dropMarker(string $path, bool $tree = false): void {
        $rel = $this->rel($path);
        if ($rel === null) {
            return;
        }
        $this->tiering->removeMarker($rel);
        if ($tree) {
            $this->tiering->removeTree($rel);
        }
    }

    private function rel(string $path): ?string {
        return $this->tiering->relative($this->local->getSourcePath($path));
    }

    /**
     * Pfad einer Datei eines anderen (ggf. eingeschraenkten) lokalen Speichers
     * relativ zum Datenverzeichnis - wie Local::copyFromStorage ihn aufloest.
     */
    private function foreignRel(IStorage $storage, string $path): ?string {
        for ($depth = 0; $storage->instanceOfStorage(Jail::class); $depth++) {
            $jail = $storage->getInstanceOfStorage(Jail::class);
            if (!$jail instanceof Jail || $depth > 10) {
                return null;
            }
            $path = $jail->getUnjailedPath($path);
            $storage = $jail->getUnjailedStorage();
        }
        $local = $storage->getInstanceOfStorage(Local::class);
        if (!$local instanceof Local) {
            return null;
        }

        return $this->tiering->relative($local->getSourcePath($path));
    }

    private function uid(): string {
        if ($this->uid === null) {
            try {
                $this->uid = Server::get(IUserSession::class)->getUser()?->getUID() ?? '';
            } catch (\Throwable) {
                $this->uid = '';
            }
        }

        return $this->uid;
    }

    private static function isPreviewRequest(): bool {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return preg_match('#/(core/preview|core/references/preview|apps/files_sharing/publicpreview|apps/files/api/v1/thumbnail|apps/photos/api/v1/preview)#', $uri) === 1;
    }
}
