<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Security\SecretBox;
use App\Services\Storage\StorageService;

/**
 * Bindet die Speicherziele des Cold-Tiers ein – SMB-Freigaben per mount.cifs,
 * S3-kompatible Buckets per s3fs (FUSE) – und prueft Erreichbarkeit,
 * Fuellstand und Zugehoerigkeit (Kennungsdatei .lanpa-storage.json mit der
 * Instanz-ID dieser Installation). Alle weiteren Teile von storage-sync
 * arbeiten auf dem Einhaengepunkt und sind damit unabhaengig von der Art.
 */
final class Mounter
{
    public function __construct(
        private readonly string $mountBase,
        private readonly string $credentialDir,
        private readonly SecretBox $secrets,
        private readonly string $instanceId,
        private readonly int $uid = 33,
        private readonly int $gid = 33,
        private readonly string $s3TempDir = '/var/lib/storage-sync/s3-tmp'
    ) {
    }

    public function mountPoint(int $id): string
    {
        return $this->mountBase . '/' . $id;
    }

    /**
     * @param array<string,mixed> $target
     */
    public static function isS3(array $target): bool
    {
        return (string) ($target['kind'] ?? StorageService::KIND_SMB) === StorageService::KIND_S3;
    }

    /**
     * @param array<string,mixed> $target   Zeile aus storage_targets
     * @param bool                $readOnly Nur lesend einbinden (Schutzziel bei einem Sicherheitsvorfall)
     *
     * @return array{state:string,message:string,total_bytes:int,free_bytes:int,root:string,share:string}
     */
    public function check(array $target, bool $forceRemount = false, bool $readOnly = false): array
    {
        $id = (int) $target['id'];
        $s3 = self::isS3($target);
        $mountPoint = $this->mountPoint($id);
        $result = ['state' => 'offline', 'message' => '', 'total_bytes' => 0, 'free_bytes' => 0, 'root' => $mountPoint,
            'share' => $s3 ? '' : self::shareKey((string) $target['unc_path'])];

        if ((int) $target['active'] !== 1) {
            $this->unmount($id);
            $result['state'] = 'disabled';
            $result['message'] = 'Deaktiviert.';

            return $result;
        }
        // Wechsel zwischen lesend und schreibend erfordert eine neue Einbindung.
        if ($forceRemount || (($current = self::mountedReadOnly($mountPoint)) !== null && $current !== $readOnly)) {
            $this->unmount($id);
        }
        if (!self::isMounted($mountPoint)) {
            $error = $s3 ? $this->mountS3($target, $readOnly) : $this->mount($target, $readOnly);
            if ($error !== null) {
                $result['message'] = $error;

                return $result;
            }
        }

        if ($s3) {
            // s3fs beantwortet statfs lokal; erst ein Auflisten fragt den Bucket ab.
            $probe = Shell::run(['ls', '-A', '--', $mountPoint], 20);
            if ($probe['code'] !== 0) {
                $log = $this->s3Log($id);
                $this->unmount($id);
                $result['message'] = $probe['code'] === 124
                    ? 'S3-Endpunkt antwortet nicht (Zeitüberschreitung).'
                    : ($log !== '' ? self::s3MountError($log, $probe['code']) : 'Bucket nicht erreichbar.');

                return $result;
            }
            // Kein fester Speicherplatz: Fuellstand aus der angegebenen Kapazitaet
            // (Bucket-Quota) und der dorthin synchronisierten Datenmenge.
            $capacity = (int) ($target['capacity_bytes'] ?? 0);
            if ($capacity > 0) {
                $result['total_bytes'] = $capacity;
                $result['free_bytes'] = max(0, $capacity - (int) ($target['synced_bytes'] ?? 0));
            }
        } else {
            $probe = Shell::run(['stat', '-f', '-c', '%b %a %S', $mountPoint], 10);
            if ($probe['code'] !== 0 || preg_match('/^(\d+) (\d+) (\d+)/', trim($probe['out']), $m) !== 1) {
                // Haengende Verbindung loesen, damit die naechste Pruefung neu einbindet.
                $this->unmount($id);
                $result['message'] = $probe['code'] === 124 ? 'Freigabe antwortet nicht (Zeitüberschreitung).' : 'Freigabe nicht erreichbar.';

                return $result;
            }
            $result['total_bytes'] = (int) $m[1] * (int) $m[3];
            $result['free_bytes'] = (int) $m[2] * (int) $m[3];
        }

        $markerError = $this->checkMarker($mountPoint, (string) $target['label'], $readOnly, $s3);
        if ($markerError !== null) {
            $result['state'] = 'invalid';
            $result['message'] = $markerError;

            return $result;
        }
        $result['state'] = 'online';

        return $result;
    }

    public function unmount(int $id): void
    {
        $mountPoint = $this->mountPoint($id);
        if (self::isMounted($mountPoint)) {
            Shell::run(['umount', '-l', $mountPoint], 15);
        }
        @unlink($this->credentialDir . '/cred-' . $id);
        @unlink($this->credentialDir . '/s3fs-' . $id . '.log');
    }

    /**
     * Entfernt Einbindungen nicht mehr vorhandener Ziele.
     *
     * @param list<int> $keep
     */
    public function cleanup(array $keep): void
    {
        foreach (glob($this->mountBase . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (ctype_digit($id) && !in_array((int) $id, $keep, true)) {
                $this->unmount((int) $id);
                @rmdir($dir);
            }
        }
    }

    public static function isMounted(string $mountPoint, string $mountInfo = '/proc/self/mountinfo'): bool
    {
        return self::mountedReadOnly($mountPoint, $mountInfo) !== null;
    }

    /**
     * null = nicht eingebunden, sonst ob die Einbindung nur lesend ist.
     */
    public static function mountedReadOnly(string $mountPoint, string $mountInfo = '/proc/self/mountinfo'): ?bool
    {
        $raw = @file_get_contents($mountInfo);
        if ($raw === false) {
            return null;
        }
        $result = null;
        foreach (explode("\n", $raw) as $line) {
            $fields = explode(' ', $line);
            if (isset($fields[4]) && self::unescape($fields[4]) === $mountPoint) {
                // Spaetere Eintraege ueberdecken fruehere (gestapelte Einbindungen).
                $result = in_array('ro', explode(',', $fields[5] ?? ''), true);
            }
        }

        return $result;
    }

    /**
     * Schluessel fuer die CIFS-Statistik ("host\share", Kleinschreibung).
     */
    public static function shareKey(string $unc): string
    {
        $device = StorageService::mountDevice($unc);
        if ($device === null) {
            return '';
        }
        $parts = explode('/', substr($device, 2));

        return strtolower($parts[0] . '\\' . ($parts[1] ?? ''));
    }

    /**
     * Mount-Optionen (ohne Zugangsdaten).
     *
     * @param array<string,mixed> $target
     */
    public function options(array $target, ?string $credentialFile, bool $readOnly = false): string
    {
        $options = [
            $readOnly ? 'ro' : 'rw',
            $credentialFile === null ? 'guest' : 'credentials=' . $credentialFile,
            'uid=' . $this->uid,
            'gid=' . $this->gid,
            'file_mode=0660',
            'dir_mode=0770',
            'soft',
            'echo_interval=10',
            'actimeo=1',
            'nobrl',
            'noperm',
            'iocharset=utf8',
        ];
        $version = (string) ($target['smb_version'] ?? 'auto');
        if ($version !== 'auto' && array_key_exists($version, StorageService::SMB_VERSIONS)) {
            $options[] = 'vers=' . $version;
        }

        return implode(',', $options);
    }

    /**
     * @param array<string,mixed> $target
     */
    private function mount(array $target, bool $readOnly = false): ?string
    {
        $id = (int) $target['id'];
        $device = StorageService::mountDevice((string) $target['unc_path']);
        if ($device === null) {
            return 'Ungültiger UNC-Pfad.';
        }
        $mountPoint = $this->mountPoint($id);
        if (!is_dir($mountPoint) && !@mkdir($mountPoint, 0750, true) && !is_dir($mountPoint)) {
            return 'Einhängepunkt kann nicht angelegt werden.';
        }

        $credentialFile = null;
        if ((string) $target['username'] !== '') {
            $password = $this->decryptPassword($target);
            if ($password === null) {
                return 'Kennwort kann nicht entschlüsselt werden (Schlüssel geändert?). Bitte neu eingeben.';
            }
            $content = 'username=' . $target['username'] . "\n" . 'password=' . $password . "\n";
            if ((string) $target['domain'] !== '') {
                $content .= 'domain=' . $target['domain'] . "\n";
            }
            $credentialFile = $this->writeCredentials($id, $content);
            if ($credentialFile === null) {
                return 'Zugangsdaten können nicht bereitgestellt werden.';
            }
        }

        $run = Shell::run(['mount', '-t', 'cifs', $device, $mountPoint, '-o', $this->options($target, $credentialFile, $readOnly)], 30);
        if ($run['code'] === 0) {
            return null;
        }

        return self::mountError($run['err'], $run['code']);
    }

    /**
     * Quelle fuer s3fs: bucket bzw. bucket:/praefix.
     *
     * @param array<string,mixed> $target
     */
    public static function s3Source(array $target): string
    {
        $prefix = trim((string) ($target['s3_prefix'] ?? ''), '/');

        return (string) $target['s3_bucket'] . ($prefix !== '' ? ':/' . $prefix : '');
    }

    /**
     * Optionen fuer s3fs (ohne Zugangsdaten – die stehen in der passwd-Datei).
     *
     * @param array<string,mixed> $target
     */
    public function s3Options(array $target, string $passwdFile, bool $readOnly = false, ?string $logFile = null): string
    {
        $options = [
            'passwd_file=' . $passwdFile,
            'url=' . (string) $target['s3_endpoint'],
            'uid=' . $this->uid,
            'gid=' . $this->gid,
            'umask=0007',
            'mp_umask=0007',
            'tmpdir=' . $this->s3TempDir,
            'retries=3',
            'connect_timeout=10',
            'readwrite_timeout=60',
            'stat_cache_expire=30',
            'complement_stat',
            // Präfix und Ordner ohne eigenes Verzeichnisobjekt (z. B. von anderen Werkzeugen angelegt) zulassen;
            // ohne diese Option verweigert s3fs den Mount eines noch nicht existierenden Präfixes.
            'compat_dir',
            'dbglevel=err',
        ];
        if ($readOnly) {
            array_unshift($options, 'ro');
        }
        if ((string) ($target['s3_region'] ?? '') !== '') {
            $options[] = 'endpoint=' . $target['s3_region'];
        }
        if ((int) ($target['s3_path_style'] ?? 1) === 1) {
            $options[] = 'use_path_request_style';
        }
        if ((int) ($target['s3_verify_tls'] ?? 1) !== 1) {
            $options[] = 'no_check_certificate';
            $options[] = 'ssl_verify_hostname=0';
        }
        if ($logFile !== null) {
            $options[] = 'logfile=' . $logFile;
        }

        return implode(',', $options);
    }

    /**
     * @param array<string,mixed> $target
     */
    private function mountS3(array $target, bool $readOnly = false): ?string
    {
        $id = (int) $target['id'];
        if ((string) ($target['s3_bucket'] ?? '') === '' || (string) ($target['s3_endpoint'] ?? '') === '') {
            return 'S3-Ziel unvollständig (Endpunkt oder Bucket fehlt).';
        }
        if (!is_executable('/usr/bin/s3fs')) {
            return 's3fs ist im Container nicht installiert (Image von storage-sync neu bauen).';
        }
        if (!file_exists('/dev/fuse')) {
            return 'FUSE ist im Container nicht verfügbar (/dev/fuse fehlt).';
        }
        $mountPoint = $this->mountPoint($id);
        if (!is_dir($mountPoint) && !@mkdir($mountPoint, 0750, true) && !is_dir($mountPoint)) {
            return 'Einhängepunkt kann nicht angelegt werden.';
        }
        if (!is_dir($this->s3TempDir)) {
            @mkdir($this->s3TempDir, 0700, true);
        }

        $secret = $this->decryptPassword($target);
        if ($secret === null || $secret === '') {
            return 'Secret Access Key fehlt oder kann nicht entschlüsselt werden (Schlüssel geändert?). Bitte neu eingeben.';
        }
        $passwdFile = $this->writeCredentials($id, $target['username'] . ':' . $secret . "\n");
        if ($passwdFile === null) {
            return 'Zugangsdaten können nicht bereitgestellt werden.';
        }
        $logFile = $this->credentialDir . '/s3fs-' . $id . '.log';
        @unlink($logFile);

        $run = Shell::run(['s3fs', self::s3Source($target), $mountPoint, '-o', $this->s3Options($target, $passwdFile, $readOnly, $logFile)], 45);
        if ($run['code'] === 0 && self::isMounted($mountPoint)) {
            // s3fs meldet Erfolg, sobald FUSE eingehängt ist, und prüft Bucket und Zugang erst danach;
            // schlägt das fehl, verschwindet der Mount wieder. Ein Listing wartet diese Prüfung ab.
            $probe = Shell::run(['ls', '-A', $mountPoint], 20);
            if ($probe['code'] === 0 && self::isMounted($mountPoint)) {
                return null;
            }
            Shell::run(['umount', '-l', $mountPoint], 10);
            $run['err'] .= "\n" . $probe['err'];
        }
        $log = trim($run['err'] . "\n" . $this->s3Log($id));

        return self::s3MountError($log, $run['code']);
    }

    /**
     * Letzte Zeilen des s3fs-Protokolls (nur Fehler, ohne Zugangsdaten).
     */
    private function s3Log(int $id): string
    {
        $raw = @file_get_contents($this->credentialDir . '/s3fs-' . $id . '.log');
        if ($raw === false || $raw === '') {
            return '';
        }
        $lines = array_slice(array_filter(explode("\n", $raw), static fn (string $l): bool => trim($l) !== ''), -10);

        return implode("\n", $lines);
    }

    /**
     * Verstaendliche Fehlermeldung aus der Ausgabe bzw. dem Protokoll von s3fs.
     */
    public static function s3MountError(string $error, int $code): string
    {
        $map = [
            'InvalidAccessKeyId' => 'Anmeldung abgelehnt: Access Key ID unbekannt.',
            'SignatureDoesNotMatch' => 'Anmeldung abgelehnt: Secret Access Key falsch.',
            'invalid credentials' => 'Anmeldung abgelehnt (Access Key ID und Secret Access Key prüfen).',
            'AccessDenied' => 'Zugriff verweigert (Berechtigungen des Schlüssels auf den Bucket prüfen).',
            'HTTP response code 403' => 'Zugriff verweigert (Access Key, Secret Key und Berechtigungen auf den Bucket prüfen).',
            'NoSuchBucket' => 'Bucket nicht gefunden.',
            'bucket not found' => 'Bucket nicht gefunden.',
            'specified bucket does not exist' => 'Bucket nicht gefunden.',
            'Bucket or directory' => 'Bucket nicht gefunden.',
            'HTTP response code 404' => 'Bucket nicht gefunden.',
            'AuthorizationHeaderMalformed' => 'Falsche Region für den Bucket (Feld „Region“ prüfen).',
            'PermanentRedirect' => 'Falsche Region oder falscher Endpunkt für den Bucket.',
            'Could not resolve host' => 'Name des S3-Endpunkts kann nicht aufgelöst werden.',
            'Couldn\'t resolve host' => 'Name des S3-Endpunkts kann nicht aufgelöst werden.',
            'SSL certificate problem' => 'TLS-Zertifikat des S3-Endpunkts wird nicht vertraut.',
            'SSL peer certificate' => 'TLS-Zertifikat des S3-Endpunkts wird nicht vertraut.',
            'Connection refused' => 'S3-Endpunkt lehnt die Verbindung ab (Adresse und Port prüfen).',
            'Couldn\'t connect' => 'S3-Endpunkt nicht erreichbar.',
            'Failed to connect' => 'S3-Endpunkt nicht erreichbar.',
            'Timeout was reached' => 'S3-Endpunkt antwortet nicht (Zeitüberschreitung).',
            'fuse: device not found' => 'FUSE ist im Container nicht verfügbar (/dev/fuse fehlt).',
            '/dev/fuse' => 'FUSE ist im Container nicht verfügbar (/dev/fuse, Gerätefreigabe prüfen).',
            'Operation not permitted' => 'Einbinden nicht erlaubt (Container benötigt CAP_SYS_ADMIN und /dev/fuse).',
            'Transport endpoint is not connected' => 'Verbindung zum Bucket abgebrochen – wird neu eingebunden.',
        ];
        foreach ($map as $needle => $message) {
            if (stripos($error, $needle) !== false) {
                return $message;
            }
        }
        if ($code === 124) {
            return 'Zeitüberschreitung beim Einbinden des Buckets.';
        }
        $error = (string) preg_replace('/\s+/', ' ', $error);

        return 'Einbinden des Buckets fehlgeschlagen' . ($error !== '' ? ': ' . mb_substr($error, 0, 200) : '.');
    }

    /**
     * @param array<string,mixed> $target
     */
    private function decryptPassword(array $target): ?string
    {
        $password = (string) ($target['password'] ?? '');
        if ($password !== '' && SecretBox::isEncrypted($password)) {
            try {
                $decrypted = $this->secrets->decrypt($password);
            } catch (\Throwable) {
                return null;
            }

            return $decrypted === null ? null : (string) $decrypted;
        }

        return $password;
    }

    /**
     * Zugangsdatei mit Rechten 0600 (tmpfs, nur im Arbeitsspeicher).
     */
    private function writeCredentials(int $id, string $content): ?string
    {
        if (!is_dir($this->credentialDir)) {
            @mkdir($this->credentialDir, 0700, true);
        }
        $file = $this->credentialDir . '/cred-' . $id;
        $old = umask(0077);
        $written = @file_put_contents($file, $content);
        umask($old);
        if ($written === false) {
            return null;
        }
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Verstaendliche Fehlermeldung aus der Ausgabe von mount.cifs.
     */
    public static function mountError(string $error, int $code): string
    {
        $map = [
            'Permission denied' => 'Anmeldung abgelehnt (Benutzer, Kennwort oder Domäne prüfen).',
            'No such file or directory' => 'Freigabe oder Unterordner nicht gefunden.',
            'Host is down' => 'Server nicht erreichbar.',
            'No route to host' => 'Server nicht erreichbar (keine Route).',
            'Connection refused' => 'Server lehnt SMB-Verbindungen ab.',
            'could not resolve address' => 'Servername kann nicht aufgelöst werden.',
            'Operation not supported' => 'SMB-Version wird vom Server nicht unterstützt.',
            'Operation not permitted' => 'Einbinden nicht erlaubt (Container benötigt CAP_SYS_ADMIN).',
        ];
        foreach ($map as $needle => $message) {
            if (stripos($error, $needle) !== false) {
                return $message;
            }
        }
        if ($code === 124) {
            return 'Zeitüberschreitung beim Einbinden.';
        }

        return 'Einbinden fehlgeschlagen' . ($error !== '' ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $error) ?? '', 0, 200) : '.');
    }

    /**
     * Kennungsdatei pruefen bzw. anlegen. Liefert eine Fehlermeldung oder null.
     */
    private function checkMarker(string $root, string $label, bool $readOnly = false, bool $s3 = false): ?string
    {
        $place = $s3 ? 'Der Bucket' : 'Die Freigabe';
        $file = $root . '/' . PathRules::TARGET_MARKER;
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            $instance = is_array($data) ? (string) ($data['instance'] ?? '') : '';
            if ($instance !== '' && $instance !== $this->instanceId) {
                return $place . ' enthält Daten einer anderen Installation (Kennung ' . substr($instance, 0, 8) . '…). '
                    . 'Bitte ' . ($s3 ? 'einen leeren Bucket bzw. ein unbenutztes Präfix' : 'einen leeren Ordner') . ' angeben oder die Daten per Wiederherstellung übernehmen.';
            }
            if ($instance !== '') {
                return null;
            }
        }
        if ($readOnly) {
            // Schreibgeschuetzt eingebunden: Kennung kann nicht angelegt werden.
            return $raw === false ? null : 'Kennungsdatei ' . ($s3 ? 'im Bucket' : 'der Freigabe') . ' ist ungültig.';
        }
        $data = ['instance' => $this->instanceId, 'created_at' => gmdate('c'), 'label' => $label];
        if (@file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return $s3
                ? 'In den Bucket kann nicht geschrieben werden (Schreibrechte des Access Keys bzw. Object Lock prüfen).'
                : 'Auf der Freigabe kann nicht geschrieben werden (Schreibrechte prüfen).';
        }

        return null;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $value);
    }
}
