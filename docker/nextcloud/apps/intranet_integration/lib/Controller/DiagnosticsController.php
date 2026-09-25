<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Controller;

use OCA\IntranetIntegration\AppInfo\Application;
use OCA\IntranetIntegration\Service\TokenVerifier;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ServerVersion;
use Psr\Log\LoggerInterface;

/**
 * Diagnose fuer den Intranet-Adminbereich.
 *
 * Nur mit gueltigem, kurzlebigem JWT (HS256, gemeinsames Euro-Office-Secret)
 * aufrufbar. Liefert keine Geheimnisse, sondern nur Zustaende.
 */
class DiagnosticsController extends Controller {
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IAppManager $appManager,
        private ServerVersion $serverVersion,
        private TokenVerifier $verifier,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[PublicPage]
    #[NoCSRFRequired]
    public function show(string $check = '0'): JSONResponse {
        $system = $this->config->getSystemValue('eurooffice', []);
        $system = is_array($system) ? $system : [];
        $secret = (string) ($system['jwt_secret'] ?? '');

        $auth = (string) $this->request->getHeader('Authorization');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        if (!$this->verifier->verify($token, $secret)) {
            return new JSONResponse(['ok' => false, 'error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $installed = $this->appManager->isInstalled('eurooffice');
        $enabled = $installed && $this->appManager->isEnabledForAnyone('eurooffice');
        $appValue = fn (string $key, string $default = ''): string
            => (string) $this->config->getAppValue('eurooffice', $key, $default);

        $result = [
            'ok' => true,
            'nextcloud' => [
                'version' => $this->serverVersion->getVersionString(),
                'maintenance' => (bool) $this->config->getSystemValue('maintenance', false),
            ],
            'connector' => [
                'installed' => $installed,
                'enabled' => $enabled,
                'version' => $installed ? (string) $this->appManager->getAppVersion('eurooffice') : '',
                'document_server_url' => (string) ($appValue('DocumentServerUrl') ?: ($system['DocumentServerUrl'] ?? '')),
                'document_server_internal_url' => (string) ($appValue('DocumentServerInternalUrl') ?: ($system['DocumentServerInternalUrl'] ?? '')),
                'storage_url' => (string) ($appValue('StorageUrl') ?: ($system['StorageUrl'] ?? '')),
                'jwt_configured' => $secret !== '' || $appValue('jwt_secret') !== '',
                'app_config_overrides' => array_values(array_filter(
                    ['DocumentServerUrl', 'DocumentServerInternalUrl', 'StorageUrl', 'jwt_secret'],
                    fn (string $key): bool => $appValue($key) !== ''
                )),
                'same_tab' => $appValue('sameTab', 'true') === 'true',
                'groups' => json_decode($appValue('groups', '[]'), true) ?: [],
            ],
            'apps' => [
                'user_ldap' => $this->appManager->isEnabledForAnyone('user_ldap'),
                'user_saml' => $this->appManager->isEnabledForAnyone('user_saml'),
                'intranet_integration' => true,
            ],
        ];

        if ($check === '1' && $enabled) {
            $result['check'] = $this->runConnectorCheck();
        }

        return new JSONResponse($result);
    }

    /**
     * Vollstaendige Pruefung des Connectors: Nextcloud -> DocumentServer
     * (Healthcheck, signierter Versionsbefehl) und DocumentServer -> Nextcloud
     * (Testkonvertierung laedt eine Datei ueber die interne StorageUrl).
     *
     * @return array{ok: bool, error: string, version: string}
     */
    private function runConnectorCheck(): array {
        try {
            /** @var object $service */
            $service = \OCP\Server::get('OCA\\Eurooffice\\DocumentService');
            [$error, $version] = $service->checkDocServiceUrl();
            return ['ok' => $error === '', 'error' => (string) $error, 'version' => (string) ($version ?? '')];
        } catch (\Throwable $e) {
            $this->logger->warning('Euro-Office-Pruefung fehlgeschlagen', ['exception' => $e]);
            return ['ok' => false, 'error' => 'Pruefung nicht ausfuehrbar: ' . get_class($e), 'version' => ''];
        }
    }
}
