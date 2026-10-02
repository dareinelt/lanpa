<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\NetworkDriveService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Einstellung "Netzlaufwerke anzeigen" im Dateien-App (je Benutzer) und
 * einmalige Hinterlegung des Windows-Kennworts fuer die Netzlaufwerke.
 */
class NetworkDrivesController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private NetworkDriveService $drives,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function show(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['ok' => false], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(['ok' => true] + $this->drives->userState($user));
    }

    #[NoAdminRequired]
    public function update(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['ok' => false], Http::STATUS_UNAUTHORIZED);
        }

        $password = $this->request->getParam('password');
        if (is_string($password) && $password !== '') {
            if (strlen($password) > 512) {
                return new JSONResponse(['ok' => false, 'message' => 'Kennwort zu lang.'], Http::STATUS_BAD_REQUEST);
            }
            $login = $this->request->getParam('login');
            $login = is_string($login) ? trim($login) : '';
            if (strlen($login) > 128 || preg_match('/[\x00-\x1F\x7F]/', $login) === 1) {
                return new JSONResponse(['ok' => false, 'message' => 'Ungueltiger Benutzername.'], Http::STATUS_BAD_REQUEST);
            }
            $this->drives->storeCredentials($user, $login, $password);
        }

        $result = ['ok' => true, 'message' => ''];
        $enabled = $this->request->getParam('enabled');
        if ($enabled !== null) {
            $result = $this->drives->setOptIn($user, filter_var($enabled, FILTER_VALIDATE_BOOLEAN));
        }

        return new JSONResponse($result + $this->drives->userState($user));
    }
}
