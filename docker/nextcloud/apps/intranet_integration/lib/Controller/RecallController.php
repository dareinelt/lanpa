<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Storage\TieringClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Fortschritt der Rueckholungen aus dem Cold-Tier (SMB-/S3-Tier) fuer den
 * angemeldeten Benutzer (Fortschrittsbalken in js/recall.js) und Hinweis auf
 * eine Einschraenkung wegen eines Sicherheitsvorfalls.
 */
class RecallController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private Application $application,
        private IRootFolder $rootFolder,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function show(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['ok' => false], Http::STATUS_UNAUTHORIZED);
        }
        $tiering = $this->application->tieringClient();
        if ($tiering === null || !$tiering->enabled()) {
            return new JSONResponse(['ok' => true, 'enabled' => false, 'recalls' => []]);
        }
        $this->rescan($tiering);

        $response = new JSONResponse([
            'ok' => true,
            'enabled' => true,
            'available' => $tiering->agentAlive(),
            'recalls' => $tiering->recallsFor($user->getUID()),
            // Sicherheitsvorfall: nur lesender Zugriff (Hinweis im Browser)
            'restricted' => $tiering->isRestricted($user->getUID()),
            'message' => $tiering->isRestricted($user->getUID()) ? $tiering->restrictionMessage() : '',
        ]);
        $response->cacheFor(0);

        return $response;
    }

    /**
     * Nach einer Wiederherstellung aus dem Intranet bittet storage-sync um
     * ein Neueinlesen der Datei (Groesse, Aenderungszeit, Etag im Dateicache).
     * Erledigt wird das hier nebenbei, weil dieser Endpunkt ohnehin regelmaessig
     * aufgerufen wird.
     */
    private function rescan(TieringClient $tiering): void {
        foreach ($tiering->takeRescans() as $rel) {
            $parts = explode('/', $rel, 2);
            if (count($parts) !== 2) {
                continue;
            }
            try {
                $home = $this->rootFolder->getUserFolder($parts[0]);
                $storage = $home->getStorage();
                $storage->getScanner()->scanFile($parts[1]);
                $storage->getCache()->correctFolderSize($parts[1]);
            } catch (\Throwable) {
                // Unbekannter Benutzer oder Pfad: beim naechsten Zugriff liest Nextcloud ohnehin neu ein.
            }
        }
    }
}
