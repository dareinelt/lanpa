<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\FileTarget;
use OCA\IntranetIntegration\Service\TokenVerifier;
use OCA\IntranetIntegration\Service\UserResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotEnoughSpaceException;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Legt eine Datei aus dem Intranet in den Dateien eines Benutzers ab
 * (z. B. Notfallplan-Exporte, POST /api/files).
 *
 * Nur mit gueltigem, kurzlebigem JWT (HS256, gemeinsames Euro-Office-Secret,
 * eigene Audience). Das Token bindet Benutzer (sub), Zielordner (folder),
 * Dateiname (name) und Inhalt (body = SHA-256 des Anfragekoerpers). Es wird
 * nur in vorhandenen, aktiven Konten abgelegt; fehlende Ordner werden
 * angelegt, eine gleichnamige Datei wird ersetzt.
 *
 * GET /api/files liefert eine zuvor abgelegte Datei zurueck, DELETE /api/files
 * entfernt sie (Zwischenspeicher der Mail-App Orvanta mit eigenem Quota). Beide
 * verwenden ein Token mit action = fetch bzw. delete statt body-Hash.
 */
class FilesController extends Controller {
    private const MAX_BODY = 16777216;
    private const UID_PATTERN = '/^[a-zA-Z0-9._-]{1,64}(@[a-z0-9_]{1,32})?$/';

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private TokenVerifier $verifier,
        private UserResolver $users,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function store(): JSONResponse {
        $system = $this->config->getSystemValue('eurooffice', []);
        $secret = (string) (is_array($system) ? ($system['jwt_secret'] ?? '') : '');

        $auth = (string) $this->request->getHeader('Authorization');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $claims = $this->verifier->claims($token, $secret, TokenVerifier::FILES_AUDIENCE);
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($claims === null || $body === '' || strlen($body) > self::MAX_BODY
            || !hash_equals((string) ($claims['body'] ?? ''), hash('sha256', $body))) {
            return new JSONResponse(['ok' => false, 'error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $uid = (string) ($claims['sub'] ?? '');
        $name = (string) ($claims['name'] ?? '');
        $segments = FileTarget::folder((string) ($claims['folder'] ?? ''));
        if (preg_match(self::UID_PATTERN, $uid) !== 1 || $segments === null || !FileTarget::isSafeSegment($name)) {
            return new JSONResponse(['ok' => false, 'message' => 'Ungueltiger Benutzer, Ordner oder Dateiname.'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $user = $this->users->find($uid);
        } catch (\Throwable $e) {
            $this->logger->error('Intranet-Dateiablage: Kontosuche fuer {uid} fehlgeschlagen.', ['app' => Application::APP_ID, 'uid' => $uid, 'exception' => $e]);
            return new JSONResponse(['ok' => false, 'message' => 'Das Nextcloud-Konto konnte nicht ermittelt werden (Verzeichnis nicht erreichbar).'], Http::STATUS_SERVICE_UNAVAILABLE);
        }
        if ($user === null || !$user->isEnabled()) {
            return new JSONResponse(['ok' => false, 'message' => 'Für Sie gibt es noch kein aktives Nextcloud-Konto. Bitte Office einmal über das Intranet öffnen und den Export wiederholen.'], Http::STATUS_NOT_FOUND);
        }

        @set_time_limit(300);
        try {
            $folder = $this->rootFolder->getUserFolder($user->getUID());
            foreach ($segments as $segment) {
                if ($folder->nodeExists($segment)) {
                    $node = $folder->get($segment);
                    if (!$node instanceof Folder) {
                        return new JSONResponse(['ok' => false, 'message' => 'Im Zielpfad gibt es bereits eine Datei „' . $segment . '“.'], Http::STATUS_CONFLICT);
                    }
                    $folder = $node;
                } else {
                    $folder = $folder->newFolder($segment);
                }
            }
            if ($folder->nodeExists($name)) {
                $file = $folder->get($name);
                if (!$file instanceof File) {
                    return new JSONResponse(['ok' => false, 'message' => 'Im Zielordner gibt es bereits einen Ordner „' . $name . '“.'], Http::STATUS_CONFLICT);
                }
                $file->putContent($body);
            } else {
                $folder->newFile($name, $body);
            }
        } catch (NotEnoughSpaceException) {
            return new JSONResponse(['ok' => false, 'message' => 'Nicht genug Speicherplatz in Ihrer Nextcloud.'], Http::STATUS_INSUFFICIENT_STORAGE);
        } catch (\Throwable $e) {
            $this->logger->error('Intranet-Dateiablage fuer {uid} fehlgeschlagen.', ['app' => Application::APP_ID, 'uid' => $user->getUID(), 'exception' => $e]);
            return new JSONResponse(['ok' => false, 'message' => 'Die Datei konnte nicht in Nextcloud gespeichert werden.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('Intranet-Dateiablage: {name} fuer {uid} gespeichert.', ['app' => Application::APP_ID, 'uid' => $user->getUID(), 'name' => $name]);
        return new JSONResponse(['ok' => true, 'message' => 'In Nextcloud gespeichert.', 'size' => strlen($body)]);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function fetch(): DataDownloadResponse|JSONResponse {
        $target = $this->resolveTarget('fetch');
        if ($target instanceof JSONResponse) {
            return $target;
        }
        [$folder, $name] = $target;
        try {
            if (!$folder->nodeExists($name)) {
                return new JSONResponse(['ok' => false, 'message' => 'Die Datei ist nicht mehr im Zwischenspeicher vorhanden.'], Http::STATUS_NOT_FOUND);
            }
            $file = $folder->get($name);
            if (!$file instanceof File) {
                return new JSONResponse(['ok' => false, 'message' => 'Der Zielpfad ist keine Datei.'], Http::STATUS_CONFLICT);
            }
            return new DataDownloadResponse($file->getContent(), $name, $file->getMimeType() ?: 'application/octet-stream');
        } catch (\Throwable $e) {
            $this->logger->error('Intranet-Dateiabruf fuer {name} fehlgeschlagen.', ['app' => Application::APP_ID, 'name' => $name, 'exception' => $e]);
            return new JSONResponse(['ok' => false, 'message' => 'Die Datei konnte nicht gelesen werden.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function remove(): JSONResponse {
        $target = $this->resolveTarget('delete');
        if ($target instanceof JSONResponse) {
            return $target;
        }
        [$folder, $name] = $target;
        try {
            if ($folder->nodeExists($name)) {
                $node = $folder->get($name);
                if ($node instanceof File) {
                    $node->delete();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Intranet-Dateiloeschung fuer {name} fehlgeschlagen.', ['app' => Application::APP_ID, 'name' => $name, 'exception' => $e]);
            return new JSONResponse(['ok' => false, 'message' => 'Die Datei konnte nicht gelöscht werden.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['ok' => true, 'message' => 'Gelöscht.']);
    }

    /**
     * Prueft Token (action-gebunden) und loest den Zielordner auf, ohne ihn anzulegen.
     *
     * @return array{0:Folder,1:string}|JSONResponse
     */
    private function resolveTarget(string $action): array|JSONResponse {
        $system = $this->config->getSystemValue('eurooffice', []);
        $secret = (string) (is_array($system) ? ($system['jwt_secret'] ?? '') : '');
        $auth = (string) $this->request->getHeader('Authorization');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $claims = $this->verifier->claims($token, $secret, TokenVerifier::FILES_AUDIENCE);
        if ($claims === null || (string) ($claims['action'] ?? '') !== $action) {
            return new JSONResponse(['ok' => false, 'error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }
        $uid = (string) ($claims['sub'] ?? '');
        $name = (string) ($claims['name'] ?? '');
        $segments = FileTarget::folder((string) ($claims['folder'] ?? ''));
        if (preg_match(self::UID_PATTERN, $uid) !== 1 || $segments === null || !FileTarget::isSafeSegment($name)) {
            return new JSONResponse(['ok' => false, 'message' => 'Ungueltiger Benutzer, Ordner oder Dateiname.'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $user = $this->users->find($uid);
        } catch (\Throwable) {
            return new JSONResponse(['ok' => false, 'message' => 'Das Nextcloud-Konto konnte nicht ermittelt werden.'], Http::STATUS_SERVICE_UNAVAILABLE);
        }
        if ($user === null || !$user->isEnabled()) {
            return new JSONResponse(['ok' => false, 'message' => 'Kein aktives Nextcloud-Konto.'], Http::STATUS_NOT_FOUND);
        }
        try {
            $folder = $this->rootFolder->getUserFolder($user->getUID());
            foreach ($segments as $segment) {
                if (!$folder->nodeExists($segment)) {
                    return new JSONResponse(['ok' => false, 'message' => 'Der Ordner ist nicht vorhanden.'], Http::STATUS_NOT_FOUND);
                }
                $node = $folder->get($segment);
                if (!$node instanceof Folder) {
                    return new JSONResponse(['ok' => false, 'message' => 'Der Zielpfad ist kein Ordner.'], Http::STATUS_CONFLICT);
                }
                $folder = $node;
            }
        } catch (\Throwable) {
            return new JSONResponse(['ok' => false, 'message' => 'Der Ordner konnte nicht geöffnet werden.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return [$folder, $name];
    }
}
