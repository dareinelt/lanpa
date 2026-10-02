<?php

declare(strict_types=1);

namespace App\Services\Storage\Agent;

use App\Security\SecretBox;
use App\Services\Storage\StorageService;

/**
 * Bindet die Speicherziele des Cold-Tiers (SMB-Tier) per mount.cifs ein und
 * prueft Erreichbarkeit, Fuellstand und Zugehoerigkeit (Kennungsdatei
 * .lanpa-storage.json mit der Instanz-ID dieser Installation).
 */
final class Mounter
{
    public function __construct(
        private readonly string $mountBase,
        private readonly string $credentialDir,
        private readonly SecretBox $secrets,
        private readonly string $instanceId,
        private readonly int $uid = 33,
        private readonly int $gid = 33
    ) {
    }

    public function mountPoint(int $id): string
    {
        return $this->mountBase . '/' . $id;
    }

    /**
     * @param array<string,mixed> $target Zeile aus storage_targets
     *
     * @return array{state:string,message:string,total_bytes:int,free_bytes:int,root:string,share:string}
     */
    public function check(array $target, bool $forceRemount = false): array
    {
        $id = (int) $target['id'];
        $mountPoint = $this->mountPoint($id);
        $result = ['state' => 'offline', 'message' => '', 'total_bytes' => 0, 'free_bytes' => 0, 'root' => $mountPoint, 'share' => self::shareKey((string) $target['unc_path'])];

        if ((int) $target['active'] !== 1) {
            $this->unmount($id);
            $result['state'] = 'disabled';
            $result['message'] = 'Deaktiviert.';

            return $result;
        }
        if ($forceRemount) {
            $this->unmount($id);
        }
        if (!self::isMounted($mountPoint)) {
            $error = $this->mount($target);
            if ($error !== null) {
                $result['message'] = $error;

                return $result;
            }
        }

        $probe = Shell::run(['stat', '-f', '-c', '%b %a %S', $mountPoint], 10);
        if ($probe['code'] !== 0 || preg_match('/^(\d+) (\d+) (\d+)/', trim($probe['out']), $m) !== 1) {
            // Haengende Verbindung loesen, damit die naechste Pruefung neu einbindet.
            $this->unmount($id);
            $result['message'] = $probe['code'] === 124 ? 'Freigabe antwortet nicht (Zeitüberschreitung).' : 'Freigabe nicht erreichbar.';

            return $result;
        }
        $result['total_bytes'] = (int) $m[1] * (int) $m[3];
        $result['free_bytes'] = (int) $m[2] * (int) $m[3];

        $markerError = $this->checkMarker($mountPoint, (string) $target['label']);
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
        $raw = @file_get_contents($mountInfo);
        if ($raw === false) {
            return false;
        }
        foreach (explode("\n", $raw) as $line) {
            $fields = explode(' ', $line);
            if (isset($fields[4]) && self::unescape($fields[4]) === $mountPoint) {
                return true;
            }
        }

        return false;
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
    public function options(array $target, ?string $credentialFile): string
    {
        $options = [
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
    private function mount(array $target): ?string
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
            $password = (string) ($target['password'] ?? '');
            if ($password !== '' && SecretBox::isEncrypted($password)) {
                try {
                    $password = (string) $this->secrets->decrypt($password);
                } catch (\Throwable) {
                    return 'Kennwort kann nicht entschlüsselt werden (Schlüssel geändert?). Bitte neu eingeben.';
                }
            }
            if (!is_dir($this->credentialDir)) {
                @mkdir($this->credentialDir, 0700, true);
            }
            $credentialFile = $this->credentialDir . '/cred-' . $id;
            $content = 'username=' . $target['username'] . "\n" . 'password=' . $password . "\n";
            if ((string) $target['domain'] !== '') {
                $content .= 'domain=' . $target['domain'] . "\n";
            }
            $old = umask(0077);
            $written = @file_put_contents($credentialFile, $content);
            umask($old);
            if ($written === false) {
                return 'Zugangsdaten können nicht bereitgestellt werden.';
            }
            @chmod($credentialFile, 0600);
        }

        $run = Shell::run(['mount', '-t', 'cifs', $device, $mountPoint, '-o', $this->options($target, $credentialFile)], 30);
        if ($run['code'] === 0) {
            return null;
        }

        return self::mountError($run['err'], $run['code']);
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
    private function checkMarker(string $root, string $label): ?string
    {
        $file = $root . '/' . PathRules::TARGET_MARKER;
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = json_decode($raw, true);
            $instance = is_array($data) ? (string) ($data['instance'] ?? '') : '';
            if ($instance !== '' && $instance !== $this->instanceId) {
                return 'Die Freigabe enthält Daten einer anderen Installation (Kennung ' . substr($instance, 0, 8) . '…). '
                    . 'Bitte einen leeren Ordner angeben oder die Daten per Wiederherstellung übernehmen.';
            }
            if ($instance !== '') {
                return null;
            }
        }
        $data = ['instance' => $this->instanceId, 'created_at' => gmdate('c'), 'label' => $label];
        if (@file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            return 'Auf der Freigabe kann nicht geschrieben werden (Schreibrechte prüfen).';
        }

        return null;
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $value);
    }
}
