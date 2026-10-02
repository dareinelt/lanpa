<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Fortschritt der Rueckholungen aus dem Cold-Tier (SMB-Tier) fuer den
 * angemeldeten Benutzer (Fortschrittsbalken in js/recall.js).
 */
class RecallController extends Controller {
    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private Application $application,
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

        $response = new JSONResponse([
            'ok' => true,
            'enabled' => true,
            'available' => $tiering->agentAlive(),
            'recalls' => $tiering->recallsFor($user->getUID()),
        ]);
        $response->cacheFor(0);

        return $response;
    }
}
