<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Storage;

/**
 * Gegenstueck zu App\Services\Storage\Agent\TieringStore (Container
 * storage-sync) im gemeinsamen Volume storage_tiering:
 *
 *   config.json             {enabled, recall_timeout, restricted, restriction_message}
 *   agent.alive             Lebenszeichen der Rueckholung (alle 0,25 s)
 *   stubs/<pfad>.json       Kennzeichen ausgelagerter Dateien (Cold-Tier)
 *   recall/queue/<id>.json  Rueckhol-Auftraege {path, uid, requested_at}
 *   recall/status/<id>.json Fortschritt {path, uid, request, state, bytes, total, ...}
 *   access/access.log       Zugriffe "<unix-zeit> <pfad>"
 *   access/writes.log       Schreibvorgaenge (JSON je Zeile {t, u, ip, ua, p}),
 *                           Zuordnung von Sicherheitsvorfaellen zum Benutzer
 *
 * Pfade sind relativ zum Nextcloud-Datenverzeichnis. Eine ausgelagerte Datei
 * ist im Hot-Tier ein "sparse" Platzhalter gleicher Groesse.
 *
 * Bewusst ohne Nextcloud-Abhaengigkeiten (testbar, siehe tests/Unit).
 */
class TieringClient {
    public const DEFAULT_BASE = '/var/lib/lanpa-tiering';
    public const ALIVE_SECONDS = 30;
    public const POLL_MICROSECONDS = 200000;

    /** Nur diese Pfade koennen ausgelagert sein (wie PathRules::TIERED). */
    private const TIERED = '#^[^/]+/(files|files_versions|files_trashbin)/#';

    /** Schreibprotokoll nicht weiter fuellen, wenn storage-sync es nicht abholt. */
    public const WRITE_LOG_MAX_BYTES = 64 * 1024 * 1024;

    private ?array $config = null;
    /** @var array<string,true> */
    private array $logged = [];
    /** @var array<string,true> */
    private array $writes = [];
    /** @var callable(int):void */
    private $sleeper;
    /** @var callable():int */
    private $clock;

    public function __construct(
        private string $base,
        private string $dataDir,
        ?callable $sleeper = null,
        ?callable $clock = null,
    ) {
        $this->base = rtrim($base, '/');
        $this->dataDir = rtrim($dataDir, '/');
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function base(): string {
        return $this->base;
    }

    public function dataDir(): string {
        return $this->dataDir;
    }

    public static function recallId(string $rel): string {
        return sha1($rel);
    }

    /**
     * Speicher-Tiering eingerichtet? (Volume vorhanden, vom Agenten freigegeben)
     */
    public function enabled(): bool {
        $config = $this->config();

        return ($config['enabled'] ?? false) === true || is_dir($this->base . '/stubs') && $this->hasStubs();
    }

    public function recallTimeout(): int {
        $timeout = (int) ($this->config()['recall_timeout'] ?? 600);

        return max(30, min(7200, $timeout));
    }

    /**
     * Wegen eines Sicherheitsvorfalls nur lesender Zugriff fuer diesen Benutzer?
     */
    public function isRestricted(string $uid): bool {
        $restricted = $this->config()['restricted'] ?? [];

        return $uid !== '' && is_array($restricted) && in_array($uid, $restricted, true);
    }

    public function restrictionMessage(): string {
        $message = $this->config()['restriction_message'] ?? '';

        return is_string($message) && $message !== ''
            ? $message
            : 'Der Zugriff auf Ihre Dateien wurde aus Sicherheitsgründen vorübergehend eingeschränkt. Bitte melden Sie sich beim Support.';
    }

    /**
     * Schreibvorgang protokollieren (wer hat welche Datei geschrieben),
     * einmal je Pfad und Anfrage. Grundlage fuer die Zuordnung eines
     * Sicherheitsvorfalls zum angemeldeten Benutzer und dessen Client.
     */
    public function logWrite(string $rel, string $uid, string $ip = '', string $userAgent = ''): void {
        if ($uid === '' || preg_match('#^[^/]+/files/.#', $rel) !== 1 || isset($this->writes[$rel]) || str_contains($rel, "\n")) {
            return;
        }
        $this->writes[$rel] = true;
        $log = $this->base . '/access/writes.log';
        clearstatcache(true, $log);
        if ((int) @filesize($log) > self::WRITE_LOG_MAX_BYTES) {
            return;
        }
        $line = json_encode([
            't' => ($this->clock)(),
            'u' => $uid,
            'ip' => mb_substr($ip, 0, 64),
            'ua' => mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $userAgent) ?? '', 0, 200),
            'p' => $rel,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line !== false) {
            @file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function agentAlive(): bool {
        $mtime = @filemtime($this->base . '/agent.alive');

        return $mtime !== false && ($this->clock)() - $mtime <= self::ALIVE_SECONDS;
    }

    /**
     * Pfad relativ zum Datenverzeichnis (oder null, wenn ausserhalb).
     */
    public function relative(string $absolute): ?string {
        $base = $this->dataDir . '/';
        if (!str_starts_with($absolute, $base)) {
            return null;
        }
        $rel = trim(substr($absolute, strlen($base)), '/');
        if ($rel === '' || str_contains('/' . $rel . '/', '/../') || str_contains('/' . $rel . '/', '/./')) {
            return null;
        }

        return $rel;
    }

    public static function isTiered(string $rel): bool {
        return preg_match(self::TIERED, $rel) === 1;
    }

    public function markerPath(string $rel): string {
        return $this->base . '/stubs/' . $rel . '.json';
    }

    public function hasMarker(string $rel): bool {
        return self::isTiered($rel) && is_file($this->markerPath($rel));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readMarker(string $rel): ?array {
        $raw = @file_get_contents($this->markerPath($rel));
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Ist die Datei derzeit nur ein Platzhalter (Inhalt im Cold-Tier)?
     */
    public function isStub(string $rel): bool {
        if (!$this->hasMarker($rel)) {
            return false;
        }
        $marker = $this->readMarker($rel);
        if ($marker === null) {
            return false;
        }
        $abs = $this->dataDir . '/' . $rel;
        clearstatcache(true, $abs);
        $stat = @stat($abs);
        if ($stat === false || (int) $stat['size'] !== (int) ($marker['size'] ?? -1)) {
            return false;
        }
        $blocks = (int) ($stat['blocks'] ?? -1);
        $allocated = $blocks < 0 ? (int) $stat['size'] : $blocks * 512;

        return $allocated < 4096 && (int) $stat['size'] > 0;
    }

    /**
     * Holt eine ausgelagerte Datei zurueck und wartet auf den Abschluss.
     *
     * @throws TieringException
     */
    public function recall(string $rel, string $uid = ''): void {
        if (!$this->isStub($rel)) {
            return;
        }
        if (!$this->agentAlive()) {
            throw new TieringException(
                'Die Datei liegt im Cold-Tier (SMB-/S3-Tier) und kann derzeit nicht zurückgeholt werden: Der Dienst storage-sync antwortet nicht.'
            );
        }

        $id = self::recallId($rel);
        $reference = $this->enqueue($id, $rel, $uid);
        $deadline = ($this->clock)() + $this->recallTimeout();

        while (true) {
            $status = $this->status($id);
            $current = $status !== null && (int) ($status['request'] ?? 0) >= $reference;
            if ($current && ($status['state'] ?? '') === 'done' && !$this->isStub($rel)) {
                return;
            }
            if ($current && ($status['state'] ?? '') === 'failed') {
                $message = is_string($status['message'] ?? null) && $status['message'] !== ''
                    ? $status['message'] : 'Rückholung fehlgeschlagen.';
                throw new TieringException('Die Datei konnte nicht aus dem Cold-Tier (SMB-/S3-Tier) zurückgeholt werden: ' . $message);
            }
            if (!$this->isStub($rel)) {
                // Inzwischen anderweitig zurueckgeholt oder ersetzt.
                return;
            }
            if (!$this->agentAlive()) {
                throw new TieringException('Rückholung abgebrochen: Der Dienst storage-sync antwortet nicht mehr.');
            }
            if (($this->clock)() >= $deadline) {
                throw new TieringException('Die Rückholung aus dem Cold-Tier (SMB-/S3-Tier) dauert noch an. Bitte in Kürze erneut versuchen.');
            }
            ($this->sleeper)(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Legt einen Rueckhol-Auftrag an (oder uebernimmt einen bestehenden) und
     * liefert dessen Zeitpunkt (Bezug fuer die Statusdatei).
     */
    public function enqueue(string $id, string $rel, string $uid): int {
        $file = $this->base . '/recall/queue/' . $id . '.json';
        $existing = json_decode((string) @file_get_contents($file), true);
        if (is_array($existing) && isset($existing['requested_at'])) {
            return (int) $existing['requested_at'];
        }
        $now = ($this->clock)();
        $this->writeJson($file, ['path' => $rel, 'uid' => $uid, 'requested_at' => $now]);

        return $now;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function status(string $id): ?array {
        $data = json_decode((string) @file_get_contents($this->base . '/recall/status/' . $id . '.json'), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Laufende, wartende und gerade abgeschlossene Rueckholungen eines Benutzers
     * (Fortschrittsbalken im Browser).
     *
     * @return list<array{id:string,name:string,state:string,bytes:int,total:int,percent:int,message:string}>
     */
    public function recallsFor(string $uid, int $recentSeconds = 8): array {
        $now = ($this->clock)();
        $items = [];
        foreach (glob($this->base . '/recall/status/*.json') ?: [] as $file) {
            $status = json_decode((string) @file_get_contents($file), true);
            if (!is_array($status) || ($status['uid'] ?? null) !== $uid || !is_string($status['path'] ?? null)) {
                continue;
            }
            $state = (string) ($status['state'] ?? '');
            $finished = (int) ($status['finished'] ?? $status['updated'] ?? 0);
            if ($state !== 'running' && $now - $finished > $recentSeconds) {
                continue;
            }
            $items[basename($file, '.json')] = $this->item(basename($file, '.json'), $status, $state);
        }
        foreach (glob($this->base . '/recall/queue/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            $request = json_decode((string) @file_get_contents($file), true);
            if (isset($items[$id]) || !is_array($request) || ($request['uid'] ?? null) !== $uid || !is_string($request['path'] ?? null)) {
                continue;
            }
            $marker = $this->readMarker($request['path']);
            $items[$id] = $this->item($id, ['path' => $request['path'], 'bytes' => 0, 'total' => (int) ($marker['size'] ?? 0)], 'queued');
        }

        return array_values($items);
    }

    /**
     * Zugriff fuer "haeufig genutzt" vermerken (einmal je Pfad und Anfrage).
     */
    public function logAccess(string $rel): void {
        if (!self::isTiered($rel) || isset($this->logged[$rel]) || str_contains($rel, "\n")) {
            return;
        }
        $this->logged[$rel] = true;
        @file_put_contents($this->base . '/access/access.log', ($this->clock)() . ' ' . $rel . "\n", FILE_APPEND | LOCK_EX);
    }

    public function removeMarker(string $rel): void {
        if (!self::isTiered($rel)) {
            return;
        }
        $path = $this->markerPath($rel);
        if (is_file($path)) {
            @unlink($path);
            $this->pruneDirs(dirname($path));
        }
    }

    /**
     * Verzeichnis geloescht: alle Kennzeichen darunter entfernen.
     */
    public function removeTree(string $rel): void {
        if (!self::isTiered($rel . '/')) {
            return;
        }
        $dir = $this->base . '/stubs/' . $rel;
        if (is_dir($dir) && !is_link($dir)) {
            $this->deleteDir($dir);
            $this->pruneDirs(dirname($dir));
        }
    }

    /**
     * Datei oder Verzeichnis umbenannt/verschoben: Kennzeichen mitnehmen.
     */
    public function move(string $from, string $to): void {
        $fromTiered = self::isTiered($from) || self::isTiered($from . '/');
        $toTiered = self::isTiered($to) || self::isTiered($to . '/');
        if (!$fromTiered && !$toTiered) {
            return;
        }
        // Ein ggf. ueberschriebenes Ziel ist kein Platzhalter mehr.
        $this->removeMarker($to);
        $this->removeTree($to);
        if (!$fromTiered) {
            return;
        }

        $marker = $this->readMarker($from);
        if ($marker !== null) {
            if ($toTiered) {
                $marker['path'] = $to;
                $this->writeJson($this->markerPath($to), $marker);
            }
            $this->removeMarker($from);
        }

        $fromDir = $this->base . '/stubs/' . $from;
        if (is_dir($fromDir) && !is_link($fromDir)) {
            if ($toTiered) {
                $toDir = $this->base . '/stubs/' . $to;
                $this->ensureDir(dirname($toDir));
                if (!@rename($fromDir, $toDir)) {
                    $this->deleteDir($fromDir);
                } else {
                    $this->rewritePaths($toDir, $to);
                }
            } else {
                $this->deleteDir($fromDir);
            }
            $this->pruneDirs(dirname($fromDir));
        }
    }

    /**
     * @param array<string,mixed> $status
     *
     * @return array{id:string,name:string,state:string,bytes:int,total:int,percent:int,message:string}
     */
    private function item(string $id, array $status, string $state): array {
        $path = (string) $status['path'];
        $name = preg_replace('#^[^/]+/files/#', '', $path) ?? $path;
        $bytes = max(0, (int) ($status['bytes'] ?? 0));
        $total = max(0, (int) ($status['total'] ?? 0));
        $percent = $state === 'done' ? 100 : ($total > 0 ? (int) min(100, floor(100 * $bytes / $total)) : 0);

        return [
            'id' => $id,
            'name' => $name,
            'state' => $state,
            'bytes' => $bytes,
            'total' => $total,
            'percent' => $percent,
            'message' => is_string($status['message'] ?? null) ? $status['message'] : '',
        ];
    }

    private function rewritePaths(string $dir, string $rel): void {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
                continue;
            }
            $data = json_decode((string) @file_get_contents($file->getPathname()), true);
            if (!is_array($data)) {
                continue;
            }
            $sub = substr($file->getPathname(), strlen($dir) + 1, -5);
            $data['path'] = $rel . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $sub);
            $this->writeJson($file->getPathname(), $data);
        }
    }

    private function config(): array {
        if ($this->config === null) {
            $data = json_decode((string) @file_get_contents($this->base . '/config.json'), true);
            $this->config = is_array($data) ? $data : [];
        }

        return $this->config;
    }

    private function hasStubs(): bool {
        $handle = @opendir($this->base . '/stubs');
        if ($handle === false) {
            return false;
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);

                return true;
            }
        }
        closedir($handle);

        return false;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeJson(string $file, array $data): void {
        $this->ensureDir(dirname($file));
        $temp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new TieringException('Speicher-Tiering: Datei kann nicht geschrieben werden.');
        }
        @chmod($temp, 0660);
        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new TieringException('Speicher-Tiering: Datei kann nicht ersetzt werden.');
        }
    }

    private function ensureDir(string $dir): void {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new TieringException('Speicher-Tiering: Verzeichnis kann nicht angelegt werden.');
        }
    }

    private function deleteDir(string $dir): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }

    private function pruneDirs(string $dir): void {
        $stop = $this->base . '/stubs';
        while ($dir !== $stop && str_starts_with($dir, $stop . '/') && @rmdir($dir)) {
            $dir = dirname($dir);
        }
    }
}
