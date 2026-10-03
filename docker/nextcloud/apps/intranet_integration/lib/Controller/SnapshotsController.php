<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Storage\TieringClient;
use OCA\IntranetIntegration\Storage\TieringException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Vorgaengerversionen aus dem Snapshot-Speicher (Rechtsklick in der
 * Dateien-App, nur Administratoren). Nextcloud liest den vom Agenten
 * veroeffentlichten Index und legt Wiederherstellungsauftraege im
 * gemeinsamen Verzeichnis ab; ausgefuehrt werden sie ausschliesslich vom
 * Agenten (storage-sync), der dabei keinen neuen Snapshot erzeugt.
 */
class SnapshotsController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IRootFolder $rootFolder,
        private Application $application,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Versionen einer Datei (GET /api/snapshots?fileId=…).
     */
    public function show(int $fileId = 0): JSONResponse {
        $context = $this->context($fileId);
        if ($context instanceof JSONResponse) {
            return $context;
        }
        [$tiering, $rel, $node] = $context;
        $snapshots = $tiering->snapshotsFor($rel);
        $response = new JSONResponse([
            'ok' => true,
            'path' => $rel,
            'name' => $node->getName(),
            'current' => ['size' => $node->getSize(), 'mtime' => $node->getMTime()],
            'snapshots' => $snapshots,
        ]);
        $response->cacheFor(0);

        return $response;
    }

    /**
     * Wiederherstellung anstossen (POST /api/snapshots/restore {fileId, uid}).
     */
    public function restore(int $fileId = 0, string $uid = ''): JSONResponse {
        $context = $this->context($fileId);
        if ($context instanceof JSONResponse) {
            return $context;
        }
        [$tiering, $rel, $node] = $context;
        if (!TieringClient::validSnapshotId($uid)) {
            return $this->error('Ungültige Kennung der Dateiversion.', Http::STATUS_BAD_REQUEST);
        }
        $known = array_filter($tiering->snapshotsFor($rel), static fn (array $row): bool => $row['uid'] === $uid);
        if ($known === []) {
            return $this->error('Diese Dateiversion gehört nicht zu der gewählten Datei oder ist nicht mehr vorhanden.', Http::STATUS_NOT_FOUND);
        }
        if (!$tiering->agentAlive()) {
            return $this->error('Der Speicherdienst (storage-sync) ist derzeit nicht erreichbar. Bitte später erneut versuchen.', Http::STATUS_SERVICE_UNAVAILABLE);
        }
        try {
            $tiering->requestSnapshotRestore($uid, $rel, (string) $this->userSession->getUser()?->getUID());
        } catch (TieringException $exception) {
            return $this->error($exception->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $result = $tiering->waitForSnapshotRestore($uid);
        if ($result['state'] === 'done') {
            $this->rescan($node);
        }
        $response = new JSONResponse([
            'ok' => $result['state'] !== 'failed',
            'state' => $result['state'],
            'message' => $result['state'] === 'failed'
                ? ($result['message'] !== '' ? $result['message'] : 'Die Wiederherstellung ist fehlgeschlagen.')
                : ($result['state'] === 'pending' ? 'Die Wiederherstellung läuft noch im Hintergrund. Bitte die Ansicht in Kürze neu laden.' : 'Die Datei wurde wiederhergestellt.'),
        ], $result['state'] === 'failed' ? Http::STATUS_CONFLICT : Http::STATUS_OK);
        $response->cacheFor(0);

        return $response;
    }

    /**
     * Prueft Anmeldung, Adminrecht, Tiering und loest die Datei auf.
     *
     * @return JSONResponse|array{0:TieringClient,1:string,2:File}
     */
    private function context(int $fileId): JSONResponse|array {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error('Nicht angemeldet.', Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->groupManager->isAdmin($user->getUID())) {
            return $this->error('Nur Administratoren dürfen Vorgängerversionen einsehen.', Http::STATUS_FORBIDDEN);
        }
        $tiering = $this->application->tieringClient();
        if ($tiering === null || !$tiering->enabled()) {
            return $this->error('Das Speicher-Tiering ist nicht eingerichtet.', Http::STATUS_NOT_FOUND);
        }
        if ($fileId <= 0) {
            return $this->error('Keine Datei angegeben.', Http::STATUS_BAD_REQUEST);
        }
        $node = $this->rootFolder->getUserFolder($user->getUID())->getFirstNodeById($fileId);
        if (!$node instanceof File) {
            return $this->error('Die Datei wurde nicht gefunden.', Http::STATUS_NOT_FOUND);
        }
        if (!$node->getStorage()->instanceOfStorage(IHomeStorage::class)) {
            return $this->error('Für Dateien auf externen Speichern gibt es keine Vorgängerversionen.', Http::STATUS_NOT_FOUND);
        }
        $owner = $node->getOwner()?->getUID() ?? '';
        $internal = ltrim($node->getInternalPath(), '/');
        if ($owner === '' || !str_starts_with($internal, 'files/')) {
            return $this->error('Für diese Datei gibt es keine Vorgängerversionen.', Http::STATUS_NOT_FOUND);
        }

        return [$tiering, $owner . '/' . $internal, $node];
    }

    private function rescan(Node $node): void {
        try {
            $storage = $node->getStorage();
            $storage->getScanner()->scanFile($node->getInternalPath());
            $storage->getCache()->correctFolderSize($node->getInternalPath());
        } catch (\Throwable) {
            // Nextcloud liest die Datei spaetestens beim naechsten Zugriff neu ein.
        }
    }

    private function error(string $message, int $status): JSONResponse {
        $response = new JSONResponse(['ok' => false, 'message' => $message], $status);
        $response->cacheFor(0);

        return $response;
    }
}
