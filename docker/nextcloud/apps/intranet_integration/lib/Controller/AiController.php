<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\AiConfigService;
use OCA\IntranetIntegration\Service\TokenVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Nimmt die KI-Einstellungen aus dem Intranet-Adminbereich entgegen.
 *
 * Nur mit gueltigem, kurzlebigem JWT (HS256, gemeinsames Euro-Office-Secret,
 * eigene Audience), das ueber den Claim "body" (SHA-256) an genau diesen
 * Anfrageinhalt gebunden ist.
 */
class AiController extends Controller {
    private const MAX_BODY = 16384;

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private TokenVerifier $verifier,
        private AiConfigService $ai,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function update(): JSONResponse {
        $system = $this->config->getSystemValue('eurooffice', []);
        $secret = (string) (is_array($system) ? ($system['jwt_secret'] ?? '') : '');

        $auth = (string) $this->request->getHeader('Authorization');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $claims = $this->verifier->claims($token, $secret, TokenVerifier::AI_AUDIENCE);
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($claims === null || strlen($body) > self::MAX_BODY
            || !hash_equals((string) ($claims['body'] ?? ''), hash('sha256', $body))) {
            return new JSONResponse(['ok' => false, 'error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new JSONResponse(['ok' => false, 'message' => 'Ungueltige Anfrage.'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->ai->apply($payload);

        return new JSONResponse($result + ['fingerprint' => $this->ai->status()['fingerprint']]);
    }
}
