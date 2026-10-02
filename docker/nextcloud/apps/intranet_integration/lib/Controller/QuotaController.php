<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\QuotaService;
use OCA\IntranetIntegration\Service\TokenVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Nimmt die Speicherplatz-Kontingente aus dem Intranet entgegen.
 *
 * Nur mit gueltigem, kurzlebigem JWT (HS256, gemeinsames Euro-Office-Secret,
 * eigene Audience), das ueber den Claim "body" (SHA-256) an genau diesen
 * Anfrageinhalt gebunden ist.
 */
class QuotaController extends Controller {
    private const MAX_BODY = 4194304;

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private TokenVerifier $verifier,
        private QuotaService $quota,
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
        $claims = $this->verifier->claims($token, $secret, TokenVerifier::QUOTA_AUDIENCE);
        $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if ($claims === null || strlen($body) > self::MAX_BODY
            || !hash_equals((string) ($claims['body'] ?? ''), hash('sha256', $body))) {
            return new JSONResponse(['ok' => false, 'error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new JSONResponse(['ok' => false, 'message' => 'Ungueltige Anfrage.'], Http::STATUS_BAD_REQUEST);
        }

        // Viele Benutzer: Laufzeitgrenze anheben (nur fuer diese Anfrage).
        @set_time_limit(300);

        return new JSONResponse($this->quota->apply($payload) + ['status' => $this->quota->status()]);
    }
}
