<?php

declare(strict_types=1);

namespace App\Services\Orvanta;

use App\Repositories\OrvantaRepository;
use App\Security\SecretBox;
use App\Services\Office\NextcloudFilesService;
use App\Services\Office\OfficeConfigService;
use App\Services\Office\OfficeJwt;

/**
 * Anhaenge in Orvanta: kurzlebige signierte Links zum Oeffnen in Euro-Office
 * (neuer Tab, DocumentServer ruft die Datei ueber das Intranet ab), Ablage im
 * persoenlichen Nextcloud-Bereich und Zwischenspeicher empfangener Inhalte
 * im Nextcloud-Ordner des Benutzers mit eigenem Quota (aelteste Eintraege
 * werden verdraengt).
 */
final class OrvantaAttachmentService
{
    public const TOKEN_LIFETIME = 300;
    private const AUDIENCE = 'orvanta_attachment';

    /** @var array<string,string> Dateiendung => Euro-Office-Dokumenttyp */
    private const OFFICE_TYPES = [
        'doc' => 'word', 'docx' => 'word', 'docm' => 'word', 'dot' => 'word', 'dotx' => 'word', 'odt' => 'word', 'ott' => 'word', 'rtf' => 'word', 'txt' => 'word', 'pdf' => 'pdf', 'djvu' => 'pdf', 'xps' => 'pdf', 'epub' => 'word', 'fb2' => 'word', 'html' => 'word', 'htm' => 'word', 'mht' => 'word',
        'xls' => 'cell', 'xlsx' => 'cell', 'xlsm' => 'cell', 'xlt' => 'cell', 'xltx' => 'cell', 'ods' => 'cell', 'ots' => 'cell', 'csv' => 'cell',
        'ppt' => 'slide', 'pptx' => 'slide', 'pptm' => 'slide', 'pot' => 'slide', 'potx' => 'slide', 'odp' => 'slide', 'otp' => 'slide', 'ppsx' => 'slide', 'pps' => 'slide',
    ];

    /** @var list<string> Direkt im Browser darstellbar */
    private const BROWSER_TYPES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'txt', 'pdf'];

    public function __construct(
        private readonly OrvantaRepository $repository,
        private readonly OrvantaConfigService $config,
        private readonly OfficeConfigService $office,
        private readonly NextcloudFilesService $nextcloud,
        private readonly OrvantaExchangeService $exchange,
        private readonly SecretBox $secrets
    ) {
    }

    /**
     * Wie ein Anhang geoeffnet wird: Euro-Office-Editor, Browser oder Download.
     *
     * @return 'office'|'browser'|'download'
     */
    public static function openMode(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (isset(self::OFFICE_TYPES[$ext])) {
            return 'office';
        }

        return in_array($ext, self::BROWSER_TYPES, true) ? 'browser' : 'download';
    }

    /**
     * Kann der Browser die Datei selbst darstellen (Fallback ohne DocumentServer)?
     */
    public static function browserCapable(string $name): bool
    {
        return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::BROWSER_TYPES, true);
    }

    public static function documentType(string $name): string
    {
        return self::OFFICE_TYPES[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'word';
    }

    public function officeAvailable(): bool
    {
        return $this->office->isEnabled() && $this->office->jwtSecret() !== '';
    }

    /**
     * Kurzlebiges Token fuer den Zugriff auf einen Anhang des Benutzers.
     */
    public function token(string $uid, string $impersonate, string $attachmentId, string $name, ?int $now = null): string
    {
        $now ??= time();

        return OfficeJwt::encode([
            'aud' => self::AUDIENCE,
            'sub' => $uid,
            'imp' => $impersonate,
            'att' => $attachmentId,
            'name' => $name,
            'iat' => $now,
            'exp' => $now + self::TOKEN_LIFETIME,
        ], $this->signingSecret());
    }

    /**
     * @return array{uid:string,impersonate:string,attachment_id:string,name:string}|null
     */
    public function verify(string $token, ?int $now = null): ?array
    {
        $claims = OfficeJwt::decode($token, $this->signingSecret(), $now);
        if ($claims === null || ($claims['aud'] ?? '') !== self::AUDIENCE) {
            return null;
        }
        $uid = (string) ($claims['sub'] ?? '');
        $attachment = (string) ($claims['att'] ?? '');
        if ($uid === '' || $attachment === '') {
            return null;
        }

        return [
            'uid' => $uid,
            'impersonate' => (string) ($claims['imp'] ?? ''),
            'attachment_id' => $attachment,
            'name' => (string) ($claims['name'] ?? 'anhang'),
        ];
    }

    /**
     * Laedt einen Anhang: zuerst aus dem Nextcloud-Zwischenspeicher, sonst von
     * Exchange (und legt ihn danach im Zwischenspeicher ab).
     *
     * @return array{name:string,content_type:string,content:string,size:int,cached:bool}
     */
    public function load(string $uid, string $impersonate, string $attachmentId): array
    {
        $hash = sha1($attachmentId);
        $cached = $this->repository->findCacheItem($uid, $hash);
        if ($cached !== null && $this->config->cacheQuotaBytes() > 0) {
            $fetched = $this->nextcloud->fetch($uid, $this->cacheFolder(), (string) $cached['name']);
            if ($fetched['ok'] && $fetched['content'] !== '') {
                return [
                    'name' => self::displayName((string) $cached['name']),
                    'content_type' => (string) ($cached['content_type'] ?? ($fetched['content_type'] ?: 'application/octet-stream')),
                    'content' => $fetched['content'],
                    'size' => strlen($fetched['content']),
                    'cached' => true,
                ];
            }
            $this->repository->deleteCacheItem((int) $cached['id']);
        }

        $attachment = $this->exchange->attachment($impersonate, $attachmentId);
        $this->cache($uid, $hash, $attachment);

        return $attachment + ['cached' => false];
    }

    /**
     * Legt empfangene Inhalte im Nextcloud-Zwischenspeicher des Benutzers ab und
     * haelt das Quota ein (Verdraengung der aeltesten Eintraege). Fehler werden
     * ignoriert, der Zwischenspeicher ist optional.
     *
     * @param array{name:string,content_type:string,content:string,size:int} $attachment
     */
    public function cache(string $uid, string $hash, array $attachment, string $kind = 'attachment'): bool
    {
        $quota = $this->config->cacheQuotaBytes();
        $size = strlen($attachment['content']);
        if ($quota <= 0 || $size === 0 || $size > $quota || $size > NextcloudFilesService::MAX_BYTES || $this->nextcloud->unavailableReason() !== null) {
            return false;
        }
        if ($this->repository->findCacheItem($uid, $hash) !== null) {
            return true;
        }
        $this->evict($uid, $quota - $size);
        $name = substr($hash, 0, 12) . '_' . NextcloudFilesService::segment($attachment['name'], 'anhang.bin');
        $result = $this->nextcloud->upload($uid, $this->cacheFolder(), $name, $attachment['content']);
        if (!$result['ok']) {
            return false;
        }
        $this->repository->addCacheItem($uid, $kind, $hash, $name, $result['path'], $size, $attachment['content_type']);

        return true;
    }

    /**
     * Entfernt aelteste Eintraege, bis die Belegung <= $limit ist.
     */
    public function evict(string $uid, int $limit): int
    {
        $removed = 0;
        $usage = $this->repository->cacheUsage($uid);
        while ($usage > max(0, $limit)) {
            $oldest = $this->repository->oldestCacheItems($uid, 10);
            if ($oldest === []) {
                break;
            }
            foreach ($oldest as $item) {
                $this->nextcloud->delete($uid, $this->cacheFolder(), (string) $item['name']);
                $this->repository->deleteCacheItem((int) $item['id']);
                $usage -= (int) $item['size_bytes'];
                $removed++;
                if ($usage <= $limit) {
                    break;
                }
            }
        }

        return $removed;
    }

    /**
     * Leert den Zwischenspeicher eines Benutzers vollstaendig.
     */
    public function clear(string $uid): int
    {
        return $this->evict($uid, -1);
    }

    /**
     * @return array{used:int,quota:int,items:int,folder:string,percent:int}
     */
    public function usage(string $uid): array
    {
        $used = $this->repository->cacheUsage($uid);
        $quota = $this->config->cacheQuotaBytes();

        return [
            'used' => $used,
            'quota' => $quota,
            'items' => count($this->repository->cacheItems($uid, 10000)),
            'folder' => $this->cacheFolder(),
            'percent' => $quota > 0 ? (int) min(100, round($used * 100 / $quota)) : 0,
        ];
    }

    /**
     * Speichert einen Anhang dauerhaft im Nextcloud-Bereich des Benutzers
     * (Ordner „Orvanta/Anhänge“ bzw. gewaehlter Unterordner).
     *
     * @return array{ok:bool,message:string,path:string,target:string}
     */
    public function saveToNextcloud(string $uid, string $impersonate, string $attachmentId, string $subfolder = ''): array
    {
        $reason = $this->nextcloud->unavailableReason();
        if ($reason !== null) {
            return ['ok' => false, 'message' => $reason, 'path' => '', 'target' => ''];
        }
        $attachment = $this->load($uid, $impersonate, $attachmentId);
        $folder = $this->cacheFolder() . '/Anhänge';
        if ($subfolder !== '') {
            $folder .= '/' . NextcloudFilesService::segment($subfolder, 'Sonstiges');
        }
        $name = NextcloudFilesService::segment($attachment['name'], 'anhang.bin');
        $result = $this->nextcloud->upload($uid, $folder, $name, $attachment['content']);

        return $result + ['target' => $result['ok'] ? $this->nextcloud->folderTarget($folder) : ''];
    }

    /**
     * Konfiguration fuer den Euro-Office-Editor (DocEditor) im Lesemodus.
     *
     * @param array{uid:string,impersonate:string,attachment_id:string,name:string} $claims
     * @param array{display_name?:string,username:string} $ssoUser
     * @return array{api_url:string,config:array<string,mixed>}
     */
    public function viewerConfig(array $claims, array $ssoUser, string $token): array
    {
        $fileUrl = $this->office->appInternalUrl() . 'office/orvanta/anhang/datei?' . http_build_query(['token' => $token]);
        $ext = strtolower(pathinfo($claims['name'], PATHINFO_EXTENSION));
        $config = [
            'type' => 'desktop',
            'documentType' => self::documentType($claims['name']),
            'document' => [
                'fileType' => $ext,
                'key' => substr(sha1($claims['attachment_id'] . '|' . $token), 0, 20),
                'title' => $claims['name'],
                'url' => $fileUrl,
                'permissions' => ['edit' => false, 'download' => true, 'print' => true, 'review' => false, 'comment' => false, 'copy' => true],
            ],
            'editorConfig' => [
                'mode' => 'view',
                'lang' => 'de',
                'user' => ['id' => $claims['uid'], 'name' => (string) ($ssoUser['display_name'] ?? $ssoUser['username'])],
                'customization' => ['autosave' => false, 'compactHeader' => true, 'forcesave' => false, 'help' => false, 'feedback' => false],
            ],
        ];
        $secret = $this->office->jwtSecret();
        if ($secret !== '') {
            $config['token'] = OfficeJwt::encode($config + ['iat' => time(), 'exp' => time() + 3600], $secret);
        }

        return [
            'api_url' => $this->office->euroOfficePublicPath() . 'web-apps/apps/api/documents/api.js',
            'config' => $config,
        ];
    }

    public function cacheFolder(): string
    {
        return $this->config->cacheFolder();
    }

    private function signingSecret(): string
    {
        return $this->secrets->deriveKey(self::AUDIENCE);
    }

    private static function displayName(string $stored): string
    {
        return preg_replace('/^[0-9a-f]{12}_/', '', $stored) ?? $stored;
    }
}
