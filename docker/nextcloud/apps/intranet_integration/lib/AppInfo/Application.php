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
    }
}
