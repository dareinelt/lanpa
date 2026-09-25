<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\AppInfo;

use OCA\IntranetIntegration\Listener\FooterListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class Application extends App implements IBootstrap {
    public const APP_ID = 'intranet_integration';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // Alle ueber das AppFramework gerenderten Seiten (Dateien, Editor,
        // Freigaben, Einstellungen ...) sowie die Anmeldeseite.
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, FooterListener::class);
        $context->registerEventListener(BeforeLoginTemplateRenderedEvent::class, FooterListener::class);
    }

    public function boot(IBootContext $context): void {
        $context->injectFn($this->redirectLoginToIntranet(...));
    }

    /**
     * Anmeldeseite -> Intranet-Einstieg: Das Intranet kennt den Benutzer
     * (Windows-Anmeldung) und reicht ihn per Einmal-Token an den
     * SSO-Endpunkt weiter. So ist beim Wechsel nach Nextcloud keine erneute
     * Kennworteingabe noetig. "?direct=1" zeigt weiterhin das Formular.
     */
    public function redirectLoginToIntranet(IRequest $request, IUserSession $userSession, IConfig $config, IURLGenerator $urlGenerator): void {
        if (PHP_SAPI === 'cli' || $request->getMethod() !== 'GET') {
            return;
        }

        $settings = $config->getSystemValue(self::APP_ID, []);
        $settings = is_array($settings) ? $settings : [];
        if (!filter_var($settings['sso_login_redirect'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        try {
            if ($request->getPathInfo() !== '/login' || $userSession->isLoggedIn()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }
        if ((string) $request->getParam('direct', '') === '1') {
            return;
        }

        $entry = is_string($settings['intranet_entry'] ?? null) ? $settings['intranet_entry'] : '/office-starten';
        if (!str_starts_with($entry, '/') || str_starts_with($entry, '//')) {
            $entry = '/office-starten';
        }
        $target = (string) $request->getParam('redirect_url', '');
        $location = $entry . ($target !== '' ? '?' . http_build_query(['ziel' => $target]) : '?ziel=' . rawurlencode(rtrim($urlGenerator->getWebroot(), '/') . '/'));

        header('Location: ' . $location, true, 302);
        header('Cache-Control: no-store');
        exit();
    }
}
