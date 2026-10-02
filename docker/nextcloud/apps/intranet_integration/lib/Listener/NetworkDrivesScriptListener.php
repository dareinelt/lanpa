<?php

declare(strict_types=1);

namespace OCA\IntranetIntegration\Listener;

use OCA\IntranetIntegration\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use OCP\Util;

/**
 * Laedt im Dateien-App die Einstellung "Netzlaufwerke anzeigen"
 * (js/network-drives.js, Dateien > Einstellungen).
 *
 * @template-implements IEventListener<Event>
 */
class NetworkDrivesScriptListener implements IEventListener {
    public function __construct(
        private IUserSession $userSession,
    ) {
    }

    public function handle(Event $event): void {
        if (!$this->userSession->isLoggedIn()) {
            return;
        }
        Util::addScript(Application::APP_ID, 'network-drives');
    }
}
